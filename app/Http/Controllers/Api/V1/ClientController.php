<?php
// app/Http/Controllers/Api/V1/ClientController.php

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
use Illuminate\Support\Facades\Log;

class ClientController extends Controller
{
    /**
     * Display a listing of the resource (only for current organization)
     */
    public function index()
{
    try {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $clients = Client::where('organization_id', $organizationId)
            ->with(['assignedLawyer:id,first_name,last_name,email,role', 'organization:id,name'])
            ->get();
        
        // Add user data separately to avoid errors
        foreach ($clients as $client) {
            if ($client->user_id) {
                $client->user = User::find($client->user_id);
            }
        }
        
        return response()->json($clients);
    } catch (\Exception $e) {
        Log::error('Error fetching clients: ' . $e->getMessage());
        return response()->json(['error' => 'Failed to fetch clients'], 500);
    }
}

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'client_number' => 'nullable|string|max:255|unique:clients,client_number',
                'email' => 'required|email|unique:clients,email',
                'phone' => 'nullable|string|max:20',
                'mobile' => 'nullable|string|max:20',
                'address' => 'nullable|string',
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'status' => 'required|in:active,inactive',
                'assigned_lawyer' => 'nullable|exists:users,id',
            ]);

            DB::beginTransaction();

            // Create user account for client
            $names = explode(' ', $validated['full_name']);
            $firstName = $names[0];
            $lastName = isset($names[1]) ? implode(' ', array_slice($names, 1)) : '';
            
            $password = 'password123'; // You can generate a random password or send via email
            
            $newUser = User::create([
                'organization_id' => $organizationId,
                'first_name' => $firstName,
                'last_name' => $lastName,
                'email' => $validated['email'],
                'password' => Hash::make($password),
                'phone' => $validated['phone'] ?? null,
                'mobile' => $validated['mobile'] ?? null,
                'role' => 'client',
                'status' => $validated['status'],
            ]);
            
            $validated['user_id'] = $newUser->id;
            $validated['organization_id'] = $organizationId;
            
            $client = Client::create($validated);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Client created successfully',
                'data' => $client
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Client creation failed: ' . $e->getMessage());
            return response()->json([
                'status' => false,
                'message' => 'Client creation failed',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource (only if belongs to current organization)
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $client = Client::where('organization_id', $organizationId)
                ->with([
                    'assignedLawyer:id,first_name,last_name',
                    'organization:id,name',
                ])
                ->find($id);
            
            if (!$client) {
                return response()->json(['error' => 'Client not found'], 404);
            }
            
            // Load user data separately if exists
            if ($client->user_id) {
                $client->user = User::find($client->user_id);
            }
            
            return response()->json($client);
        } catch (\Exception $e) {
            Log::error('Error fetching client: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch client'], 500);
        }
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;

            $client = Client::where('organization_id', $organizationId)->find($id);
            if (!$client) {
                return response()->json(['error' => 'Client not found'], 404);
            }
            
            $validated = $request->validate([
                'full_name' => 'required|string|max:255',
                'client_number' => 'nullable|string|max:255|unique:clients,client_number,' . $client->id,
                'email' => 'required|email|unique:clients,email,' . $client->id,
                'password' => 'nullable|string|min:6',
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
                'city' => 'nullable|string|max:100',
                'state' => 'nullable|string|max:100',
                'postal_code' => 'nullable|string|max:20',
                'country' => 'nullable|string|max:100',
                'assigned_lawyer' => 'nullable|exists:users,id',
                'document' => 'nullable|file|mimes:pdf,doc,docx,jpg,jpeg,png|max:2048',
            ]);

            DB::beginTransaction();

            // Handle user account if exists or create new one
            if ($client->user_id) {
                $userAccount = User::where('organization_id', $organizationId)->find($client->user_id);
                if ($userAccount) {
                    $names = explode(' ', $validated['full_name']);
                    $firstName = $names[0];
                    $lastName = isset($names[1]) ? implode(' ', array_slice($names, 1)) : '';

                    $userUpdateData = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $validated['email'],
                        'phone' => $validated['phone'] ?? null,
                        'mobile' => $validated['mobile'] ?? null,
                        'status' => $validated['status'],
                    ];
                    
                    if (!empty($validated['password'])) {
                        $userUpdateData['password'] = Hash::make($validated['password']);
                    }
                    
                    $userAccount->update($userUpdateData);
                }
            } else {
                // Create user account for client if it doesn't exist
                $names = explode(' ', $validated['full_name']);
                $firstName = $names[0];
                $lastName = isset($names[1]) ? implode(' ', array_slice($names, 1)) : '';
                
                $password = $validated['password'] ?? 'password123';
                
                $userAccount = User::create([
                    'organization_id' => $organizationId,
                    'first_name' => $firstName,
                    'last_name' => $lastName,
                    'email' => $validated['email'],
                    'password' => Hash::make($password),
                    'phone' => $validated['phone'] ?? null,
                    'mobile' => $validated['mobile'] ?? null,
                    'role' => 'client',
                    'status' => $validated['status'],
                ]);
                
                $validated['user_id'] = $userAccount->id;
            }

            // Handle document upload
            if ($request->hasFile('document')) {
                if ($client->document_id) {
                    $oldDocument = Document::where('organization_id', $organizationId)->find($client->document_id);
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

            $client->update($validated);

            DB::commit();

            return response()->json([
                'status' => true,
                'message' => 'Client updated successfully',
                'data' => $client
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Client update failed: ' . $e->getMessage());
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
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $client = Client::where('organization_id', $organizationId)->find($id);
            if (!$client) {
                return response()->json(['error' => 'Client not found'], 404);
            }
            
            // Also delete the associated user account if exists
            if ($client->user_id) {
                $userAccount = User::where('organization_id', $organizationId)->find($client->user_id);
                if ($userAccount) {
                    $userAccount->delete();
                }
            }
            
            $client->delete();
            return response()->json(['message' => 'Client deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Client deletion failed: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete client'], 500);
        }
    }

    /**
     * Get clients for the current organization (alternative method)
     */
    public function getClientsByOrganization()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $clients = Client::where('organization_id', $organizationId)
                ->with(['assignedLawyer:id,first_name,last_name'])
                ->orderBy('created_at', 'desc')
                ->get();
            
            return response()->json([
                'success' => true,
                'data' => $clients,
                'total' => $clients->count()
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching organization clients: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch clients'], 500);
        }
    }

    /**
     * My Cases (only for the authenticated client)
     */
    public function myCases()
    {
        try {
            $userId = Auth::id();
            $client = Client::where('user_id', $userId)->first();

            if (!$client) {
                return response()->json(['message' => 'Client not found'], 404);
            }

            $cases = $client->cases()->with('assignedLawyer:id,first_name,last_name')->get();

            return response()->json($cases);
        } catch (\Exception $e) {
            Log::error('Error fetching client cases: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch cases'], 500);
        }
    }
}