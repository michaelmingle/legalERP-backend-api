<?php

namespace App\Http\Controllers\Api\V1\SuperAdmin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\SubscriptionPlan;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class SubscriptionController extends Controller
{
    public function index(Request $request)
    {
        try {
            $hasOrgId       = Schema::hasColumn('subscription_plans', 'organization_id');
            $hasPlanName    = Schema::hasColumn('subscription_plans', 'plan_name');
            $hasAmount      = Schema::hasColumn('subscription_plans', 'amount');
            $hasPricing     = Schema::hasColumn('subscription_plans', 'pricing');
            $hasName        = Schema::hasColumn('subscription_plans', 'name');
            $hasBillingCyc  = Schema::hasColumn('subscription_plans', 'billing_cycle');
            $hasStatus      = Schema::hasColumn('subscription_plans', 'status');
            $hasStartDate   = Schema::hasColumn('subscription_plans', 'start_date');
            $hasEndDate     = Schema::hasColumn('subscription_plans', 'end_date');

            $query = SubscriptionPlan::query();
            if ($hasOrgId) {
                $query->with('organization');
            }

            if ($hasStatus && $request->filled('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            if ($hasOrgId && $request->filled('search')) {
                $query->whereHas('organization', function ($q) use ($request) {
                    $q->where('name', 'like', '%' . $request->search . '%');
                });
            } elseif ($hasName && $request->filled('search')) {
                $query->where('name', 'like', '%' . $request->search . '%');
            }

            $subscriptions = $query->latest()->paginate(20);

            $subscriptions->getCollection()->transform(function ($sub) use (
                $hasOrgId, $hasPlanName, $hasAmount, $hasPricing, $hasName,
                $hasBillingCyc, $hasStatus, $hasStartDate, $hasEndDate
            ) {
                $orgName = null;
                if ($hasOrgId && $sub->relationLoaded('organization') && $sub->organization) {
                    $orgName = $sub->organization->name;
                }

                $planName = $hasPlanName && $sub->plan_name
                    ? $sub->plan_name
                    : ($hasName ? $sub->name : null);

                $amount = $hasAmount && $sub->amount !== null
                    ? $sub->amount
                    : ($hasPricing ? $sub->pricing : 0);

                return [
                    'id'                => $sub->id,
                    'organization_name' => $orgName,
                    'organization_id'   => $hasOrgId ? $sub->organization_id : null,
                    'plan_name'         => $planName,
                    'amount'            => $amount,
                    'billing_cycle'     => $hasBillingCyc ? $sub->billing_cycle : 'monthly',
                    'status'            => $hasStatus ? ($sub->status ?? 'active') : 'active',
                    'start_date'        => $hasStartDate ? $sub->start_date : null,
                    'end_date'          => $hasEndDate ? $sub->end_date : null,
                    'days_remaining'    => $hasEndDate && $sub->end_date
                        ? now()->diffInDays($sub->end_date, false)
                        : null,
                    'created_at'        => $sub->created_at,
                ];
            });

            return response()->json([
                'success' => true,
                'data'    => $subscriptions,
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin SubscriptionController@index error', [
                'message' => $e->getMessage(),
                'trace'   => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch subscriptions',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function renew($id)
    {
        try {
            $subscription = SubscriptionPlan::findOrFail($id);

            if (Schema::hasColumn('subscription_plans', 'end_date')) {
                $cycle = Schema::hasColumn('subscription_plans', 'billing_cycle')
                    ? ($subscription->billing_cycle ?? 'monthly')
                    : 'monthly';

                $extension = match ($cycle) {
                    'monthly' => 30,
                    'quarterly' => 90,
                    'yearly' => 365,
                    default => 30,
                };

                $subscription->end_date = now()->addDays($extension);
            }

            if (Schema::hasColumn('subscription_plans', 'status')) {
                $subscription->status = 'active';
            }

            $subscription->save();

            return response()->json([
                'success' => true,
                'message' => 'Subscription renewed successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin SubscriptionController@renew error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to renew subscription',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }

    public function cancel($id)
    {
        try {
            $subscription = SubscriptionPlan::findOrFail($id);

            if (Schema::hasColumn('subscription_plans', 'status')) {
                $subscription->status = 'cancelled';
            }

            $subscription->save();

            return response()->json([
                'success' => true,
                'message' => 'Subscription cancelled successfully',
            ]);
        } catch (\Throwable $e) {
            Log::error('SuperAdmin SubscriptionController@cancel error', [
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Failed to cancel subscription',
                'error'   => $e->getMessage(),
            ], 500);
        }
    }
}