<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Carbon\Carbon;

/**
 * Employer -> Candidate: like / save / comment / report
 * Candidate ko activity, Superadmin ko sab kuch.
 *
 * ASSUMPTIONS: users table mein `name` aur `role` columns hain
 * (role = employer | candidate | superadmin)
 */
class InteractionController extends Controller
{
    private function requireRole(Request $request, array $roles)
    {
        $user = $request->user();
        if (!$user || !in_array($user->role, $roles, true)) {
            abort(response()->json(['success' => false, 'message' => 'Not allowed'], 403));
        }
        return $user;
    }

    private function withTimeAgo($rows)
    {
        return collect($rows)->map(function ($row) {
            $row->time_ago = Carbon::parse($row->created_at)->diffForHumans();
            return $row;
        })->values();
    }

    // Sirf asli candidates hi valid hain
    private function candidateRule(): array
    {
        return ['required', 'integer', Rule::exists('users', 'id')->where('role', 'candidate')];
    }

    // ---------- EMPLOYER ----------

    public function toggle(Request $request, string $type)
    {
        $user = $this->requireRole($request, ['employer']);
        $request->validate(['candidate_id' => $this->candidateRule()]);

        $table = $type === 'like' ? 'candidate_likes' : 'candidate_saves';
        $match = [
            'candidate_id' => $request->candidate_id,
            'employer_id'  => $user->id,
        ];

        $query = DB::table($table)->where($match);
        if ($query->exists()) {
            $query->delete();
            return response()->json(['success' => true, 'active' => false]);
        }

        DB::table($table)->insert($match + ['created_at' => now()]);
        return response()->json(['success' => true, 'active' => true]);
    }

    public function comment(Request $request)
    {
        $user = $this->requireRole($request, ['employer']);
        $data = $request->validate([
            'candidate_id' => $this->candidateRule(),
            'comment_text' => 'required|string|max:1000',
        ]);

        $id = DB::table('candidate_comments')->insertGetId([
            'candidate_id' => $data['candidate_id'],
            'employer_id'  => $user->id,
            'comment_text' => $data['comment_text'],
            'created_at'   => now(),
        ]);

        return response()->json(['success' => true, 'id' => $id]);
    }

    public function report(Request $request)
    {
        $user = $this->requireRole($request, ['employer']);
        $data = $request->validate([
            'candidate_id' => $this->candidateRule(),
            'reason'       => 'required|string|max:255',
            'details'      => 'nullable|string|max:2000',
        ]);

        DB::table('candidate_reports')->insert([
            'candidate_id' => $data['candidate_id'],
            'employer_id'  => $user->id,
            'reason'       => $data['reason'],
            'details'      => $data['details'] ?? null,
            'created_at'   => now(),
        ]);

        return response()->json(['success' => true, 'message' => 'Report submit ho gayi']);
    }

    // Dashboard khulte hi: maine kin candidates ko like/save kiya
    public function myState(Request $request)
    {
        $user = $this->requireRole($request, ['employer']);

        return response()->json([
            'success' => true,
            'liked' => DB::table('candidate_likes')->where('employer_id', $user->id)->pluck('candidate_id'),
            'saved' => DB::table('candidate_saves')->where('employer_id', $user->id)->pluck('candidate_id'),
        ]);
    }

    // Employer dashboard ke liye asli candidates + unka profile data
    public function candidates(Request $request)
    {
        $me = $this->requireRole($request, ['employer', 'superadmin']);

        $users = DB::table('users as u')
            ->where('u.role', 'candidate')
            ->where('u.is_active', 1)
            ->selectRaw(
                'u.id, u.name, u.email,
                 (SELECT COUNT(*) FROM candidate_likes l WHERE l.candidate_id = u.id AND l.employer_id <> ?) AS likes,
                 (SELECT COUNT(*) FROM candidate_comments c WHERE c.candidate_id = u.id) AS comments',
                [$me->id]
            )
            ->orderByDesc('u.id')
            ->get();

        $ids = $users->pluck('id');

        $skills = DB::table('candidate_skills')
            ->whereIn('candidate_id', $ids)->where('status', 'active')
            ->get()->groupBy('candidate_id');

        // sabse nayi degree pehle
        $edu = DB::table('candidate_educations')
            ->whereIn('candidate_id', $ids)
            ->orderByDesc('end_year')->orderByDesc('id')
            ->get()->groupBy('candidate_id');

        // abhi ki job pehle, phir nayi se purani
        $work = DB::table('candidate_work_experiences')
            ->whereIn('candidate_id', $ids)->where('status', 'active')
            ->orderByDesc('currently_working')->orderByDesc('start_date')
            ->get()->groupBy('candidate_id');

        $personal = DB::table('candidate_personal_details')
            ->whereIn('candidate_id', $ids)
            ->get()->keyBy('candidate_id');

        $palette = ['#5b3df5', '#12b86f', '#e83f7d', '#159fd8', '#f7a51c', '#7d2ae8', '#ff6b4a'];

        // role + skills dekhkar category chips ke liye group
        $categorize = function (string $text) {
            $t = strtolower($text);
            $groups = [
                'Design'    => ['design', 'figma', 'ux', 'ui/'],
                'Frontend'  => ['react', 'frontend', 'front-end', 'html', 'css', 'javascript', 'web'],
                'Backend'   => ['node', 'backend', 'php', 'laravel', 'java', 'python', 'api', 'sql', 'devops'],
                'Marketing' => ['seo', 'marketing', 'content', 'ads'],
                'Data'      => ['data', 'analyst', 'analytics', 'machine learning'],
            ];
            foreach ($groups as $name => $words) {
                foreach ($words as $w) {
                    if (str_contains($t, $w)) return $name;
                }
            }
            return 'General';
        };

        $list = $users->map(function ($u) use ($skills, $edu, $work, $personal, $palette, $categorize) {
            $jobs = $work->get($u->id, collect());
            $p    = $personal->get($u->id);
            $e    = optional($edu->get($u->id))->first();
            $skillNames = $skills->get($u->id, collect())->pluck('skill_name')->values()->all();

            // Experience: pehle personal details ka total, nahi to jobs ki dates se
            if ($p && $p->total_experience_years !== null) {
                $years = (float) $p->total_experience_years;
            } else {
                $months = 0;
                foreach ($jobs as $j) {
                    $start = Carbon::parse($j->start_date);
                    $end   = $j->end_date ? Carbon::parse($j->end_date) : now();
                    $months += abs($start->diffInMonths($end));
                }
                $years = round($months / 12, 1);
            }
            $yearsText = rtrim(rtrim(number_format($years, 1), '0'), '.');

            $latest = $jobs->first();
            $role   = ($p && $p->current_designation)
                ?: ($latest->designation ?? null)
                ?: ($p->resume_headline ?? null)
                ?: 'Candidate';

            $education = $e
                ? trim($e->degree_name . ($e->specialization ? ' - ' . $e->specialization : ''))
                : 'Not specified';

            $summary = ($p && $p->resume_headline)
                ?: ($latest ? "$role at {$latest->company_name}" : 'Profile details not added yet.');

            $parts = preg_split('/\s+/', trim($u->name));
            $initials = mb_strtoupper(mb_substr($parts[0] ?? '', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));

            return [
                'id'           => (int) $u->id,
                'name'         => $u->name,
                'initials'     => $initials,
                'email'        => $u->email,
                'color'        => $palette[$u->id % count($palette)],
                'role'         => $role,
                'category'     => $categorize($role . ' ' . implode(' ', $skillNames)),
                'location'     => $latest->location ?? 'Not specified',
                'experience'   => $yearsText . ' yrs experience',
                'salary'       => 'Not specified',
                'availability' => 'Not specified',
                'education'    => $education,
                'match'        => 0,
                'summary'      => $summary,
                'skills'       => $skillNames,
                'likes'        => (int) $u->likes,
                'comments'     => (int) $u->comments,
            ];
        });

        return response()->json(['success' => true, 'candidates' => $list]);
    }

    // ---------- COMMENTS LIST ----------

    public function listComments(Request $request, int $candidateId)
    {
        $this->requireRole($request, ['employer', 'candidate', 'superadmin']);

        $rows = DB::table('candidate_comments as c')
            ->join('users as u', 'u.id', '=', 'c.employer_id')
            ->where('c.candidate_id', $candidateId)
            ->orderByDesc('c.created_at')
            ->get(['c.comment_text', 'c.created_at', 'u.name as employer_name']);

        return response()->json(['success' => true, 'comments' => $this->withTimeAgo($rows)]);
    }

    // ---------- CANDIDATE: mujhe kisne like/save/comment kiya ----------

    public function myActivity(Request $request)
    {
        $user = $this->requireRole($request, ['candidate']);
        $id = $user->id;

        $likes = DB::table('candidate_likes as l')
            ->join('users as u', 'u.id', '=', 'l.employer_id')
            ->where('l.candidate_id', $id)
            ->selectRaw("'like' as type, u.name as employer_name, l.created_at, NULL as comment_text");

        $saves = DB::table('candidate_saves as s')
            ->join('users as u', 'u.id', '=', 's.employer_id')
            ->where('s.candidate_id', $id)
            ->selectRaw("'save' as type, u.name as employer_name, s.created_at, NULL as comment_text");

        $comments = DB::table('candidate_comments as c')
            ->join('users as u', 'u.id', '=', 'c.employer_id')
            ->where('c.candidate_id', $id)
            ->selectRaw("'comment' as type, u.name as employer_name, c.created_at, c.comment_text");

        $rows = $likes->unionAll($saves)->unionAll($comments)
            ->orderByDesc('created_at')->limit(100)->get();

        return response()->json(['success' => true, 'activity' => $this->withTimeAgo($rows)]);
    }

    // ---------- SUPERADMIN: sab kuch ----------

    public function adminActivity(Request $request)
    {
        $this->requireRole($request, ['superadmin']);

        $base = fn ($table, $alias) => DB::table("$table as $alias")
            ->join('users as e', 'e.id', '=', "$alias.employer_id")
            ->join('users as cd', 'cd.id', '=', "$alias.candidate_id");

        $likes = $base('candidate_likes', 'l')
            ->selectRaw("'like' as type, e.name as employer_name, cd.name as candidate_name, l.created_at, NULL as detail");
        $saves = $base('candidate_saves', 's')
            ->selectRaw("'save' as type, e.name as employer_name, cd.name as candidate_name, s.created_at, NULL as detail");
        $comments = $base('candidate_comments', 'c')
            ->selectRaw("'comment' as type, e.name as employer_name, cd.name as candidate_name, c.created_at, c.comment_text as detail");
        $reports = $base('candidate_reports', 'r')
            ->selectRaw("CONCAT('report (', r.status, ')') as type, e.name as employer_name, cd.name as candidate_name, r.created_at, CONCAT(r.reason, ' - ', IFNULL(r.details, '')) as detail");

        $rows = $likes->unionAll($saves)->unionAll($comments)->unionAll($reports)
            ->orderByDesc('created_at')->limit(500)->get();

        return response()->json(['success' => true, 'activity' => $this->withTimeAgo($rows)]);
    }
}
