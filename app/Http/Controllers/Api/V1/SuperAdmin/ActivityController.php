<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\AuditLog;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class ActivityController extends Controller
{
    public function index(Request $request)
    {
        try {
            $hasOrgId = Schema::hasColumn('audit_logs', 'organization_id');
            $hasDesc  = Schema::hasColumn('audit_logs', 'description');

            $query = AuditLog::with('user');
            if ($hasOrgId) {
                $query->with('organization');
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search, $hasDesc) {
                    $q->whereHas('user', function ($user) use ($search) {
                        $user->where('first_name', 'like', "%{$search}%")
                            ->orWhere('last_name', 'like', "%{$search}%")
                            ->orWhere('email', 'like', "%{$search}%");
                    });
                    if ($hasDesc) {
                        $q->orWhere('description', 'like', "%{$search}%");
                    }
                });
            }

            if ($request->filled('action_type')) {
                $query->where('action', $request->action_type);
            }

            if ($hasOrgId && $request->filled('organization_id')) {
                $query->where('organization_id', $request->organization_id);
            }

            if ($request->filled('start_date')) {
                $query->whereDate('created_at', '>=', $request->start_date);
            }

            if ($request->filled('end_date')) {
                $query->whereDate('created_at', '<=', $request->end_date);
            }

            $activities = $query->latest()->paginate(50);

            $activities->getCollection()->transform(function ($activity) use ($hasOrgId, $hasDesc) {
                $userName = 'System';
                if ($activity->user) {
                    $userName = trim(($activity->user->first_name ?? '') . ' ' . ($activity->user->last_name ?? ''));
                    if ($userName === '') {
                        $userName = $activity->user->email ?? 'System';
                    }
                }

                return [
                    'id'                => $activity->id,
                    'user_name'         => $userName,
                    'user_email'        => $activity->user?->email,
                    'action_type'       => $activity->action,
                    'description'       => $hasDesc ? $activity->description : null,
                    'ip_address'        => $activity->ip_address,
                    'organization_name' => $hasOrgId && $activity->relationLoaded('organization') && $activity->organization
                        ? $activity->organization->name
                        : null,
                    'created_at'        => $activity->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $activities,
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin ActivityController@index error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch activities',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function export(Request $request)
    {
        try {
            $hasOrgId = Schema::hasColumn('audit_logs', 'organization_id');
            $hasDesc  = Schema::hasColumn('audit_logs', 'description');

            $query = AuditLog::with('user');
            if ($hasOrgId) {
                $query->with('organization');
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->whereHas('user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                        ->orWhere('last_name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            }

            $activities = $query->latest()->get();

            $csvData = [];
            $csvData[] = ['User', 'Email', 'Action', 'Description', 'Organization', 'IP Address', 'Timestamp'];

            foreach ($activities as $activity) {
                $userName = 'System';
                if ($activity->user) {
                    $userName = trim(($activity->user->first_name ?? '') . ' ' . ($activity->user->last_name ?? ''));
                    if ($userName === '') {
                        $userName = $activity->user->email ?? 'System';
                    }
                }

                $csvData[] = [
                    $userName,
                    $activity->user?->email ?? '',
                    $activity->action,
                    $hasDesc ? ($activity->description ?? '') : '',
                    $hasOrgId && $activity->relationLoaded('organization') && $activity->organization
                        ? $activity->organization->name
                        : '',
                    $activity->ip_address,
                    optional($activity->created_at)->toDateTimeString() ?? '',
                ];
            }

            $filename = 'user-activities-' . now()->format('Y-m-d-His') . '.csv';

            return response()->streamDownload(function () use ($csvData) {
                $file = fopen('php://output', 'w');
                foreach ($csvData as $row) {
                    fputcsv($file, $row);
                }
                fclose($file);
            }, $filename, [
                'Content-Type' => 'text/csv',
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin ActivityController@export error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to export activities',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}