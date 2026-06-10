<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class OrganizationController extends Controller
{
    public function index(Request $request)
    {
        try {
            $hasStatus      = Schema::hasColumn('organizations', 'status');
            $hasIndustry    = Schema::hasColumn('organizations', 'industry');
            $hasOrgEmail    = Schema::hasColumn('organizations', 'organisation_email');
            $hasEmail       = Schema::hasColumn('organizations', 'email');
            $userHasStatus  = Schema::hasColumn('users', 'status');

            $query = Organization::query();

            if ($hasStatus && $request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search, $hasOrgEmail, $hasEmail) {
                    $q->where('name', 'like', "%{$search}%");
                    if ($hasOrgEmail) {
                        $q->orWhere('organisation_email', 'like', "%{$search}%");
                    }
                    if ($hasEmail) {
                        $q->orWhere('email', 'like', "%{$search}%");
                    }
                });
            }

            $organizations = $query->latest()->get();

            $data = $organizations->map(function ($org) use ($hasStatus, $hasIndustry, $hasOrgEmail, $hasEmail) {
                $admin = User::where('organization_id', $org->id)
                    ->whereIn('role', ['admin', 'owner'])
                    ->first();

                $userCount = User::where('organization_id', $org->id)->count();

                $email = null;
                if ($hasOrgEmail) {
                    $email = $org->organisation_email;
                }
                if (!$email && $hasEmail) {
                    $email = $org->email;
                }

                return [
                    'id'         => $org->id,
                    'name'       => $org->name,
                    'email'      => $email,
                    'admin_name' => $admin ? trim(($admin->first_name ?? '') . ' ' . ($admin->last_name ?? '')) : null,
                    'industry'   => $hasIndustry ? $org->industry : null,
                    'status'     => $hasStatus ? ($org->status ?? 'pending') : 'pending',
                    'user_count' => $userCount,
                    'created_at' => $org->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin OrganizationController@index error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch organizations',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function show($id)
    {
        try {
            $hasStatus       = Schema::hasColumn('organizations', 'status');
            $hasIndustry     = Schema::hasColumn('organizations', 'industry');
            $hasDescription  = Schema::hasColumn('organizations', 'description');
            $hasOrgEmail     = Schema::hasColumn('organizations', 'organisation_email');
            $hasEmail        = Schema::hasColumn('organizations', 'email');
            $hasSubPlan      = Schema::hasColumn('organizations', 'subscription_plan');
            $userHasStatus   = Schema::hasColumn('users', 'status');

            $org = Organization::findOrFail($id);

            $admin = User::where('organization_id', $org->id)
                ->whereIn('role', ['admin', 'owner'])
                ->first();

            $userCount  = User::where('organization_id', $org->id)->count();
            $activeUsers = $userHasStatus
                ? User::where('organization_id', $org->id)->where('status', 'active')->count()
                : $userCount;

            $email = $hasOrgEmail ? $org->organisation_email : null;
            if (!$email && $hasEmail) {
                $email = $org->email;
            }

            $data = [
                'id'                => $org->id,
                'name'              => $org->name,
                'email'             => $email,
                'description'       => $hasDescription ? $org->description : null,
                'industry'          => $hasIndustry ? $org->industry : null,
                'status'            => $hasStatus ? ($org->status ?? 'pending') : 'pending',
                'created_at'        => $org->created_at,
                'admin_name'        => $admin ? trim(($admin->first_name ?? '') . ' ' . ($admin->last_name ?? '')) : null,
                'user_count'        => $userCount,
                'active_users'      => $activeUsers,
                'case_count'        => 0,
                'subscription_plan' => $hasSubPlan ? $org->subscription_plan : null,
            ];

            return response()->json([
                'success' => true,
                'data'    => $data,
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin OrganizationController@show error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function updateStatus(Request $request, $id)
    {
        try {
            $request->validate(['status' => 'required|in:active,suspended,pending,trial,inactive']);

            $org = Organization::findOrFail($id);
            $org->status = $request->status;
            $org->save();

            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin OrganizationController@updateStatus error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function destroy($id)
    {
        try {
            $org = Organization::findOrFail($id);
            $org->delete();

            return response()->json([
                'success' => true,
                'message' => 'Organization deleted successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin OrganizationController@destroy error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    public function list()
    {
        try {
            $organizations = Organization::select('id', 'name')->get();

            return response()->json([
                'success' => true,
                'data'    => $organizations,
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin OrganizationController@list error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }
}