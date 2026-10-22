<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidatePersonalDetail extends Model
{
    protected $table = 'candidate_personal_details';

    protected $fillable = [
        'candidate_id',
        'headline',
        'dob',
        'gender',
        'languages',
        'designation',
        'company',
        'experience_years',
        'salary',
        'notice',
        'address',
        'city',
        'state',
        'country',
        'pincode',
        'summary',
        'resume_file',
        'profile_photo',
        'status',
    ];
}