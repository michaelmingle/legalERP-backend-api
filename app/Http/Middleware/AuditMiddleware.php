<?php
// app/Http/Middleware/AuditMiddleware.php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class AuditMiddleware
{
    public function handle(Request $request, Closure $next): Response
    {
        // Process the request first
        $response = $next($request);
        
        // Only log for authenticated users
        if (Auth::check() && $this->shouldLog($request)) {
            $this->logActivity($request);
        }
        
        return $response;
    }
    
    protected function shouldLog(Request $request): bool
    {
        // Skip logging for audit routes to prevent infinite loop
        if (str_contains($request->path(), 'audit-logs')) {
            return false;
        }
        
        // Log POST, PUT, PATCH, DELETE requests
        if ($request->isMethod('post') || $request->isMethod('put') || 
            $request->isMethod('patch') || $request->isMethod('delete')) {
            return true;
        }
        
        return false;
    }
    
    protected function logActivity(Request $request): void
    {
        $user = Auth::user();
        
        if (!$user) return;
        
        $action = $this->determineAction($request);
        $entityType = $this->determineEntityType($request);
        $entityId = $this->extractEntityId($request);
        $entityName = $this->extractEntityName($request);
        
        try {
            AuditLog::create([
                'user_id' => $user->id,
                'user_name' => $user->first_name . ' ' . $user->last_name,
                'user_email' => $user->email,
                'user_role' => $user->role,
                'action' => $action,
                'entity_type' => $entityType ?: 'System',
                'entity_id' => $entityId,
                'entity_name' => $entityName,
                'description' => $this->generateDescription($action, $entityType, $entityName, $user),
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'request_method' => $request->method(),
                'request_url' => $request->fullUrl(),
                'route_name' => $request->route()?->getName()
            ]);
        } catch (\Exception $e) {
            // Silently fail
        }
    }
    
    protected function determineAction(Request $request): string
    {
        return match($request->method()) {
            'POST' => 'create',
            'PUT', 'PATCH' => 'update',
            'DELETE' => 'delete',
            'GET' => 'view',
            default => strtolower($request->method())
        };
    }
    
    protected function determineEntityType(Request $request): ?string
    {
        $path = $request->path();
        
        if (str_contains($path, 'cases')) return 'Case';
        if (str_contains($path, 'clients')) return 'Client';
        if (str_contains($path, 'documents')) return 'Document';
        if (str_contains($path, 'invoices')) return 'Invoice';
        if (str_contains($path, 'users')) return 'User';
        if (str_contains($path, 'appointments')) return 'Appointment';
        if (str_contains($path, 'time-trackings')) return 'TimeTracking';
        if (str_contains($path, 'leaves')) return 'Leave';
        if (str_contains($path, 'payments')) return 'Payment';
        
        return null;
    }
    
    protected function extractEntityId(Request $request): ?int
    {
        $route = $request->route();
        if ($route) {
            $parameters = $route->parameters();
            foreach ($parameters as $key => $value) {
                if (is_numeric($value)) {
                    return (int) $value;
                }
            }
        }
        return null;
    }
    
    protected function extractEntityName(Request $request): ?string
    {
        if ($request->has('name')) return $request->input('name');
        if ($request->has('case_name')) return $request->input('case_name');
        if ($request->has('title')) return $request->input('title');
        return null;
    }
    
    protected function generateDescription($action, $entityType, $entityName, $user): string
    {
        $userName = $user->first_name . ' ' . $user->last_name;
        
        if ($entityName) {
            return "{$userName} {$action}d {$entityType}: {$entityName}";
        }
        return "{$userName} {$action}d {$entityType}";
    }
}