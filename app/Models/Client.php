<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'organization_id',
        'full_name',
        'email',
        'phone',
        'mobile',
        'photo_url',
        'gender',
        'date_of_birth',
        'job_title',
        'status',
        'address',
        'assigned_lawyer',
        'document_id',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function cases()
    {
        return $this->hasMany(Cases::class);
    }
    
}
