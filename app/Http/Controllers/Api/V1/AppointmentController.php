<?php
// app/Http/Controllers/Api/V1/AppointmentController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\Cases;
use App\Models\Client;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class AppointmentController extends Controller
{
    /**
     * Display a listing of the resource (only for current organization)
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            $organizationId = $user->organization_id;
            
            // Start with base query filtered by organization
            $query = Appointment::with(['case', 'lawyer'])
                ->whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                });
            
            // For clients, filter by their specific cases
            if ($user->role === 'client') {
                // Get the client record for this user
                $client = Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                
                if (!$client) {
                    return response()->json([]);
                }
                
                // Get all case IDs for this client within the organization
                $clientCaseIds = Cases::where('client_id', $client->id)
                    ->where('organization_id', $organizationId)
                    ->pluck('id');
                
                $query->whereIn('case_id', $clientCaseIds);
            } 
            // For lawyers/employees, get appointments assigned to them
            elseif (in_array($user->role, ['lawyer', 'employee'])) {
                $query->where('lawyer_id', $user->id);
            }
            
            // Apply date range filter
            if ($request->has('start_date') && $request->has('end_date')) {
                $query->whereBetween('start_time', [$request->start_date, $request->end_date]);
            }
            
            // Apply status filter
            if ($request->has('status')) {
                $query->where('status', $request->status);
            }
            
            $appointments = $query->orderBy('start_time', 'asc')->get();
            
            return response()->json($appointments);
            
        } catch (\Exception $e) {
            Log::error('Error fetching appointments: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Get client cases for the logged-in client (without assignedLawyer relationship)
     */
    public function getClientCases()
    {
        try {
            $user = Auth::user();
            
            if (!$user || $user->role !== 'client') {
                return response()->json(['error' => 'Unauthorized'], 401);
            }
            
            $organizationId = $user->organization_id;
            
            $client = Client::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();
            
            if (!$client) {
                return response()->json([]);
            }
            
            $cases = Cases::where('client_id', $client->id)
                ->where('organization_id', $organizationId)
                ->get();
            
            return response()->json($cases);
            
        } catch (\Exception $e) {
            Log::error('Error fetching client cases: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            $organizationId = $user->organization_id;
            
            $request->validate([
                'case_id' => 'required|exists:cases,id',
                'title' => 'required|string|max:255',
                'purpose' => 'nullable|string|max:255',
                'start_time' => 'required|date',
                'end_time' => 'required|date|after:start_time',
                'location' => 'nullable|string',
                'meeting_link' => 'nullable|url',
                'description' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            // Verify the case belongs to this organization and is assigned to this lawyer
            $case = Cases::where('id', $request->case_id)
                ->where('organization_id', $organizationId)
                ->where('assigned_to', $user->id)
                ->first();
                
            if (!$case) {
                return response()->json(['error' => 'Case not found or you are not assigned to this case'], 403);
            }

            $appointment = Appointment::create([
                'case_id' => $request->case_id,
                'lawyer_id' => $user->id,
                'title' => $request->title,
                'purpose' => $request->purpose,
                'start_time' => $request->start_time,
                'end_time' => $request->end_time,
                'location' => $request->location,
                'meeting_link' => $request->meeting_link,
                'description' => $request->description,
                'notes' => $request->notes,
                'status' => 'scheduled'
            ]);

            return response()->json($appointment, 201);
            
        } catch (\Exception $e) {
            Log::error('Error creating appointment: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            $organizationId = $user->organization_id;
            
            $appointment = Appointment::with(['case', 'lawyer'])
                ->whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->where('id', $id);
            
            // For clients, check via case ownership
            if ($user->role === 'client') {
                $client = Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if ($client) {
                    $clientCaseIds = Cases::where('client_id', $client->id)
                        ->where('organization_id', $organizationId)
                        ->pluck('id');
                    $appointment->whereIn('case_id', $clientCaseIds);
                }
            } 
            // For lawyers/employees, check lawywer_id
            elseif (in_array($user->role, ['lawyer', 'employee'])) {
                $appointment->where('lawyer_id', $user->id);
            }
            
            $appointment = $appointment->first();
                
            if (!$appointment) {
                return response()->json(['error' => 'Appointment not found'], 404);
            }
            
            return response()->json($appointment);
            
        } catch (\Exception $e) {
            Log::error('Error fetching appointment: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            $organizationId = $user->organization_id;
            
            $appointment = Appointment::whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->where('id', $id);
            
            // For clients, check via case ownership
            if ($user->role === 'client') {
                $client = Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if ($client) {
                    $clientCaseIds = Cases::where('client_id', $client->id)
                        ->where('organization_id', $organizationId)
                        ->pluck('id');
                    $appointment->whereIn('case_id', $clientCaseIds);
                }
            } 
            // For lawyers/employees
            elseif (in_array($user->role, ['lawyer', 'employee'])) {
                $appointment->where('lawyer_id', $user->id);
            }
            
            $appointment = $appointment->first();
                
            if (!$appointment) {
                return response()->json(['error' => 'Appointment not found or unauthorized'], 404);
            }

            $request->validate([
                'title' => 'sometimes|string|max:255',
                'purpose' => 'nullable|string|max:255',
                'status' => 'sometimes|in:scheduled,confirmed,completed,cancelled,rescheduled',
                'start_time' => 'sometimes|date',
                'end_time' => 'sometimes|date|after:start_time',
                'location' => 'nullable|string',
                'meeting_link' => 'nullable|url',
                'description' => 'nullable|string',
                'notes' => 'nullable|string',
            ]);

            $appointment->update($request->all());

            return response()->json($appointment);
            
        } catch (\Exception $e) {
            Log::error('Error updating appointment: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            $organizationId = $user->organization_id;
            
            $appointment = Appointment::whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->where('id', $id);
            
            // For clients, check via case ownership
            if ($user->role === 'client') {
                $client = Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if ($client) {
                    $clientCaseIds = Cases::where('client_id', $client->id)
                        ->where('organization_id', $organizationId)
                        ->pluck('id');
                    $appointment->whereIn('case_id', $clientCaseIds);
                }
            } 
            // For lawyers/employees
            elseif (in_array($user->role, ['lawyer', 'employee'])) {
                $appointment->where('lawyer_id', $user->id);
            }
            
            $appointment = $appointment->first();
                
            if (!$appointment) {
                return response()->json(['error' => 'Appointment not found or unauthorized'], 404);
            }
            
            $appointment->delete();

            return response()->json(['message' => 'Appointment deleted successfully']);
            
        } catch (\Exception $e) {
            Log::error('Error deleting appointment: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update appointment status
     */
    public function updateStatus(Request $request, $id)
    {
        try {
            $user = Auth::user();
            
            if (!$user) {
                return response()->json(['error' => 'Unauthenticated'], 401);
            }
            
            $organizationId = $user->organization_id;
            
            $request->validate([
                'status' => 'required|in:scheduled,confirmed,completed,cancelled,rescheduled'
            ]);

            $appointment = Appointment::whereHas('case', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->where('id', $id);
            
            // For clients, check via case ownership
            if ($user->role === 'client') {
                $client = Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if ($client) {
                    $clientCaseIds = Cases::where('client_id', $client->id)
                        ->where('organization_id', $organizationId)
                        ->pluck('id');
                    $appointment->whereIn('case_id', $clientCaseIds);
                }
            } 
            // For lawyers/employees
            elseif (in_array($user->role, ['lawyer', 'employee'])) {
                $appointment->where('lawyer_id', $user->id);
            }
            
            $appointment = $appointment->first();
                
            if (!$appointment) {
                return response()->json(['error' => 'Appointment not found or unauthorized'], 404);
            }
            
            $appointment->update(['status' => $request->status]);

            return response()->json($appointment);
            
        } catch (\Exception $e) {
            Log::error('Error updating appointment status: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }
}