<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Cases;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class CaseController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $cases = Cases::all();
        $cases->load('client:id,full_name', 'assignedUser:id,first_name,last_name', 'supervisor:id,first_name,last_name', 'organization:id,name', 'caseType:id,name');
        return response()->json($cases);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'case_number' => 'required|string|unique:cases',
            'organization_id' => 'required|exists:organizations,id',
            // 'case_type_id' => 'required|exists:case_types,id',
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

            $documentId = null;

            // Handle file upload
            if ($request->hasFile('document')) {

                $file = $request->file('document');
                $path = $file->store('documents', 'public');

                $document = Document::create([
                    'file_path' => $path,
                    'uploaded_by' => Auth::id(),
                    'organization_id' => $validated['organization_id'],
                    // 'mime_type' => $file->getClientMimeType(),
                    'confidentiality' => $validated['confidentiality'],
                ]);

                $documentId = $document->id;
            }

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

            return response()->json([
                'message' => 'Failed to create case',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        //
        $case = Cases::findOrFail($id);
        return response()->json($case);
    }

    public function lawyerCases()
    {
        $lawyerCase = Cases::where('assigned_to', Auth::user()->id)->get();
        return response()->json($lawyerCase);
    }

    // Client cases
    public function clientCases()
    {
        $clientCases = Cases::where('client_id', Auth::user()->id)->get();

        // dd($clientCases);
        return response()->json($clientCases);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        $case = Cases::findOrFail($id);

        $validated = $request->validate([
            'case_number' => 'sometimes|required|string|unique:cases,case_number,' . $id,
            'organization_id' => 'sometimes|required|exists:organizations,id',
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

            $documentId = null;

            // Handle file upload
            if ($request->hasFile('document')) {

                $file = $request->file('document');
                $path = $file->store('documents', 'public');

                $document = Document::create([
                    'file_path' => $path,
                    'uploaded_by' => Auth::id(),
                    'organization_id' => $validated['organization_id'] ?? $case->organization_id,
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

            return response()->json([
                'message' => 'Failed to update case',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $case = Cases::findOrFail($id);
        $case->delete();
        return response()->json(null, 204);
    }
}
