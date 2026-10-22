<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateAchievement extends Model
{
    protected $table = 'candidate_achievements';

    protected $fillable = [
        'candidate_id',
        'title',
        'description',
        'issuer',
        'achievement_date',
        'status',
    ];
}