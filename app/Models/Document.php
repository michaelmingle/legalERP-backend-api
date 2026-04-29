<?php
// app/Models/Document.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class Document extends Model
{
    use LogsActivity, SoftDeletes;

    protected $fillable = [
        'organization_id',
        'case_id',
        'uploaded_by',
        'file_path',
        'file_name',
        'file_size',
        'file_type',
        'description',
        'confidentiality',
    ];

    protected $casts = [
        'file_size' => 'integer',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
        'deleted_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }

    public function uploader()
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }
}