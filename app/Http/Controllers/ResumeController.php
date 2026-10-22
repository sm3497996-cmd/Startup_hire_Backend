<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

use App\Models\CandidatePersonalDetail;
use App\Models\CandidateEducation;
use App\Models\CandidateSkill;
use App\Models\CandidateCourseCertification;
use App\Models\CandidateWorkExperience;
use App\Models\CandidateProject;
use App\Models\CandidateAchievement;

class ResumeController extends Controller
{
    public function store(Request $request)
    {
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

        try {

            DB::beginTransaction();

            // 1. Personal Details
            if (isset($data['personal']) && is_array($data['personal'])) {

                $personal = $data['personal'];

                $personal['candidate_id'] = $candidateId;

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

                    CandidateCourseCertification::create([
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

                    CandidateWorkExperience::create([
                        'candidate_id' => $candidateId,
                        'company_name' => $experience['company'] ?? null,
                        'designation' => $experience['role'] ?? null,
                        'employment_type' => $experience['type'] ?? null,
                        'location' => $experience['location'] ?? null,
                        'start_date' => $startDate,
                        'end_date' => $endDate,
                        'currently_working' => $isCurrentlyWorking,
                        'job_description' => $experience['description'] ?? null,
                        'status' => 'active',
                    ]);
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

                    CandidateProject::create([
                        'candidate_id' => $candidateId,
                        'project_title' => $project['title'] ?? null,
                        'description' => $project['description'] ?? null,
                        'technologies_used' => isset($project['stack']) && is_array($project['stack'])
                            ? implode(', ', $project['stack'])
                            : ($project['stack'] ?? null),
                        'project_url' => $project['link'] ?? null,
                        'start_date' => $convertProjectDate($project['startDate'] ?? null),
                        'end_date' => $convertProjectDate($project['endDate'] ?? null),
                        'status' => 'active',
                    ]);
                }
            }

            // 7. Achievements
            CandidateAchievement::where('candidate_id', $candidateId)->delete();

            if (isset($data['achievements']) && is_array($data['achievements'])) {

                foreach ($data['achievements'] as $achievement) {

                    if (empty(trim($achievement['title'] ?? ''))) {
                        continue;
                    }

                    CandidateAchievement::create([
                        'candidate_id' => $candidateId,
                        'title' => $achievement['title'] ?? null,
                        'description' => $achievement['desc'] ?? null,
                        'issuer' => $achievement['issuer'] ?? null,
                        'achievement_date' => $achievement['date'] ?? null,
                        'status' => 'active',
                    ]);
                }
            }

            DB::commit();

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
            $resume = [
                'personal' => CandidatePersonalDetail::where('candidate_id', $candidateId)->first(),
                'education' => CandidateEducation::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'skills' => CandidateSkill::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'courses' => CandidateCourseCertification::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'experience' => CandidateWorkExperience::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'projects' => CandidateProject::where('candidate_id', $candidateId)->where('status', 'active')->get(),
                'achievements' => CandidateAchievement::where('candidate_id', $candidateId)->where('status', 'active')->get(),
            ];

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