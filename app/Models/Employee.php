<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Employee extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'user_id',
        'full_name',
        'date_of_birth',
        'gender',
        'contact_number',
        'contact_email',
        'emergency_contact_name',
        'emergency_contact_number',
        'emergency_relation',
        'employee_id',
        'department',
        'job_title',
        'hire_date',
        'employment_type',
        'status',
        'salary',
        'allowance',
        'deduction',
        'bank_name',
        'bank_account_number',
        'photo'
    ];

    protected $casts = [
        'date_of_birth' => 'date',
        'hire_date' => 'date',
        'salary' => 'decimal:2',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function getPhotoUrlAttribute()
{
    return $this->photo ? asset('storage/' . $this->photo) : null;
}
}