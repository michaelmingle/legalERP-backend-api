<?php
// app/Models/TeamInvite.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use App\Traits\LogsActivity;

class TeamInvite extends Model
{
    use LogsActivity;

    protected $fillable = [
        'organization_id',
        'email',
        'token',
        'status',
        'expires_at'
    ];

    protected $casts = [
        'expires_at' => 'datetime',
    ];

    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    public function isExpired()
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    public function scopePending($query)
    {
        return $query->where('status', 'pending')
                     ->where(function($q) {
                         $q->whereNull('expires_at')
                           ->orWhere('expires_at', '>', now());
                     });
    }

    public function markAsAccepted()
    {
        $this->update(['status' => 'accepted']);
    }

    public function markAsExpired()
    {
        $this->update(['status' => 'expired']);
    }
}