<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'user_id',
        'organization_id',
        'full_name',
        'client_number',
        'email',
        'phone',
        'mobile',
        'photo_url',
        'gender',
        'date_of_birth',
        'job_title',
        'tags',
        'start_date',
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

    public function documents()
    {
        return $this->hasMany(Document::class);
    }

    // Assigned laywer relationship
    public function assignedLawyer()
    {
        return $this->belongsTo(User::class, 'assigned_lawyer');
    }

    public function user()
{
    return $this->belongsTo(User::class);
}
}
