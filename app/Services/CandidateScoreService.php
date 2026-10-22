<?php

namespace App\Services;

use Carbon\Carbon;
use App\Models\CandidatePersonalDetail;
use App\Models\CandidateEducation;
use App\Models\CandidateSkill;
use App\Models\CandidateCourseCertification;
use App\Models\CandidateWorkExperience;
use App\Models\CandidateProject;
use App\Models\CandidateAchievement;

class CandidateScoreService
{
    const POINTS = [
        'personal_field' => 2,
        'address'        => 2,
        'education'      => 2,
        'skills'         => 2,
        'achievement'    => 2,
        'course'         => 1,
        'experience'     => 3,
        'project'        => 3,
        'other'          => 1,
    ];
    const SKILL_LIMIT = 25;
    const SKILL_PENALTY = 1;
    const SWITCH_PENALTY = 5;      // 12 mahine mein 2 se zyada job
    const MAX_JOBS_PER_YEAR = 2;

    public function score($candidateId): array
    {
        $b = [];

        // 1. Personal info (har bhari hui field = 2)
        $personal = CandidatePersonalDetail::where('candidate_id', $candidateId)->first();
        $skipCols = ['id','candidate_id','status','created_at','updated_at',
                     'address','city','state','country','pincode'];
        $filled = 0;
        if ($personal) {
            foreach ($personal->getAttributes() as $k => $v) {
                if (in_array($k, $skipCols)) continue;
                if ($v !== null && trim((string)$v) !== '') $filled++;
            }
        }
        $b['personal'] = $filled * self::POINTS['personal_field'];

        // 2. Address
        $hasAddress = $personal && !empty($personal->address) && !empty($personal->city);
        $b['address'] = $hasAddress ? self::POINTS['address'] : 0;

        // 3. Education
        $edu = CandidateEducation::where('candidate_id', $candidateId)->where('status', 'active')
                ->whereNotNull('degree_name')->where('degree_name', '!=', '')->count();
        $b['education'] = $edu * self::POINTS['education'];

        // 4. Skills
        $skillCount = CandidateSkill::where('candidate_id', $candidateId)->where('status', 'active')->count();
        $b['skills'] = $skillCount > 0 ? self::POINTS['skills'] : 0;
        $b['skills_penalty'] = $skillCount > self::SKILL_LIMIT ? -self::SKILL_PENALTY : 0;

        // 5. Achievements
        $ach = CandidateAchievement::where('candidate_id', $candidateId)->where('status', 'active')->count();
        $b['achievements'] = $ach * self::POINTS['achievement'];

        // 6. Courses
        $courses = CandidateCourseCertification::where('candidate_id', $candidateId)->where('status', 'active')->count();
        $b['courses'] = $courses * self::POINTS['course'];

        // 7. Projects
        $projects = CandidateProject::where('candidate_id', $candidateId)->where('status', 'active')->count();
        $b['projects'] = $projects * self::POINTS['project'];

        // 8. Work experience + job-switch logic
        $jobs = CandidateWorkExperience::where('candidate_id', $candidateId)
                ->where('status', 'active')->orderBy('start_date')->get();

        $expPoints = $jobs->count() * self::POINTS['experience'];
        $tenureFactor = 1.0;
        $switchPenalty = 0;

        if ($jobs->count() > 0) {
            $totalMonths = 0;
            $starts = [];
            foreach ($jobs as $j) {
                $start = $j->start_date ? Carbon::parse($j->start_date) : null;
                if (!$start) continue;
                $end = $j->end_date ? Carbon::parse($j->end_date) : Carbon::now();
                $totalMonths += max(0, $start->diffInMonths($end));
                $starts[] = $start;
            }

            // Average tenure < 12 mahine => proportional cut (0.9 => 10% cut)
            $avg = count($starts) ? $totalMonths / count($starts) : 12;
            $tenureFactor = min(1.0, $avg / 12);

            // 12 mahine ke andar 2 se zyada job
            foreach ($starts as $s) {
                $inWindow = collect($starts)->filter(
                    fn ($d) => $d->gte($s) && $d->lt($s->copy()->addMonths(12))
                )->count();
                if ($inWindow > self::MAX_JOBS_PER_YEAR) {
                    $switchPenalty = -self::SWITCH_PENALTY;
                    break;
                }
            }
        }

        $b['experience_raw']    = $expPoints;
        $b['tenure_factor']     = round($tenureFactor, 2);
        $b['experience']        = round($expPoints * $tenureFactor, 2);
        $b['switch_penalty']    = $switchPenalty;

        // Total
        $total = $b['personal'] + $b['address'] + $b['education'] + $b['skills']
               + $b['skills_penalty'] + $b['achievements'] + $b['courses']
               + $b['projects'] + $b['experience'] + $b['switch_penalty'];

        return [
            'total'     => round($total, 2),
            'breakdown' => $b,
            'verdict'   => $total >= 40 ? 'Recommended' : ($total >= 20 ? 'Average' : 'Low'),
        ];
    }
}