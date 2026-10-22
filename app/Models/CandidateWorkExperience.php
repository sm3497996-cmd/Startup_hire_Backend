<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateWorkExperience extends Model
{
    protected $table = 'candidate_work_experiences';

    protected $fillable = [
        'candidate_id',
        'company_name',
        'designation',
        'employment_type',
        'location',
        'start_date',
        'end_date',
        'currently_working',
        'job_description',
        'status',
    ];
}