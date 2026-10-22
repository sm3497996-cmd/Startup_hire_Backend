<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateEducation extends Model
{
    protected $table = 'candidate_educations';

    protected $fillable = [
        'candidate_id',
        'education_level',
        'degree_name',
        'specialization',
        'institution_name',
        'board_university',
        'start_year',
        'end_year',
        'percentage_cgpa',
        'is_pursuing',
        'city',
        'state',
        'country',
        'pincode',
        'status',
    ];
}