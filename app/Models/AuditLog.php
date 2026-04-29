<?php
// app/Models/AuditLog.php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AuditLog extends Model
{
    protected $table = 'audit_logs';
    
    protected $fillable = [
        'organization_id',  // Add this field
        'user_id',
        'user_name',
        'user_email',
        'user_role',
        'action',
        'module',
        'record_id',
        'record_type',
        'old_values',
        'new_values',
        'description',
        'ip_address',
        'user_agent',
        'device',
        'request_method',
        'request_url',
        'route_name'
    ];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
    
    public function organization()
    {
        return $this->belongsTo(Organization::class);
    }

    // Scopes for filtering
    public function scopeOfType($query, $entityType)
    {
        return $query->where('module', $entityType);
    }

    public function scopeOfAction($query, $action)
    {
        return $query->where('action', $action);
    }

    public function scopeDateRange($query, $startDate, $endDate)
    {
        return $query->whereBetween('created_at', [$startDate, $endDate]);
    }

    public function scopeSearch($query, $search)
    {
        return $query->where(function($q) use ($search) {
            $q->where('user_name', 'like', "%{$search}%")
              ->orWhere('user_email', 'like', "%{$search}%")
              ->orWhere('module', 'like', "%{$search}%")
              ->orWhere('description', 'like', "%{$search}%")
              ->orWhere('action', 'like', "%{$search}%")
              ->orWhere('ip_address', 'like', "%{$search}%");
        });
    }
    
    // Time-based scopes
    public function scopeToday($query)
    {
        return $query->whereDate('created_at', today());
    }

    public function scopeThisWeek($query)
    {
        return $query->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()]);
    }

    public function scopeThisMonth($query)
    {
        return $query->whereMonth('created_at', now()->month);
    }
}