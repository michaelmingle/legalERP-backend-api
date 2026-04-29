<?php
// app/Helpers/AuditLogger.php

namespace App\Helpers;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Request;

class AuditLogger
{
    public static function log($action, $entityType, $entityId, $entityName, $description = null, $oldValues = null, $newValues = null)
    {
        $user = Auth::user();
        
        if (!$user) return;
        
        AuditLog::create([
            'user_id' => $user->id,
            'user_name' => $user->first_name . ' ' . $user->last_name,
            'user_email' => $user->email,
            'user_role' => $user->role,
            'action' => $action,
            'entity_type' => $entityType,
            'entity_id' => $entityId,
            'entity_name' => $entityName,
            'description' => $description,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => Request::ip(),
            'user_agent' => Request::userAgent(),
            'request_method' => Request::method(),
            'request_url' => Request::fullUrl()
        ]);
    }
    
    public static function login($user)
    {
        self::log('login', 'User', $user->id, $user->email, "User logged in");
    }
    
    public static function logout($user)
    {
        self::log('logout', 'User', $user->id, $user->email, "User logged out");
    }
    
    public static function view($entityType, $entityId, $entityName)
    {
        self::log('view', $entityType, $entityId, $entityName, "Viewed {$entityType}: {$entityName}");
    }
    
    public static function export($entityType, $description)
    {
        self::log('export', $entityType, null, $description, $description);
    }
    
    public static function import($entityType, $description, $count)
    {
        self::log('import', $entityType, null, $description, "Imported {$count} {$entityType}(s)");
    }
}