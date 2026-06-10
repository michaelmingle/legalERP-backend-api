<?php
// app/Http/Controllers/Api/V1/AuditLogController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class AuditLogController extends Controller
{
    /**
     * Display a listing of the resource (filtered by organization)
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            $isSuperAdmin = $user->role === 'super_admin';

            // Only admin, owner and super_admin can view audit logs
            if (!in_array($user->role, ['admin', 'owner', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view audit logs'
                ], 403);
            }

            $query = AuditLog::with('user')->orderBy('created_at', 'desc');
            if (!$isSuperAdmin) {
                $query->where('organization_id', $organizationId);
            }
            
            // Filter by module
            if ($request->has('module') && $request->module !== 'all' && $request->module !== '') {
                $query->where('module', $request->module);
            }
            
            // Filter by action
            if ($request->has('action') && $request->action !== 'all' && $request->action !== '') {
                $query->where('action', $request->action);
            }
            
            // Filter by user
            if ($request->has('user_id') && $request->user_id !== 'all' && $request->user_id !== '') {
                $query->where('user_id', $request->user_id);
            }
            
            // Date range filter
            if ($request->has('from_date') && $request->from_date) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date') && $request->to_date) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }
            
            // Search
            if ($request->has('search') && $request->search) {
                $search = $request->search;
                $query->where(function($q) use ($search) {
                    $q->where('user_name', 'like', "%{$search}%")
                      ->orWhere('user_email', 'like', "%{$search}%")
                      ->orWhere('action', 'like', "%{$search}%")
                      ->orWhere('module', 'like', "%{$search}%")
                      ->orWhere('ip_address', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            }
            
            $perPage = $request->get('per_page', 50);
            $logs = $query->paginate($perPage);
            
            // Get statistics (org-scoped for non super admins, global for super admins)
            $scope = function () use ($isSuperAdmin, $organizationId) {
                $q = AuditLog::query();
                if (!$isSuperAdmin) {
                    $q->where('organization_id', $organizationId);
                }
                return $q;
            };

            $stats = [
                'total'     => $scope()->count(),
                'today'     => $scope()->whereDate('created_at', today())->count(),
                'this_week' => $scope()->whereBetween('created_at', [now()->startOfWeek(), now()->endOfWeek()])->count(),
                'this_month' => $scope()->whereMonth('created_at', now()->month)->count(),
                'by_module' => $scope()->select('module', DB::raw('count(*) as total'))->groupBy('module')->get(),
                'by_action' => $scope()->select('action', DB::raw('count(*) as total'))->groupBy('action')->get(),
            ];
            
            return response()->json([
                'success' => true,
                'data' => $logs,
                'stats' => $stats
            ]);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch audit logs: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Display the specified resource (filtered by organization)
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            $isSuperAdmin = $user->role === 'super_admin';

            if (!in_array($user->role, ['admin', 'owner', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized to view audit logs'
                ], 403);
            }

            $query = AuditLog::with('user');
            if (!$isSuperAdmin) {
                $query->where('organization_id', $organizationId);
            }
            $log = $query->findOrFail($id);
            
            return response()->json([
                'success' => true,
                'data' => $log
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Log not found'
            ], 404);
        }
    }
    
    /**
     * Get distinct modules for the organization
     */
    public function getModules(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            $isSuperAdmin = $user->role === 'super_admin';

            if (!in_array($user->role, ['admin', 'owner', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $query = AuditLog::query();
            if (!$isSuperAdmin) {
                $query->where('organization_id', $organizationId);
            }
            $modules = $query->distinct()->pluck('module');
            
            return response()->json([
                'success' => true,
                'data' => $modules
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch modules'
            ], 500);
        }
    }
    
    /**
     * Get distinct actions for the organization
     */
    public function getActions(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            $isSuperAdmin = $user->role === 'super_admin';

            if (!in_array($user->role, ['admin', 'owner', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $query = AuditLog::query();
            if (!$isSuperAdmin) {
                $query->where('organization_id', $organizationId);
            }
            $actions = $query->distinct()->pluck('action');
            
            return response()->json([
                'success' => true,
                'data' => $actions
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch actions'
            ], 500);
        }
    }
    
    /**
     * Clear old audit logs (filtered by organization)
     */
    public function clear(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            $isSuperAdmin = $user->role === 'super_admin';

            if (!in_array($user->role, ['admin', 'owner', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $days = $request->get('days', 30);
            $query = AuditLog::where('created_at', '<', now()->subDays($days));
            if (!$isSuperAdmin) {
                $query->where('organization_id', $organizationId);
            }
            $deleted = $query->delete();
            
            return response()->json([
                'success' => true,
                'message' => "Deleted {$deleted} old logs"
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to clear logs'
            ], 500);
        }
    }
    
    /**
     * Export audit logs to CSV (filtered by organization)
     */
    public function export(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            $isSuperAdmin = $user->role === 'super_admin';

            if (!in_array($user->role, ['admin', 'owner', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }

            $query = AuditLog::with('user')->orderBy('created_at', 'desc');
            if (!$isSuperAdmin) {
                $query->where('organization_id', $organizationId);
            }
            
            if ($request->has('module') && $request->module !== 'all' && $request->module !== '') {
                $query->where('module', $request->module);
            }
            if ($request->has('action') && $request->action !== 'all' && $request->action !== '') {
                $query->where('action', $request->action);
            }
            if ($request->has('from_date') && $request->from_date) {
                $query->whereDate('created_at', '>=', $request->from_date);
            }
            if ($request->has('to_date') && $request->to_date) {
                $query->whereDate('created_at', '<=', $request->to_date);
            }
            
            $logs = $query->get();
            
            $csvData = [];
            $csvData[] = ['ID', 'User', 'Email', 'Role', 'Action', 'Module', 'Record ID', 'Record Type', 'IP Address', 'Device', 'Date', 'Time'];
            
            foreach ($logs as $log) {
                $csvData[] = [
                    $log->id,
                    $log->user_name,
                    $log->user_email,
                    $log->user_role,
                    $log->action,
                    $log->module,
                    $log->record_id,
                    $log->record_type,
                    $log->ip_address,
                    $log->device,
                    $log->created_at->format('Y-m-d'),
                    $log->created_at->format('H:i:s'),
                ];
            }
            
            $handle = fopen('php://temp', 'r+');
            foreach ($csvData as $row) {
                fputcsv($handle, $row);
            }
            rewind($handle);
            $csvContent = stream_get_contents($handle);
            fclose($handle);
            
            return response($csvContent, 200)
                ->header('Content-Type', 'text/csv')
                ->header('Content-Disposition', "attachment; filename=audit-logs-" . date('Y-m-d') . ".csv");
                
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to export logs: ' . $e->getMessage()
            ], 500);
        }
    }
    
    /**
     * Get audit statistics for dashboard (filtered by organization)
     */
    public function getStats(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only admin and owner can view audit stats
            if (!in_array($user->role, ['admin', 'owner', 'super_admin'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 403);
            }
            
            $endDate = now();
            $startDate = $request->has('days') ? now()->subDays($request->days) : now()->subDays(30);
            
            $stats = [
                'total_activities' => AuditLog::where('organization_id', $organizationId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->count(),
                'by_entity' => AuditLog::where('organization_id', $organizationId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->selectRaw('module as entity_type, count(*) as count')
                    ->groupBy('module')
                    ->get(),
                'top_users' => AuditLog::where('organization_id', $organizationId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->selectRaw('user_name, user_email, count(*) as count')
                    ->groupBy('user_name', 'user_email')
                    ->orderBy('count', 'desc')
                    ->limit(10)
                    ->get(),
                'daily_activity' => AuditLog::where('organization_id', $organizationId)
                    ->whereBetween('created_at', [$startDate, $endDate])
                    ->selectRaw('DATE(created_at) as date, count(*) as count')
                    ->groupBy('date')
                    ->orderBy('date', 'desc')
                    ->limit(30)
                    ->get()
            ];
            
            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch stats'
            ], 500);
        }
    }
}