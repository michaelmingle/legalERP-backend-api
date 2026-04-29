<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cases;
use App\Models\Client;
use App\Models\User;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CaseController extends Controller
{
    /**
     * Display a listing of the resource (filtered by organization)
     */
    public function index()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $cases = Cases::where('organization_id', $organizationId)
            ->with(['client', 'assignedUser', 'supervisor', 'caseType'])
            ->get();
        
        return response()->json($cases);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $validated = $request->validate([
            'case_number' => 'required|string|unique:cases',
            'case_type' => 'required|string|max:255',
            'client_id' => 'required|exists:clients,id',
            'case_name' => 'required|string|max:255',
            'note' => 'nullable|string',
            'status' => 'required|in:draft,opened,in_progress,pending_review,pending_client,pending_court,settled,closed,archived',
            'priority' => 'required|in:low,medium,high,urgent',
            'confidentiality' => 'required|in:public,confidential,highly_confidential',
            'assigned_to' => 'nullable|exists:users,id',
            'supervisor' => 'required|exists:users,id',
            'billing_method' => 'required|in:hourly,daily,weekly,monthly',
            'case_start_date' => 'required|date',
            'expected_resolution_date' => 'nullable|date',
            'next_hearing_date' => 'nullable|date',
            'next_followup_date' => 'nullable|date',
            'rate' => 'required|numeric|min:0',
            'deposit' => 'required|numeric|min:0',
            'document' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        DB::beginTransaction();

        try {
            // Verify client belongs to organization
            $client = Client::where('id', $validated['client_id'])
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$client) {
                return response()->json(['error' => 'Client not found in your organization'], 404);
            }
            
            // Verify assigned_to user belongs to organization
            if (!empty($validated['assigned_to'])) {
                $assignedUser = User::where('id', $validated['assigned_to'])
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$assignedUser) {
                    return response()->json(['error' => 'Assigned user not found in your organization'], 404);
                }
            }
            
            // Verify supervisor belongs to organization
            $supervisor = User::where('id', $validated['supervisor'])
                ->where('organization_id', $organizationId)
                ->first();
                
            if (!$supervisor) {
                return response()->json(['error' => 'Supervisor not found in your organization'], 404);
            }

            $documentId = null;

            // Handle file upload
            if ($request->hasFile('document')) {
                $file = $request->file('document');
                $path = $file->store('documents', 'public');

                $document = Document::create([
                    'file_path' => $path,
                    'uploaded_by' => Auth::id(),
                    'organization_id' => $organizationId,
                    'confidentiality' => $validated['confidentiality'],
                ]);

                $documentId = $document->id;
            }

            // Add organization_id to validated data
            $validated['organization_id'] = $organizationId;
            
            // Attach document ID if exists
            if ($documentId) {
                $validated['document'] = $documentId;
            }

            // Create case
            $case = Cases::create($validated);

            // Update document with case_id
            if (isset($document)) {
                $document->update(['case_id' => $case->id]);
            }

            DB::commit();

            return response()->json([
                'message' => 'Case created successfully',
                'data' => $case
            ], 201);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Case creation failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to create case',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    public function getAssignableUsers()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        // Get users that can be assigned to cases (lawyers and employees only)
        $users = User::where('organization_id', $organizationId)
            ->whereIn('role', ['lawyer', 'employee'])
            ->where('status', 'active')
            ->select('id', 'first_name', 'last_name', 'email', 'role', 'username')
            ->orderBy('first_name')
            ->get()
            ->map(function($user) {
                return [
                    'id' => $user->id,
                    'first_name' => $user->first_name,
                    'last_name' => $user->last_name,
                    'name' => $user->first_name . ' ' . $user->last_name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'username' => $user->username,
                    'display_name' => ($user->first_name . ' ' . $user->last_name) ?: $user->username ?: $user->email,
                ];
            });
        
        return response()->json($users);
    }

    /**
     * Display the specified resource (filtered by organization)
     */
    public function show($id)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $case = Cases::where('organization_id', $organizationId)
            ->with(['client', 'assignedUser', 'supervisor', 'caseType'])
            ->findOrFail($id);
            
        return response()->json($case);
    }

    /**
     * Get cases for the logged-in lawyer (filtered by organization)
     */
    public function lawyerCases()
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $lawyerCases = Cases::where('organization_id', $organizationId)
            ->where('assigned_to', $user->id)
            ->with(['client', 'supervisor'])
            ->get();
            
        return response()->json($lawyerCases);
    }

    /**
     * Get cases for the logged-in client (filtered by organization)
     */
    public function clientCases()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Get the client record for this user within the organization
            $client = Client::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();
            
            if (!$client) {
                return response()->json([]);
            }
            
            // Get cases for this client within the organization
            $cases = Cases::where('organization_id', $organizationId)
                ->where('client_id', $client->id)
                ->with(['assignedLawyer:id,first_name,last_name', 'supervisor:id,first_name,last_name'])
                ->get();
            
            return response()->json($cases);
        } catch (\Exception $e) {
            Log::error('Error in clientCases: ' . $e->getMessage());
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $case = Cases::where('organization_id', $organizationId)->findOrFail($id);

        $validated = $request->validate([
            'case_number' => 'sometimes|required|string|unique:cases,case_number,' . $id,
            'case_type' => 'sometimes|required|string|max:255',
            'client_id' => 'sometimes|required|exists:clients,id',
            'case_name' => 'sometimes|required|string|max:255',
            'note' => 'nullable|string',
            'status' => 'sometimes|required|in:draft,opened,in_progress,pending_review,pending_client,pending_court,settled,closed,archived',
            'priority' => 'sometimes|required|in:low,medium,high,urgent',
            'confidentiality' => 'sometimes|required|in:public,confidential,highly_confidential',
            'assigned_to' => 'nullable|exists:users,id',
            'supervisor' => 'sometimes|required|exists:users,id',
            'billing_method' => 'sometimes|required|in:hourly,daily,weekly,monthly',
            'case_start_date' => 'sometimes|required|date',
            'expected_resolution_date' => 'nullable|date',
            'next_hearing_date' => 'nullable|date',
            'next_followup_date' => 'nullable|date',
            'rate' => 'sometimes|required|numeric|min:0',
            'deposit' => 'sometimes|required|numeric|min:0',
            'document' => 'nullable|file|mimes:pdf,doc,docx|max:10240',
        ]);

        DB::beginTransaction();

        try {
            // Verify client belongs to organization if being updated
            if (isset($validated['client_id'])) {
                $client = Client::where('id', $validated['client_id'])
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$client) {
                    return response()->json(['error' => 'Client not found in your organization'], 404);
                }
            }
            
            // Verify assigned_to user belongs to organization if being updated
            if (isset($validated['assigned_to']) && !empty($validated['assigned_to'])) {
                $assignedUser = User::where('id', $validated['assigned_to'])
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$assignedUser) {
                    return response()->json(['error' => 'Assigned user not found in your organization'], 404);
                }
            }
            
            // Verify supervisor belongs to organization if being updated
            if (isset($validated['supervisor'])) {
                $supervisor = User::where('id', $validated['supervisor'])
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$supervisor) {
                    return response()->json(['error' => 'Supervisor not found in your organization'], 404);
                }
            }

            $documentId = null;

            // Handle file upload
            if ($request->hasFile('document')) {
                $file = $request->file('document');
                $path = $file->store('documents', 'public');

                $document = Document::create([
                    'file_path' => $path,
                    'uploaded_by' => Auth::id(),
                    'organization_id' => $organizationId,
                    'confidentiality' => $validated['confidentiality'] ?? $case->confidentiality,
                    'case_id' => $case->id,
                ]);

                $documentId = $document->id;
            }

            // Attach new document if uploaded
            if ($documentId) {
                $validated['document'] = $documentId;
            }

            // Update case
            $case->update($validated);

            DB::commit();

            return response()->json([
                'message' => 'Case updated successfully',
                'data' => $case
            ], 200);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Case update failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to update case',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Remove the specified resource from storage (soft delete)
     */
    public function destroy($id)
    {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $case = Cases::where('organization_id', $organizationId)->findOrFail($id);
        $case->delete();
        
        return response()->json(['message' => 'Case deleted successfully'], 200);
    }
}