<?php
// app/Models/Appointment.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;
use Illuminate\Database\Eloquent\SoftDeletes;

class Appointment extends Model
{
    use HasFactory, LogsActivity, SoftDeletes;

    protected $fillable = [
        'case_id', 'lawyer_id', 'title', 'description',
        'purpose', 'status', 'start_time', 'end_time', 'location',
        'meeting_link', 'notes'
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
    ];

    public function case()
    {
        return $this->belongsTo(Cases::class);
    }

    public function lawyer()
    {
        return $this->belongsTo(User::class, 'lawyer_id');
    }
}