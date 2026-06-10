<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Organization;
use App\Models\SubscriptionPlan;
use App\Models\User;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SuperAdminController extends Controller
{
    public function getStats(Request $request)
    {
        try {
            $orgHasStatus  = Schema::hasColumn('organizations', 'status');
            $planHasStatus = Schema::hasColumn('subscription_plans', 'status');
            $auditHasOrg   = Schema::hasColumn('audit_logs', 'organization_id');
            $auditHasDesc  = Schema::hasColumn('audit_logs', 'description');

            $stats = [
                'totalOrganizations'     => Organization::count(),
                'activeOrganizations'    => $orgHasStatus ? Organization::where('status', 'active')->count() : 0,
                'suspendedOrganizations' => $orgHasStatus ? Organization::where('status', 'suspended')->count() : 0,
                'totalSubscriptions'     => SubscriptionPlan::count(),
                'activeSubscriptions'    => $planHasStatus ? SubscriptionPlan::where('status', 'active')->count() : 0,
                'totalUsers'             => User::count(),
                'recentActivities'       => [],
            ];

            $auditQuery = AuditLog::with('user');
            if ($auditHasOrg) {
                $auditQuery->with('organization');
            }

            $stats['recentActivities'] = $auditQuery
                ->latest()
                ->limit(10)
                ->get()
                ->map(function ($log) use ($auditHasDesc, $auditHasOrg) {
                    $userName = null;
                    if ($log->user) {
                        $userName = trim(($log->user->first_name ?? '') . ' ' . ($log->user->last_name ?? ''));
                        if ($userName === '') {
                            $userName = $log->user->email ?? null;
                        }
                    }

                    return [
                        'id'                => $log->id,
                        'type'              => $log->action,
                        'description'       => $auditHasDesc ? $log->description : null,
                        'created_at'        => $log->created_at,
                        'user_name'         => $userName,
                        'organization_name' => $auditHasOrg && $log->relationLoaded('organization') && $log->organization
                            ? $log->organization->name
                            : null,
                    ];
                });

            return response()->json([
                'success' => true,
                'data'    => $stats,
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdminController@getStats error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch dashboard stats',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}
