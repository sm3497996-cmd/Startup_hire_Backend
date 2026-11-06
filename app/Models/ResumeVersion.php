<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ResumeVersion extends Model
{
    protected $table = 'resume_versions';

    protected $fillable = [
        'candidate_id', 'title', 'target_field', 'data', 'is_default',
        'share_token', 'shared_at',
    ];
}