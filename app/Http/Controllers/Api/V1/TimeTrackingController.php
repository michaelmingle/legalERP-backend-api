<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TimeTracking;
use App\Models\Cases;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class TimeTrackingController extends Controller
{
    // app/Http/Controllers/Api/V1/TimeTrackingController.php

    /**
     * Display a listing of the resource.
     * GET /api/time-trackings
     */
    public function index()
    {
        $user = Auth::user();

        // Admin or Owner can see ALL time tracking entries
        if ($user->role === 'admin' || $user->role === 'owner') {
            $timeTrackings = TimeTracking::with([
                'case:id,case_name,case_number,client_id,assigned_to',
                'case.client:id,full_name,email',
                'user:id,first_name,last_name,email,role'
            ])
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $timeTrackings
            ]);
        }

        // Client - see ALL time entries for their cases
        if ($user->role === 'client') {
            // Get the client record associated with this user
            $client = \App\Models\Client::where('user_id', $user->id)->first();

            if (!$client) {
                return response()->json([
                    'success' => true,
                    'data' => [],
                    'message' => 'No client profile found for this user'
                ]);
            }

            // Get cases where client_id matches the client's ID (not user_id)
            $clientCases = Cases::where('client_id', $client->id)->pluck('id');

            Log::info('Client User ID: ' . $user->id);
            Log::info('Client Record ID: ' . $client->id);
            Log::info('Client Cases IDs: ' . json_encode($clientCases));

            // Get ALL time entries for these cases
            $timeTrackings = TimeTracking::whereIn('case_id', $clientCases)
                ->with([
                    'case:id,case_name,case_number,client_id',
                    'user:id,first_name,last_name,role'
                ])
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $timeTrackings,
                'debug' => [
                    'user_id' => $user->id,
                    'client_id' => $client->id,
                    'case_ids' => $clientCases,
                    'time_entries_count' => $timeTrackings->count()
                ]
            ]);
        }

        // Lawyer or Employee - see ALL time entries for cases assigned to them
        if ($user->role === 'lawyer' || $user->role === 'employee') {
            $assignedCases = Cases::where('assigned_to', $user->id)->pluck('id');

            $timeTrackings = TimeTracking::whereIn('case_id', $assignedCases)
                ->with([
                    'case:id,case_name,case_number,client_id',
                    'case.client:id,full_name',
                    'user:id,first_name,last_name'
                ])
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $timeTrackings
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 403);
    }

    /**
     * Get time tracking for client (their cases) with fresh data
     * GET /api/client/time-trackings
     */
    public function getTimeTracking()
    {
        $user = Auth::user();

        if ($user->role === 'client') {
            $clientCases = Cases::where('client_id', $user->id)->pluck('id');

            $timeTrackings = TimeTracking::whereIn('case_id', $clientCases)
                ->with([
                    'case:id,case_name,case_number',
                    'user:id,first_name,last_name'
                ])
                ->orderBy('date', 'desc')
                ->orderBy('created_at', 'desc')
                ->get();

            return response()->json([
                'success' => true,
                'data' => $timeTrackings,
                'cache_control' => 'no-cache, no-store, must-revalidate',
                'message' => 'Time tracking entries retrieved successfully'
            ]);
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 403);
    }

    /**
     * Get the latest case stage for a specific case (for clients)
     * GET /api/cases/{caseId}/latest-stage
     */
    public function getLatestCaseStage($caseId)
    {
        $user = Auth::user();
        $case = Cases::findOrFail($caseId);

        // Check if client has access to this case
        if ($user->role === 'client' && $case->client_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to view this case'
            ], 403);
        }

        // Get the latest time entry for this case
        $latestTimeEntry = TimeTracking::where('case_id', $caseId)
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        // Stage progress mapping
        $stageProgress = [
            'initial_opening' => 10,
            'case_assessment' => 20,
            'evidence_gathering' => 35,
            'legal_research' => 45,
            'initial_filing' => 55,
            'discovery' => 65,
            'motions_practice' => 75,
            'pre_trial' => 85,
            'trial' => 95,
            'resolution' => 100
        ];

        $currentStage = $latestTimeEntry ? $latestTimeEntry->case_stage : 'initial_opening';
        $progressPercentage = $stageProgress[$currentStage] ?? 10;

        // Get all time entries for summary
        $allTimeEntries = TimeTracking::where('case_id', $caseId)->get();
        $totalHours = $allTimeEntries->sum('hours');
        $totalEntries = $allTimeEntries->count();

        return response()->json([
            'success' => true,
            'data' => [
                'case_id' => $caseId,
                'case_name' => $case->case_name,
                'case_number' => $case->case_number,
                'current_stage' => $currentStage,
                'progress_percentage' => $progressPercentage,
                'total_hours' => $totalHours,
                'total_entries' => $totalEntries,
                'last_activity_date' => $latestTimeEntry ? $latestTimeEntry->date : null,
                'latest_entry' => $latestTimeEntry
            ],
            'message' => 'Case stage retrieved successfully'
        ]);
    }

    /**
     * Store a newly created resource in storage.
     * POST /api/time-trackings
     */
    public function store(Request $request)
    {
        $user = Auth::user();

        $request->validate([
            'case_id' => 'required|exists:cases,id',
            'case_stage' => 'nullable|string',
            'description' => 'required|string',
            'hours' => 'required|numeric|min:0',
            'date' => 'required|date',
            'status' => 'nullable|in:billed,unbilled,approved,rejected'
        ]);

        $case = Cases::find($request->case_id);

        // Clients cannot log time
        if ($user->role === 'client') {
            return response()->json([
                'success' => false,
                'message' => 'Clients cannot log time entries'
            ], 403);
        }

        $timeTracking = TimeTracking::create([
            'case_id' => $request->case_id,
            'user_id' => $user->id,
            'case_stage' => $request->case_stage,
            'description' => $request->description,
            'hours' => $request->hours,
            'date' => $request->date,
            'status' => $request->status ?? 'unbilled'
        ]);

        $timeTracking->load('case', 'user');

        return response()->json([
            'success' => true,
            'data' => $timeTracking,
            'message' => 'Time tracking entry created successfully'
        ], 201);
    }

    /**
     * Display the specified resource.
     * GET /api/time-trackings/{id}
     */
    public function show($id)
    {
        $user = Auth::user();
        $timeTracking = TimeTracking::with('case', 'user')->findOrFail($id);

        // Admin can view any
        if ($user->role === 'admin' || $user->role === 'owner') {
            return response()->json([
                'success' => true,
                'data' => $timeTracking,
                'message' => 'Time tracking entry retrieved successfully'
            ]);
        }

        // User can view their own
        if (($user->role === 'lawyer' || $user->role === 'employee') && $timeTracking->user_id === $user->id) {
            return response()->json([
                'success' => true,
                'data' => $timeTracking,
                'message' => 'Time tracking entry retrieved successfully'
            ]);
        }

        // User can view if they are assigned to the case
        if (($user->role === 'lawyer' || $user->role === 'employee')) {
            $case = Cases::find($timeTracking->case_id);
            if ($case && $case->assigned_to === $user->id) {
                return response()->json([
                    'success' => true,
                    'data' => $timeTracking,
                    'message' => 'Time tracking entry retrieved successfully'
                ]);
            }
        }

        // Client can view if it's their case
        if ($user->role === 'client') {
            $case = Cases::find($timeTracking->case_id);
            if ($case && $case->client_id === $user->id) {
                return response()->json([
                    'success' => true,
                    'data' => $timeTracking,
                    'message' => 'Time tracking entry retrieved successfully'
                ]);
            }
        }

        return response()->json([
            'success' => false,
            'message' => 'Unauthorized'
        ], 403);
    }

    /**
     * Update the specified resource in storage.
     * PUT/PATCH /api/time-trackings/{id}
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $timeTracking = TimeTracking::findOrFail($id);

        if ($user->role !== 'admin' && $user->role !== 'owner' && $timeTracking->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to update this time entry'
            ], 403);
        }

        $request->validate([
            'case_id' => 'required|exists:cases,id',
            'case_stage' => 'nullable|string',
            'description' => 'nullable|string',
            'hours' => 'required|numeric|min:0',
            'date' => 'required|date',
            'status' => 'required|string|in:billed,unbilled,approved,rejected'
        ]);

        $timeTracking->update($request->all());

        return response()->json([
            'success' => true,
            'message' => 'Time tracking updated successfully',
            'data' => $timeTracking
        ]);
    }

    /**
     * Remove the specified resource from storage.
     * DELETE /api/time-trackings/{id}
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $timeTracking = TimeTracking::findOrFail($id);

        if ($user->role !== 'admin' && $user->role !== 'owner' && $timeTracking->user_id !== $user->id) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized to delete this time entry'
            ], 403);
        }

        $timeTracking->delete();

        return response()->json([
            'success' => true,
            'message' => 'Time tracking deleted successfully'
        ]);
    }

    /**
     * Get time tracking summary for a specific case
     * GET /api/cases/{caseId}/time-summary
     */
    public function getCaseTimeSummary($caseId)
    {
        $user = Auth::user();
        $case = Cases::findOrFail($caseId);

        // Check permissions
        $hasAccess = false;

        if ($user->role === 'admin' || $user->role === 'owner') {
            $hasAccess = true;
        } elseif (($user->role === 'lawyer' || $user->role === 'employee') && $case->assigned_to === $user->id) {
            $hasAccess = true;
        } elseif ($user->role === 'client') {
            // Get the client record for this user
            $client = \App\Models\Client::where('user_id', $user->id)->first();
            if ($client && $case->client_id === $client->id) {
                $hasAccess = true;
            }
        }

        if (!$hasAccess) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized'
            ], 403);
        }

        $timeEntries = TimeTracking::where('case_id', $caseId)
            ->orderBy('date', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        $latestEntry = $timeEntries->first();
        $currentStage = $latestEntry ? $latestEntry->case_stage : 'initial_opening';

        $stageProgress = [
            'initial_opening' => 10,
            'case_assessment' => 20,
            'evidence_gathering' => 35,
            'legal_research' => 45,
            'initial_filing' => 55,
            'discovery' => 65,
            'motions_practice' => 75,
            'pre_trial' => 85,
            'trial' => 95,
            'resolution' => 100
        ];

        return response()->json([
            'success' => true,
            'data' => [
                'case_id' => $caseId,
                'case_name' => $case->case_name,
                'total_hours' => $timeEntries->sum('hours'),
                'entry_count' => $timeEntries->count(),
                'current_stage' => $currentStage,
                'progress_percentage' => $stageProgress[$currentStage] ?? 10,
                'entries' => $timeEntries
            ]
        ]);
    }
    
}
