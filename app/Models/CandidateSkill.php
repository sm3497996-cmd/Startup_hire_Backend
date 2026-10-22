<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateSkill extends Model
{
    protected $table = 'candidate_skills';

    protected $fillable = [
        'candidate_id',
        'skill_name',
        'proficiency_level',
        'experience_years',
        'status',
    ];
}