<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class CandidateAttachment extends Model
{
    protected $table = 'candidate_attachments';

    protected $fillable = [
        'candidate_id', 'section', 'item_id',
        'original_name', 'file_path', 'mime_type', 'file_size',
    ];

    public function toApi(): array
    {
        return [
            'id'      => $this->id,
            'section' => $this->section,
            'item_id' => $this->item_id,
            'name'    => $this->original_name,
            'url'     => Storage::disk('public')->url($this->file_path),
            'mime'    => $this->mime_type,
            'size'    => $this->file_size,
        ];
    }
}