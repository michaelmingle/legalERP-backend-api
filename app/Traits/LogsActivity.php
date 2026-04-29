<?php
// app/Traits/LogsActivity.php

namespace App\Traits;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

trait LogsActivity
{
    /**
     * Log activity
     */
    // app/Traits/LogsActivity.php - Update the logActivity method

protected function logActivity($action, $module, $recordId = null, $recordType = null, $oldValues = null, $newValues = null)
{
    try {
        $user = Auth::user();
        if (!$user) return;
        
        AuditLog::create([
            'organization_id' => $user->organization_id, // Add this line
            'user_id' => $user->id,
            'user_name' => $user->first_name . ' ' . $user->last_name,
            'user_email' => $user->email,
            'user_role' => $user->role,
            'action' => $action,
            'module' => $module,
            'record_id' => $recordId,
            'record_type' => $recordType,
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'device' => $this->getDeviceType(),
        ]);
    } catch (\Exception $e) {
        Log::error('Failed to log activity: ' . $e->getMessage());
    }
}
    
    /**
     * Get device type from user agent
     */
    protected function getDeviceType()
    {
        $userAgent = request()->userAgent();
        if (str_contains($userAgent, 'Mobile')) return 'Mobile';
        if (str_contains($userAgent, 'Tablet')) return 'Tablet';
        return 'Desktop';
    }
    
    /**
     * Boot the trait - automatically logs model events for ALL models that use this trait
     */
    public static function bootLogsActivity()
    {
        static::created(function ($model) {
            $model->logActivity('create', $model->getTable(), $model->id, get_class($model), null, $model->getAttributes());
        });
        
        static::updated(function ($model) {
            if ($model->wasChanged()) {
                $model->logActivity('update', $model->getTable(), $model->id, get_class($model), $model->getOriginal(), $model->getChanges());
            }
        });
        
        static::deleted(function ($model) {
            $model->logActivity('delete', $model->getTable(), $model->id, get_class($model), $model->getOriginal(), null);
        });
    }
}