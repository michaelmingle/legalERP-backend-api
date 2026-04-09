<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clients = Client::all();
        // assigned lawyer details
        $clients->load('assignedLawyer:id,first_name,last_name', 'organization:id,name');
        // dd($clients);
        return response()->json($clients);
    }

    // My Cases 
    public function myCases()
    {
        $userId = Auth::id();
        $client = Client::where('user_id', $userId)->first();

        if (!$client) {
            return response()->json(['message' => 'Client not found'], 404);
        }

        $cases = $client->cases()->with('assignedLawyer:id,first_name,last_name')->get();

        return response()->json($cases);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $organizationId = Auth::user()->organization_id;

        $validated = $request->validate([
            // 'organization_id' => 'required|exists:organizations,id',
            'full_name' => 'required|string|max:255',
            'client_number' => 'nullable|string|max:255',
            'email' => 'required|email|unique:clients,email',
            // 'password' => 'required|string|min:6',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'job_title' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'address' => 'nullable|string|max:500',
            'assigned_lawyer' => 'nullable|exists:users,id',
            'document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
        ]);

        DB::beginTransaction();

        try {

            $names = explode(' ', $validated['full_name']);

            $user = User::create([
                'organization_id' => $organizationId,
                'first_name' => $names[0],
                'last_name' => $names[1] ?? '',
                'email' => $validated['email'],
                'password' => Hash::make("password"),
                'phone' => $validated['phone'] ?? null,
                'mobile' => $validated['mobile'] ?? null,
                'role' => 'client',
                'status' => $validated['status'],
            ]);


            if ($request->hasFile('document')) {

                $file = $request->file('document');
                $path = $file->store('documents', 'public');

                $document = Document::create([
                    'organization_id' => $organizationId,
                    'uploaded_by' => Auth::id(),
                    'file_path' => $path,
                ]);

                $validated['document_id'] = $document->id;
            }


            $validated['user_id'] = $user->id;
            $validated['organization_id'] = $organizationId;

            $client = Client::create($validated);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Client created successfully',
                'data' => $client->load('user')
            ], 201);
        } catch (\Exception $e) {

            DB::rollBack();

            return response()->json([
                'status' => false,
                'message' => 'Client creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }


    /**
     * Display the specified resource.
     */
    public function show(string $id)
    {
        $client = Client::findOrFail($id);
        return response()->json($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
{
    $organizationId = Auth::user()->organization_id;

    $client = Client::findOrFail($id);
    $user = User::findOrFail($client->user_id);

    $validated = $request->validate([
        // 'organization_id' => 'required|exists:organizations,id',
        'full_name' => 'required|string|max:255',
        'client_number' => 'nullable|string|max:255',
        'email' => 'required|email|unique:clients,email,' . $client->id,
        // 'password' => 'nullable|string|min:6',
        'phone' => 'nullable|string|max:20',
        'mobile' => 'nullable|string|max:20',
        'photo_url' => 'nullable|url|max:255',
        'gender' => 'nullable|in:male,female,other',
        'date_of_birth' => 'nullable|date',
        'job_title' => 'nullable|string|max:255',
        'start_date' => 'nullable|date',
        'tags' => 'nullable|string|max:255',
        'status' => 'required|in:active,inactive',
        'address' => 'nullable|string|max:500',
        'assigned_lawyer' => 'nullable|exists:users,id',
        'document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
    ]);

    DB::beginTransaction();

    try {

        $names = explode(' ', $validated['full_name']);

        $user->update([
            'organization_id' => $organizationId,
            'first_name' => $names[0],
            'last_name' => $names[1] ?? '',
            'email' => $validated['email'],
            'phone' => $validated['phone'] ?? null,
            'mobile' => $validated['mobile'] ?? null,
            'status' => $validated['status'],
        ]);

        if (!empty($validated['password'])) {
            $user->update([
                'password' => Hash::make("password")
            ]);
        }

        if ($request->hasFile('document')) {

            if ($client->document_id) {
                $oldDocument = Document::find($client->document_id);

                if ($oldDocument) {
                    Storage::disk('public')->delete($oldDocument->file_path);
                    $oldDocument->delete();
                }
            }

            $file = $request->file('document');
            $path = $file->store('documents', 'public');

            $document = Document::create([
                'organization_id' => $organizationId,
                'uploaded_by' => Auth::id(),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
            ]);

            $validated['document_id'] = $document->id;
        }

        $validated['organization_id'] = $organizationId;
        $client->update($validated);

        DB::commit();

        return response()->json([
            'status' => true,
            'message' => 'Client updated successfully',
            'data' => $client->load('user')
        ]);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'status' => false,
            'message' => 'Client update failed',
            'error' => $e->getMessage()
        ], 500);
    }
}


    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        $client = Client::findOrFail($id);
        $client->delete();
        return response()->json(['message' => 'Client deleted successfully']);
    }
}
