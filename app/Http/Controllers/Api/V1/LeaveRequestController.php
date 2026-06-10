<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Employee;
use App\Models\LeaveRequest;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;

class LeaveRequestController extends Controller
{
    /**
     * Get leave requests for the authenticated employee (filtered by organization)
     */
    public function myLeaves(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Get the employee record for this user within the organization
            $employee = Employee::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();
            
            if (!$employee) {
                // Not every user (eg. lawyers) has an Employee record. Treat as "no leaves".
                return response()->json([
                    'success' => true,
                    'data'    => [],
                    'message' => 'No employee profile for current user',
                ], 200);
            }

            $query = LeaveRequest::where('employee_id', $employee->id)
                ->whereHas('employee', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->with(['employee.user', 'approver'])
                ->orderBy('created_at', 'desc');
            
            // Apply filters
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            if ($request->has('from_date')) {
                $query->where('start_date', '>=', $request->from_date);
            }
            
            if ($request->has('to_date')) {
                $query->where('end_date', '<=', $request->to_date);
            }
            
            $leaves = $query->get();
            
            return response()->json([
                'success' => true,
                'data' => $leaves
            ], 200);
            
        } catch (\Exception $e) {
            Log::error('Error fetching my leaves: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to fetch leave requests'
            ], 500);
        }
    }

    /**
     * Display a listing of leave requests (filtered by organization)
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only HR, admin, and owner can view all leave requests
            if (!in_array($user->role, ['hr', 'admin', 'owner'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $query = LeaveRequest::whereHas('employee', function($q) use ($organizationId) {
                    $q->where(function ($w) use ($organizationId) {
                        $w->where('organization_id', $organizationId)
                          ->orWhereNull('organization_id'); // legacy rows
                    });
                })
                ->with(['employee.user', 'approver']);

            if ($request->has('status')) {
                $query->where('status', $request->status);
            }

            if ($request->has('search')) {
                $search = $request->search;
                $query->whereHas('employee.user', function ($q) use ($search) {
                    $q->where('first_name', 'like', "%{$search}%")
                      ->orWhere('last_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }

            if ($request->has('from_date')) {
                $query->where('start_date', '>=', $request->from_date);
            }
            if ($request->has('to_date')) {
                $query->where('end_date', '<=', $request->to_date);
            }

            $leaves = $query->latest('applied_at')->paginate(15);

            $leaves->getCollection()->transform(function ($leave) {
                $employee = $leave->employee;
                $user = $employee ? $employee->user : null;

                return [
                    'id'         => $leave->id,
                    'name'       => $user
                        ? trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? ''))
                        : ($employee?->full_name ?? 'Unknown'),
                    'email'      => $user?->email,
                    'role'       => $user?->role ?? $employee?->job_title,
                    'department' => $employee?->department,
                    'avatar'     => $user?->photo_url
                        ?? ($employee?->photo ? asset('storage/' . $employee->photo) : null),
                    'type'       => $leave->leave_type,
                    'start_date' => optional($leave->start_date)->toDateString(),
                    'end_date'   => optional($leave->end_date)->toDateString(),
                    'date'       => ($leave->start_date ? $leave->start_date->format('d/m/Y') : '?')
                                  . ' – ' . ($leave->end_date ? $leave->end_date->format('d/m/Y') : '?'),
                    'status'     => ucfirst($leave->status ?? 'pending'),
                    'days'       => $leave->total_days,
                    'reason'     => $leave->reason ?? null,
                ];
            });

            return response()->json($leaves);
            
        } catch (\Exception $e) {
            Log::error('Error fetching leave requests: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch leave requests'], 500);
        }
    }

    /**
     * Store a newly created leave request.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $validated = $request->validate([
                'leave_type'    => 'required|string|max:255',
                'start_date'    => 'required|date|after_or_equal:today',
                'end_date'      => 'required|date|after_or_equal:start_date',
                'reason'        => 'nullable|string',
            ]);

            DB::beginTransaction();
            
            // Get the authenticated user's employee record within the organization
            $employee = Employee::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();
            
            if (!$employee) {
                return response()->json(['error' => 'Employee record not found'], 404);
            }
            
            $leave = LeaveRequest::create([
                'employee_id' => $employee->id,
                'leave_type'  => $validated['leave_type'],
                'start_date'  => $validated['start_date'],
                'end_date'    => $validated['end_date'],
                'reason'      => $validated['reason'] ?? null,
                'applied_at'  => now(),
                'status'      => 'pending',
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Leave request submitted successfully',
                'leave'   => $leave->load('employee.user', 'approver')
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Leave creation failed: ' . $e->getMessage());
            return response()->json(['error' => 'Submission failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified leave request (filtered by organization)
     */
    public function show(LeaveRequest $leaveRequest)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify leave request belongs to organization
            if (!$leaveRequest->employee || $leaveRequest->employee->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            return response()->json($leaveRequest->load('employee.user', 'approver'));
        } catch (\Exception $e) {
            Log::error('Error fetching leave request: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch leave request'], 500);
        }
    }

    /**
     * Update the specified leave request (approve/decline or edit)
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $leaveRequest = LeaveRequest::whereHas('employee', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->findOrFail($id);
            
            $action = $request->input('action'); // 'approve', 'decline', 'edit'

            if (in_array($action, ['approve', 'decline'])) {
                // Only HR, admin, and owner can approve/decline
                if (!in_array($user->role, ['hr', 'admin', 'owner'])) {
                    return response()->json(['error' => 'Unauthorized to approve/decline leave requests'], 403);
                }

                $leaveRequest = $leaveRequest->fresh();

                if (trim(strtolower($leaveRequest->status)) !== 'pending') {
                    return response()->json([
                        'error' => 'Only pending requests can be approved or declined',
                        'current_status' => $leaveRequest->status
                    ], 422);
                }

                $newStatus = $action === 'approve' ? 'approved' : 'declined';

                DB::beginTransaction();
                try {
                    $leaveRequest->update([
                        'status'      => $newStatus,
                        'approved_by' => $user->id,
                        'approved_at' => now(),
                    ]);

                    // If approved, update the employee's status to "On Leave"
                    if ($action === 'approve') {
                        $employee = $leaveRequest->employee;
                        if ($employee) {
                            $employee->update(['status' => 'On Leave']);
                        }
                    } else {
                        // If declined, make sure employee status is active
                        $employee = $leaveRequest->employee;
                        if ($employee && $employee->status === 'On Leave') {
                            $employee->update(['status' => 'active']);
                        }
                    }

                    DB::commit();

                    return response()->json([
                        'message' => "Leave request {$newStatus} successfully",
                        'leave'   => $leaveRequest->fresh()->load('employee.user', 'approver')
                    ]);

                } catch (\Exception $e) {
                    DB::rollBack();
                    Log::error('Leave update failed: ' . $e->getMessage());
                    return response()->json(['error' => 'Action failed'], 500);
                }
            }

            // Edit pending request (only the employee who submitted can edit)
            if ($action === 'edit') {
                // Check if user is the one who submitted the request
                $employee = Employee::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$employee || $leaveRequest->employee_id !== $employee->id) {
                    return response()->json(['error' => 'Unauthorized to edit this leave request'], 403);
                }

                $leaveRequest = $leaveRequest->fresh();

                if ($leaveRequest->status !== 'pending') {
                    return response()->json(['error' => 'Only pending requests can be edited'], 422);
                }

                $validated = $request->validate([
                    'leave_type' => 'sometimes|string|max:255',
                    'start_date' => 'sometimes|date|after_or_equal:today',
                    'end_date'   => 'sometimes|date|after_or_equal:start_date',
                    'reason'     => 'nullable|string',
                ]);

                $leaveRequest->update($validated);

                return response()->json([
                    'message' => 'Leave request updated',
                    'leave'   => $leaveRequest->fresh()
                ]);
            }

            return response()->json(['error' => 'Invalid action'], 400);
            
        } catch (\Exception $e) {
            Log::error('Leave update failed: ' . $e->getMessage());
            return response()->json(['error' => 'Action failed: ' . $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified leave request (soft delete)
     */
    public function destroy(LeaveRequest $leaveRequest)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify leave request belongs to organization
            if (!$leaveRequest->employee || $leaveRequest->employee->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            // Check if user has permission to delete
            $hasPermission = false;
            
            if (in_array($user->role, ['hr', 'admin', 'owner'])) {
                $hasPermission = true;
            } else {
                $employee = Employee::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                if ($employee && $leaveRequest->employee_id === $employee->id) {
                    $hasPermission = true;
                }
            }
            
            if (!$hasPermission) {
                return response()->json(['error' => 'Unauthorized to delete this leave request'], 403);
            }
            
            if ($leaveRequest->status === 'approved') {
                return response()->json(['error' => 'Approved leaves cannot be deleted'], 403);
            }

            $leaveRequest->delete();

            return response()->json(['message' => 'Leave request deleted successfully']);
            
        } catch (\Exception $e) {
            Log::error('Leave deletion failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete leave request'], 500);
        }
    }
    
    /**
     * Get leave balance for the authenticated employee
     */
    public function myLeaveBalance()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $employee = Employee::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$employee) {
                // Lawyers/owners may not have an Employee profile. Return zero-balance instead of 404.
                return response()->json([
                    'success' => true,
                    'data'    => [
                        'employee' => null,
                        'balance'  => [
                            'annual' => ['total' => 0, 'taken' => 0, 'remaining' => 0],
                            'sick'   => ['total' => 0, 'taken' => 0, 'remaining' => 0],
                            'casual' => ['total' => 0, 'taken' => 0, 'remaining' => 0],
                        ],
                        'leave_history' => [],
                    ],
                    'message' => 'No employee profile for current user',
                ], 200);
            }

            // Get approved leaves for the current year
            $currentYear = now()->year;
            $approvedLeaves = LeaveRequest::where('employee_id', $employee->id)
                ->where('status', 'approved')
                ->whereYear('start_date', $currentYear)
                ->get();
                
            $totalDaysTaken = $approvedLeaves->sum('total_days');
            
            // You can customize these leave limits based on company policy
            $leaveBalance = [
                'annual' => [
                    'total' => 20,
                    'taken' => min($totalDaysTaken, 20),
                    'remaining' => max(0, 20 - $totalDaysTaken),
                ],
                'sick' => [
                    'total' => 10,
                    'taken' => 0, // Track sick leaves separately if needed
                    'remaining' => 10,
                ],
                'casual' => [
                    'total' => 5,
                    'taken' => 0,
                    'remaining' => 5,
                ],
            ];
            
            return response()->json([
                'success' => true,
                'data' => [
                    'employee' => [
                        'id' => $employee->id,
                        'name' => $user->first_name . ' ' . $user->last_name,
                        'email' => $user->email,
                    ],
                    'balance' => $leaveBalance,
                    'leave_history' => $approvedLeaves,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching leave balance: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch leave balance'], 500);
        }
    }
}