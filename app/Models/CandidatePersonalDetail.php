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
    public function setDobAttribute($value)
{
    $value = trim((string) $value);

    // 28/10/2004 ya 28-10-2004 -> 2004-10-28
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $value, $m)) {
        $this->attributes['dob'] = sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]);
        return;
    }

    // 2004-10-28 pehle se sahi hai
    if (preg_match('/^\d{4}-\d{2}-\d{2}/', $value)) {
        $this->attributes['dob'] = substr($value, 0, 10);
        return;
    }

    // khali ya samajh na aane wali value
    $this->attributes['dob'] = null;
}
}