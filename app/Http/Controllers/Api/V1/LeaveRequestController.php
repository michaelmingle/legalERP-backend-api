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
     * Get leave requests for the authenticated employee.
     */
    public function myLeaves(Request $request)
    {
        try {
            $user = Auth::user();
            
            // Get the employee record for this user
            $employee = Employee::where('user_id', $user->id)->first();
            
            if (!$employee) {
                return response()->json([
                    'success' => false,
                    'message' => 'Employee record not found'
                ], 404);
            }
            
            $query = LeaveRequest::where('employee_id', $employee->id)
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
     * Display a listing of leave requests.
     */
    public function index(Request $request)
    {
        $query = LeaveRequest::with('employee.user');

        if ($request->has('status')) {
            $query->where('status', $request->status);
        }

        if ($request->has('search')) {
            $search = $request->search;
            $query->whereHas('employee.user', function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%");
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
            return [
                'id'     => $leave->id,
                'name'   => $leave->employee->full_name ?? $leave->employee->user->name,
                'avatar' => $leave->employee->photo ? asset('storage/' . $leave->employee->photo) : null,
                'type'   => $leave->leave_type,
                'date'   => $leave->start_date->format('d/m/Y') . ' – ' . $leave->end_date->format('d/m/y'),
                'status' => ucfirst($leave->status),
                'days'   => $leave->total_days,
            ];
        });

        return response()->json($leaves);
    }

    /**
     * Store a newly created leave request.
     */
    public function store(Request $request)
{
    $validated = $request->validate([
        'leave_type'    => 'required|string|max:255',
        'start_date'    => 'required|date|after_or_equal:today',
        'end_date'      => 'required|date|after_or_equal:start_date',
        'reason'        => 'nullable|string',
    ]);

    DB::beginTransaction();
    try {
        // Get the authenticated user's employee record
        $user = Auth::user();
        $employee = Employee::where('user_id', $user->id)->first();
        
        if (!$employee) {
            return response()->json(['error' => 'Employee record not found'], 404);
        }
        
        $leave = LeaveRequest::create([
            'employee_id' => $employee->id, // Use authenticated employee's ID
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
            'leave'   => $leave->load('employee.user')
        ], 201);

    } catch (\Exception $e) {
        DB::rollBack();
        Log::error('Leave creation failed: ' . $e->getMessage());
        return response()->json(['error' => 'Submission failed'], 500);
    }
}

    /**
     * Display the specified leave request.
     */
    public function show(LeaveRequest $leaveRequest)
    {
        return response()->json($leaveRequest->load('employee.user', 'approver'));
    }

    /**
     * Update the specified leave request (approve/decline or edit).
     */
public function update(Request $request, $id)
{
    $leaveRequest = LeaveRequest::findOrFail($id);
    $action = $request->input('action'); // 'approve', 'decline', 'edit'

    if (in_array($action, ['approve', 'decline'])) {
        $user = Auth::user();
        if (!$user) {
            return response()->json(['error' => 'Unauthenticated'], 401);
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

    // Edit pending request
    if ($action === 'edit') {
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
}

    /**
     * Remove the specified leave request (soft delete).
     */
    public function destroy(LeaveRequest $leaveRequest)
    {
        if ($leaveRequest->status === 'approved') {
            return response()->json(['error' => 'Approved leaves cannot be deleted'], 403);
        }

        $leaveRequest->delete();

        return response()->json(['message' => 'Leave request deleted']);
    }
}