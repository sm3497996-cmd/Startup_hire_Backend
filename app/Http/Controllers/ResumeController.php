<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Validator;

use App\Models\CandidatePersonalDetail;
use App\Models\CandidateEducation;
use App\Models\CandidateSkill;
use App\Models\CandidateCourseCertification;
use App\Models\CandidateWorkExperience;
use App\Models\CandidateProject;
use App\Models\CandidateAchievement;
use App\Models\CandidateAttachment;

class ResumeController extends Controller
{
    /**
     * Uploaded files ko resume ke card (course/experience/project/achievement) se jodta hai.
     */
    private function linkAttachments(int $candidateId, string $section, int $itemId, $attachments, array &$keepIds): void
    {
        if (!is_array($attachments)) {
            return;
        }

        $ids = collect($attachments)->pluck('id')->filter()
            ->map(fn ($i) => (int) $i)->unique()->take(5)->values()->all();

        if (!$ids) {
            return;
        }

        CandidateAttachment::where('candidate_id', $candidateId)
            ->whereIn('id', $ids)
            ->update(['section' => $section, 'item_id' => $itemId]);

        $keepIds = array_merge($keepIds, $ids);
    }

    /**
     * DB mein path (profile_photos/x.jpg) save hota hai, frontend ko poora URL milta hai.
     */
    private function photoUrl(?string $path): ?string
    {
        if (!$path) {
            return null;
        }

        if (preg_match('/^https?:\/\//i', $path)) {
            return $path;
        }

        return url('storage/' . ltrim($path, '/'));
    }

    /**
     * Kis candidate ki photo badalni hai: candidate = khud, superadmin = candidate_id se.
     */
    private function photoCandidateId(Request $request): ?int
    {
        $legacy = $request->attributes->get('legacy_user');

        if (strtolower($legacy['role'] ?? '') === 'candidate') {
            return (int) $legacy['id'];
        }

        $id = $request->input('candidate_id');
        return $id ? (int) $id : null;
    }

       public function uploadPhoto(Request $request)
    {
        $candidateId = $this->photoCandidateId($request);

        if (!$candidateId) {
            return response()->json(['success' => false, 'message' => 'candidate_id is required.'], 400);
        }

        try {
            if ($request->file('photo') !== null) {
                // Normal file upload (FormData, field name: "photo")
                $validator = Validator::make($request->all(), [
                    'photo' => 'required|image|mimes:jpg,jpeg,png,webp|max:2048',
                ], [
                    'photo.image' => 'Only JPG, PNG or WEBP photos are allowed.',
                    'photo.mimes' => 'Only JPG, PNG or WEBP photos are allowed.',
                    'photo.max' => 'Photo must be 2 MB or smaller.',
                    'photo.uploaded' => 'Photo could not be uploaded. It may be larger than the server limit.',
                ]);

                if ($validator->fails()) {
                    return response()->json([
                        'success' => false,
                        'message' => $validator->errors()->first('photo'),
                    ], 422);
                }

                $file = $request->file('photo');
                $ext = $file->extension() ?: 'jpg';
                $newPath = $file->storeAs('profile_photos', $candidateId . '_' . time() . '.' . $ext, 'public');

                if (!$newPath) {
                    throw new \RuntimeException('Could not store the file.');
                }
            } else {
                // Old base64 data-URL upload (kept so nothing that already works breaks)
                $raw = $request->input('photo');

                if (!is_string($raw) || !preg_match('/^data:image\/(jpeg|png|webp);base64,/i', $raw)) {
                    return response()->json(['success' => false, 'message' => 'Photo sahi format mein nahi mili.'], 422);
                }

                $bin = base64_decode(substr($raw, strpos($raw, ',') + 1), true);

                if ($bin === false || strlen($bin) > 2 * 1024 * 1024) {
                    return response()->json(['success' => false, 'message' => 'Photo 2 MB se chhoti honi chahiye.'], 422);
                }

                $info = @getimagesizefromstring($bin);
                $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

                if (!$info || !isset($types[$info[2]])) {
                    return response()->json(['success' => false, 'message' => 'Sirf JPG, PNG ya WEBP photo allowed hai.'], 422);
                }

                $newPath = 'profile_photos/' . $candidateId . '_' . time() . '.' . $types[$info[2]];
                Storage::disk('public')->put($newPath, $bin);
            }

            $detail = CandidatePersonalDetail::firstOrNew(['candidate_id' => $candidateId]);
            $oldPath = $detail->profile_photo;

            $detail->candidate_id = $candidateId;
            $detail->profile_photo = $newPath;
            $detail->save();

            if ($oldPath && !preg_match('/^https?:\/\//i', $oldPath)) {
                Storage::disk('public')->delete($oldPath);
            }

            $url = $this->photoUrl($newPath);

            return response()->json([
                'success' => true,
                'message' => 'Photo updated.',
                'photo' => $url,
                'profile_photo' => $newPath,
                'profile_photo_url' => $url,
            ]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Photo save nahi ho payi.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function deletePhoto(Request $request)
    {
        $candidateId = $this->photoCandidateId($request);

        if (!$candidateId) {
            return response()->json(['success' => false, 'message' => 'candidate_id is required.'], 400);
        }

        try {
            $detail = CandidatePersonalDetail::where('candidate_id', $candidateId)->first();

            if ($detail && $detail->profile_photo) {
                $old = $detail->profile_photo;
                $detail->profile_photo = null;
                $detail->save();

                if (!preg_match('/^https?:\/\//i', $old)) {
                    Storage::disk('public')->delete($old);
                }
            }

            return response()->json(['success' => true, 'message' => 'Photo removed.']);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => 'Photo remove nahi ho payi.',
                'error' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        ini_set('display_errors', '0');
        // Read raw JSON body
        $rawBody = $request->getContent();

        // Remove UTF-8 BOM if PowerShell added it
        $rawBody = preg_replace('/^\xEF\xBB\xBF/', '', $rawBody);

        $data = json_decode($rawBody, true);

        if (!is_array($data)) {
            return response()->json([
                'success' => false,
                'message' => 'Invalid JSON data.'
            ], 400);
        }

        $legacy = $request->attributes->get('legacy_user');
        $candidateId = $data['candidate_id'] ?? null;

        // Candidate sirf apna resume badal sakta hai
        if (strtolower($legacy['role'] ?? '') === 'candidate') {
            $candidateId = $legacy['id'];
        }

        if (!$candidateId) {
            return response()->json([
                'success' => false,
                'message' => 'candidate_id is required.'
            ], 400);
        }

        $candidateId = (int) $candidateId;
        // ---- Sirf profile photo badalni ho to baaki resume ko mat chhedo ----
if (!empty($data['remove_photo'])) {
    $detail = CandidatePersonalDetail::where('candidate_id', $candidateId)->first();
    if ($detail && $detail->profile_photo) {
        $old = $detail->profile_photo;
        $detail->profile_photo = null;
        $detail->save();
        if (!preg_match('/^https?:\/\//i', $old)) {
            Storage::disk('public')->delete($old);
        }
    }
    return response()->json(['success' => true, 'message' => 'Photo removed.']);
}

if (isset($data['photo_data'])) {
    $raw = $data['photo_data'];

    if (!is_string($raw) || !preg_match('/^data:image\/(jpeg|png|webp);base64,/i', $raw)) {
        return response()->json(['success' => false, 'message' => 'Photo sahi format mein nahi mili.'], 422);
    }

    $bin = base64_decode(substr($raw, strpos($raw, ',') + 1), true);

    if ($bin === false || strlen($bin) > 2 * 1024 * 1024) {
        return response()->json(['success' => false, 'message' => 'Photo 2 MB se chhoti honi chahiye.'], 422);
    }

    $info = @getimagesizefromstring($bin);
    $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_WEBP => 'webp'];

    if (!$info || !isset($types[$info[2]])) {
        return response()->json(['success' => false, 'message' => 'Sirf JPG, PNG ya WEBP photo allowed hai.'], 422);
    }

    try {
        $newPath = 'profile_photos/' . $candidateId . '_' . time() . '.' . $types[$info[2]];
        Storage::disk('public')->put($newPath, $bin);

        $detail = CandidatePersonalDetail::firstOrNew(['candidate_id' => $candidateId]);
        $oldPath = $detail->profile_photo;

        $detail->candidate_id = $candidateId;
        $detail->profile_photo = $newPath;
        $detail->save();

        if ($oldPath && !preg_match('/^https?:\/\//i', $oldPath)) {
            Storage::disk('public')->delete($oldPath);
        }

        return response()->json([
            'success' => true,
            'message' => 'Photo updated.',
            'photo' => $this->photoUrl($newPath),
        ]);
    } catch (\Throwable $e) {
        return response()->json([
            'success' => false,
            'message' => 'Photo save nahi ho payi.',
            'error' => $e->getMessage(),
        ], 500);
    }
}
        $keepIds = [];
        $orphanPaths = [];

        try {

            DB::beginTransaction();

            // 1. Personal Details
            if (isset($data['personal']) && is_array($data['personal'])) {

                $personal = $data['personal'];

                $personal['candidate_id'] = $candidateId;

                // Photo sirf /resume/photo API se badalti hai, Save Resume se nahi
                unset($personal['photo'], $personal['profile_photo']);

                if (isset($personal['headline'])) {
                    $personal['resume_headline'] = $personal['headline'];
                    unset($personal['headline']);
                }

                if (isset($personal['summary'])) {
                    $personal['profile_summary'] = $personal['summary'];
                    unset($personal['summary']);
                }

                CandidatePersonalDetail::updateOrCreate(
                    ['candidate_id' => $candidateId],
                    $personal
                );
            }

            // 2. Education
            CandidateEducation::where('candidate_id', $candidateId)->delete();

            if (isset($data['education']) && is_array($data['education'])) {

                foreach ($data['education'] as $education) {

                    if (empty(trim($education['degree_name'] ?? ''))) {
                        continue;
                    }

                    CandidateEducation::create([
                        'candidate_id' => $candidateId,
                        'education_level' => $education['education_level'] ?? null,
                        'degree_name' => $education['degree_name'] ?? null,
                        'specialization' => $education['specialization'] ?? null,
                        'institution_name' => $education['institution_name'] ?? null,
                        'board_university' => $education['board_university'] ?? null,
                        'start_year' => $education['start_year'] ?? null,
                        'end_year' => $education['end_year'] ?? null,
                        'percentage_cgpa' => $education['percentage_cgpa'] ?? null,
                        'is_pursuing' => !empty($education['is_pursuing']) ? 1 : 0,
                        'status' => 'active',
                    ]);
                }
            }

            // 3. Skills
            CandidateSkill::where('candidate_id', $candidateId)->delete();

            if (isset($data['skills']) && is_array($data['skills'])) {

                foreach ($data['skills'] as $skill) {

                    if (empty(trim($skill['skill_name'] ?? ''))) {
                        continue;
                    }

                    CandidateSkill::create([
                        'candidate_id' => $candidateId,
                        'skill_name' => $skill['skill_name'] ?? null,
                        'proficiency_level' => $skill['proficiency_level'] ?? null,
                        'experience_years' => $skill['experience_years'] ?? null,
                        'status' => 'active',
                    ]);
                }
            }

            // 4. Courses / Certifications
            CandidateCourseCertification::where('candidate_id', $candidateId)->delete();

            if (isset($data['courses']) && is_array($data['courses'])) {

                $convertDate = function ($date) {
                    if (!$date || $date === 'Lifetime') {
                        return null;
                    }

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        return $date;
                    }

                    if (preg_match('/^\d{2}-\d{2}-\d{4}$/', $date)) {
                        [$day, $month, $year] = explode('-', $date);
                        return "$year-$month-$day";
                    }

                    return null;
                };

                foreach ($data['courses'] as $course) {

                    if (empty(trim($course['title'] ?? ''))) {
                        continue;
                    }

                    $row = CandidateCourseCertification::create([
                        'candidate_id' => $candidateId,
                        'type' => $course['type'] ?? 'course',
                        'title' => $course['title'] ?? null,
                        'issued_by' => $course['issued_by'] ?? $course['institute'] ?? null,
                        'issue_date' => $convertDate(
                            $course['issue_date'] ?? $course['startDate'] ?? null
                        ),
                        'expiry_date' => $convertDate(
                            $course['expiry_date'] ?? $course['endDate'] ?? $course['validTill'] ?? null
                        ),
                        'credential_id' => $course['credential_id'] ?? $course['certNumber'] ?? null,
                        'certificate_url' => $course['certificate_url'] ?? $course['link'] ?? null,
                        'details' => $course['details'] ?? null,
                        'status' => 'active',
                    ]);

                    $this->linkAttachments($candidateId, 'course', $row->id, $course['attachments'] ?? [], $keepIds);
                }
            }

            // 5. Work Experience
            CandidateWorkExperience::where('candidate_id', $candidateId)->delete();

            if (isset($data['experience']) && is_array($data['experience'])) {

                $convertExpDate = function ($date) {
                    if (!$date || strtolower(trim($date)) === 'present') {
                        return null;
                    }

                    if (preg_match('/^(\d{2})-(\d{4})$/', $date, $m)) {
                        return "{$m[2]}-{$m[1]}-01";
                    }

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        return $date;
                    }

                    return null;
                };

                foreach ($data['experience'] as $experience) {

                    if (empty(trim($experience['company'] ?? ''))) {
                        continue;
                    }

                    $startDate = $convertExpDate($experience['startDate'] ?? null);
                    $endDateRaw = $experience['endDate'] ?? null;
                    $isCurrentlyWorking = (!$endDateRaw || strtolower(trim($endDateRaw)) === 'present') ? 1 : 0;
                    $endDate = $isCurrentlyWorking ? null : $convertExpDate($endDateRaw);

                    $row = CandidateWorkExperience::create([
                        'candidate_id' => $candidateId,
                        'company_name' => $experience['company'] ?? null,
                        'designation' => $experience['role'] ?? null,
                        'employment_type' => $experience['type'] ?? null,
                        'location' => $experience['location'] ?? null,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'currently_working' => $isCurrentlyWorking,
                        'job_description' => $experience['description'] ?? null,
                        'reference_name' => $experience['refName'] ?? null,
                        'reference_designation' => $experience['refDesignation'] ?? null,
                        'reference_contact' => $experience['refContact'] ?? null,
                        'reference_email' => $experience['refEmail'] ?? null,
                        'status' => 'active',
                    ]);

                    $this->linkAttachments($candidateId, 'experience', $row->id, $experience['attachments'] ?? [], $keepIds);
                }
            }

            // 6. Projects
            CandidateProject::where('candidate_id', $candidateId)->delete();

            if (isset($data['projects']) && is_array($data['projects'])) {

                $convertProjectDate = function ($date) {
                    if (!$date || strtolower(trim($date)) === 'present') {
                        return null;
                    }

                    if (preg_match('/^(\d{2})-(\d{4})$/', $date, $m)) {
                        return "{$m[2]}-{$m[1]}-01";
                    }

                    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                        return $date;
                    }

                    return null;
                };

                foreach ($data['projects'] as $project) {

                    if (empty(trim($project['title'] ?? ''))) {
                        continue;
                    }

                    // Sirf http/https link allow karo, baaki (javascript: etc.) null
                    $link = trim($project['link'] ?? '');
                    if ($link !== '' && !preg_match('/^https?:\/\//i', $link)) {
                        $link = 'https://' . $link;
                    }
                    $link = ($link !== '' && filter_var($link, FILTER_VALIDATE_URL) && preg_match('/^https?:\/\//i', $link))
                        ? mb_substr($link, 0, 500)
                        : null;

                    $row = CandidateProject::create([
                        'candidate_id' => $candidateId,
                        'project_title' => $project['title'] ?? null,
                        'description' => $project['description'] ?? null,
                        'technologies_used' => isset($project['stack']) && is_array($project['stack'])
                            ? implode(', ', $project['stack'])
                            : ($project['stack'] ?? null),
                        'project_url' => $link,
                        'reference_name' => $project['refName'] ?? null,
                        'reference_designation' => $project['refDesignation'] ?? null,
                        'reference_contact' => $project['refContact'] ?? null,
                        'reference_email' => $project['refEmail'] ?? null,
                        'start_date' => $convertProjectDate($project['startDate'] ?? null),
                        'end_date' => $convertProjectDate($project['endDate'] ?? null),
                        'status' => 'active',
                    ]);

                    $this->linkAttachments($candidateId, 'project', $row->id, $project['attachments'] ?? [], $keepIds);
                }
            }

            // 7. Achievements
            CandidateAchievement::where('candidate_id', $candidateId)->delete();

            if (isset($data['achievements']) && is_array($data['achievements'])) {

                foreach ($data['achievements'] as $achievement) {

                    if (empty(trim($achievement['title'] ?? ''))) {
                        continue;
                    }

                    $row = CandidateAchievement::create([
                        'candidate_id' => $candidateId,
                        'title' => $achievement['title'] ?? null,
                        'description' => $achievement['desc'] ?? null,
                        'issuer' => $achievement['issuer'] ?? null,
                        'achievement_date' => $achievement['date'] ?? null,
                        'status' => 'active',
                    ]);

                    $this->linkAttachments($candidateId, 'achievement', $row->id, $achievement['attachments'] ?? [], $keepIds);
                }
            }

            // 8. Other Links
if (isset($data['links']) && is_array($data['links'])) {

    DB::table('candidate_other_links')
        ->where('candidate_id', $candidateId)
        ->delete();

    foreach ($data['links'] as $link) {

        $label = trim($link['label'] ?? '');
        $url = trim($link['url'] ?? '');

        if ($label === '' || $url === '') {
            continue;
        }

        if (!preg_match('/^https?:\/\//i', $url)) {
            $url = 'https://' . $url;
        }

        if (!filter_var($url, FILTER_VALIDATE_URL)) {
            continue;
        }

        DB::table('candidate_other_links')->insert([
            'candidate_id' => $candidateId,
            'label' => mb_substr($label, 0, 100),
            'url' => mb_substr($url, 0, 500),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}

            // Jo files kisi card se nahi judi (card delete hua ya upload adhura raha) unhe hata do
            $orphans = CandidateAttachment::where('candidate_id', $candidateId)
                ->whereNotIn('id', $keepIds)
                ->get();
            $orphanPaths = $orphans->pluck('file_path')->all();
            CandidateAttachment::whereIn('id', $orphans->pluck('id'))->delete();

            DB::commit();

            if ($orphanPaths) {
                Storage::disk('public')->delete($orphanPaths);
            }

            return response()->json([
                'success' => true,
                'message' => 'Resume saved successfully.'
            ], 200);

        } catch (\Throwable $e) {

            DB::rollBack();

            return response()->json([
                'success' => false,
                'message' => 'Failed to save resume.',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function show(Request $request, $candidateId)
    {
        $legacy = $request->attributes->get('legacy_user');

        // Candidate sirf apna resume dekh sakta hai
        if (strtolower($legacy['role'] ?? '') === 'candidate' && (int) $legacy['id'] !== (int) $candidateId) {
            return response()->json(['success' => false, 'message' => 'Forbidden.'], 403);
        }

        try {
            $personal = CandidatePersonalDetail::where('candidate_id', $candidateId)->first();

            // Path ko poora URL bana ke bhejo (sirf response ke liye, DB nahi badalta)
            if ($personal && $personal->profile_photo) {
                $personal->profile_photo = $this->photoUrl($personal->profile_photo);
            }

            $resume = [
                'personal' => $personal,
                'education' => CandidateEducation::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'skills' => CandidateSkill::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'courses' => CandidateCourseCertification::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'experience' => CandidateWorkExperience::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'projects' => CandidateProject::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'achievements' => CandidateAchievement::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'links' => DB::table('candidate_other_links')
                ->where('candidate_id', $candidateId)
                ->select('label', 'url')
                ->get(),
                'attachments' => [],
                'links' => DB::table('candidate_other_links')
    ->where('candidate_id', $candidateId)
    ->select('label', 'url')
    ->get(),
            ];

            // Attachments fail ho jayein to bhi baaki resume load hona chahiye
            try {
                $resume['attachments'] = CandidateAttachment::where('candidate_id', $candidateId)
                    ->get()
                    ->map(fn ($a) => $a->toApi())
                    ->values();
            } catch (\Throwable $e) {
                $resume['attachments'] = [];
            }

            return response()->json(['success' => true, 'resume' => $resume]);
        } catch (\Throwable $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
                'file' => $e->getFile() . ':' . $e->getLine(),
            ], 500);
        }
    }
}