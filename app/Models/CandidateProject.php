<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateProject extends Model
{
    protected $table = 'candidate_projects';

    protected $fillable = [
        'candidate_id',
        'project_title',
        'description',
        'technologies_used',
        'project_url',
        'start_date',
        'end_date',
        'status',
    ];
}