<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Client;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $clients = Client::all();
        return response()->json($clients);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|unique:clients,email',
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'photo_url' => 'nullable|url|max:255',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'job_title' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'address' => 'nullable|string|max:500',
            'assigned_lawyer' => 'nullable|exists:users,id',
            'document_id' => 'nullable|exists:documents,id',
        ]);

        // Handle file upload and create document record if needed
        if ($request->hasFile('document')) {
            $file = $request->file('document');
            $path = $file->store('documents', 'public');
            $document = Document::create([
                'organization_id' => $validated['organization_id'],
                'uploaded_by' => Auth::id(),
                'file_name' => $file->getClientOriginalName(),
                'file_path' => $path,
                'file_type' => $file->getClientMimeType(),
                'file_size' => $file->getSize(),
            ]);
            $validated['document_id'] = $document->id;
        }

        $client = Client::create($validated);
        return response()->json($client, 201);
        
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
        $client = Client::findOrFail($id);

        $validated = $request->validate([
            'organization_id' => 'required|exists:organizations,id',
            'full_name' => 'required|string|max:255',
            'email' => 'required|email|unique:clients,email,' . $client->id,
            'phone' => 'nullable|string|max:20',
            'mobile' => 'nullable|string|max:20',
            'photo_url' => 'nullable|url|max:255',
            'gender' => 'nullable|in:male,female,other',
            'date_of_birth' => 'nullable|date',
            'job_title' => 'nullable|string|max:255',
            'status' => 'required|in:active,inactive',
            'address' => 'nullable|string|max:500',
            'assigned_lawyer' => 'nullable|exists:users,id',
            'document_id' => 'nullable|exists:documents,id',
         ]);


            if ($request->hasFile('document')) {
                $file = $request->file('document');
                $path = $file->store('documents', 'public');
                $document = Document::create([
                    'organization_id' => $validated['organization_id'],
                    'uploaded_by' => Auth::id(),
                    'file_name' => $file->getClientOriginalName(),
                    'file_path' => $path,
                    'file_type' => $file->getClientMimeType(),
                    'file_size' => $file->getSize(),
                ]);
                $validated['document_id'] = $document->id;
            }

        $client->update($validated);
        return response()->json($client);

            // Validate the request data
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
