<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class CandidateCourseCertification extends Model
{
    protected $table = 'candidate_courses_certifications';

    protected $fillable = [
        'candidate_id',
        'type',
        'title',
        'issued_by',
        'issue_date',
        'expiry_date',
        'credential_id',
        'certificate_url',
        'details',
        'status',
    ];
}