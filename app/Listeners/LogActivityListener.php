<?php
// app/Listeners/LogActivityListener.php

namespace App\Listeners;

use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;

class LogActivityListener
{
    public function handle($event)
    {
        $user = Auth::user();
        
        // Get the model from the event
        $model = $event->__get('model') ?? $event->__get('eloquent') ?? null;
        
        if (!$model) {
            return;
        }
        
        $action = '';
        $oldValues = null;
        $newValues = null;
        
        // Determine the action based on the event type
        $eventClass = get_class($event);
        
        if (str_contains($eventClass, 'Created')) {
            $action = 'create';
            $newValues = $model->toArray();
        } elseif (str_contains($eventClass, 'Updated')) {
            $action = 'update';
            $oldValues = $model->getOriginal();
            $newValues = $model->getChanges();
        } elseif (str_contains($eventClass, 'Deleted')) {
            $action = 'delete';
            $oldValues = $model->toArray();
        } else {
            return;
        }
        
        // Get entity name
        $entityName = $model->name ?? $model->case_name ?? $model->title ?? $model->case_number ?? $model->id;
        
        AuditLog::create([
            'user_id' => $user?->id,
            'user_name' => $user ? ($user->first_name . ' ' . $user->last_name) : 'System',
            'user_email' => $user?->email,
            'user_role' => $user?->role,
            'action' => $action,
            'entity_type' => class_basename($model),
            'entity_id' => $model->id,
            'entity_name' => $entityName,
            'description' => "{$action} {$entityName}",
            'old_values' => $oldValues,
            'new_values' => $newValues,
            'ip_address' => request()->ip(),
            'user_agent' => request()->userAgent(),
            'request_method' => request()->method(),
            'request_url' => request()->fullUrl()
        ]);
    }
}