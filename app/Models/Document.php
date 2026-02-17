<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Document extends Model
{
    protected $fillable = [
        'file_path',
        'organization_id',
        'uploaded_by',
        'case_id',
        'description',
        'original_filename',
        'mime_type',
        'confidentiality',
    ];

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }
}
