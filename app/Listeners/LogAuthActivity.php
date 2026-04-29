<?php
// app/Listeners/LogAuthActivity.php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

class LogAuthActivity
{
    public function logLogin(Login $event)
    {
        $user = $event->user;
        
        AuditLog::create([
            'user_id' => $user->id,
            'user_name' => $user->first_name . ' ' . $user->last_name,
            'user_email' => $user->email,
            'user_role' => $user->role,
            'action' => 'login',
            'entity_type' => 'Authentication',
            'description' => "User {$user->email} logged into the system",
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_method' => request()->method(),
            'request_url' => request()->fullUrl()
        ]);
    }
    
    public function logLogout(Logout $event)
    {
        $user = $event->user;
        
        if ($user) {
            AuditLog::create([
                'user_id' => $user->id,
                'user_name' => $user->first_name . ' ' . $user->last_name,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'action' => 'logout',
                'entity_type' => 'Authentication',
                'description' => "User {$user->email} logged out of the system",
                'ip_address' => request()->ip(),
                'user_agent' => request()->userAgent(),
                'request_method' => request()->method(),
                'request_url' => request()->fullUrl()
            ]);
        }
    }
}