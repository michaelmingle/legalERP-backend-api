<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Document;
use App\Models\Cases;
use App\Models\Client;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Log;

class DocumentController extends Controller
{
    /**
     * Display a listing of the resource (filtered by organization)
     */
    public function index()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only admin and owner can view all documents
            if (!in_array($user->role, ['admin', 'owner'])) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            $documents = Document::where('organization_id', $organizationId)
                ->with(['uploader:id,first_name,last_name', 'case:id,case_name', 'organization:id,name'])
                ->latest()
                ->get();
                
            return response()->json($documents);
        } catch (\Exception $e) {
            Log::error('Error fetching documents: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch documents'], 500);
        }
    }

    /**
 * Get documents for the logged-in user (works for all roles)
 */
public function myDocument()
{
    try {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        $query = Document::where('organization_id', $organizationId)
            ->with(['uploader', 'case']);
        
        switch ($user->role) {
            case 'admin':
            case 'owner':
                // Can see all documents
                break;
                
            case 'lawyer':
            case 'employee':
                // Can see documents from cases assigned to them
                $caseIds = Cases::where('assigned_to', $user->id)
                    ->where('organization_id', $organizationId)
                    ->pluck('id');
                
                $query->whereIn('case_id', $caseIds);
                break;
                
            case 'client':
                // Can see documents from their cases
                $client = \App\Models\Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                
                if ($client) {
                    $caseIds = Cases::where('client_id', $client->id)
                        ->where('organization_id', $organizationId)
                        ->pluck('id');
                    
                    $query->whereIn('case_id', $caseIds);
                } else {
                    return response()->json([]);
                }
                break;
                
            default:
                return response()->json(['error' => 'Unauthorized'], 403);
        }
        
        // Also include documents uploaded by the user themselves
        $query->orWhere('uploaded_by', $user->id);
        
        $documents = $query->latest()->get();
        
        return response()->json($documents);
        
    } catch (\Exception $e) {
        Log::error('Error fetching my documents: ' . $e->getMessage());
        return response()->json(['error' => 'Failed to fetch documents'], 500);
    }
}

    /**
     * Get documents for the logged-in client (filtered by organization)
     */
    public function clientDocuments()
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Only clients can access
            if ($user->role !== 'client') {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            // Get the client record for this user within the organization
            $client = \App\Models\Client::where('user_id', $user->id)
                ->where('organization_id', $organizationId)
                ->first();
            
            if (!$client) {
                return response()->json([]);
            }
            
            // Get documents for cases belonging to this client
            $clientDocuments = Document::where('organization_id', $organizationId)
                ->whereHas('case', function ($query) use ($client) {
                    $query->where('client_id', $client->id);
                })
                ->with(['uploader:id,first_name,last_name', 'case:id,case_name'])
                ->latest()
                ->get()
                ->map(function ($doc) {
                    return [
                        'id' => $doc->id,
                        'file_name' => $doc->file_path ? basename($doc->file_path) : null,
                        'file_path' => $doc->file_path,
                        'size' => $doc->file_path 
                            ? Storage::disk('public')->size($doc->file_path) 
                            : null,
                        'case_name' => $doc->case ? $doc->case->case_name : null,
                        'uploaded_by' => $doc->uploader 
                            ? $doc->uploader->first_name . ' ' . $doc->uploader->last_name 
                            : 'Unknown',
                        'created_at' => $doc->created_at,
                    ];
                });
            
            return response()->json($clientDocuments);
        } catch (\Exception $e) {
            Log::error('Error fetching client documents: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch documents'], 500);
        }
    }

    /**
 * Get documents for users based on their role (lawyers, employees, and clients)
 */
public function lawyerDocuments()
{
    try {
        $user = Auth::user();
        $organizationId = $user->organization_id;
        
        Log::info('Documents access attempt', [
            'user_id' => $user->id,
            'user_role' => $user->role,
            'organization_id' => $organizationId
        ]);
        
        // Base query for the organization
        $query = Document::where('organization_id', $organizationId)
            ->with(['uploader', 'case']);
        
        // Filter based on user role
        switch ($user->role) {
            case 'admin':
            case 'owner':
                // Can see ALL documents in the organization
                // No additional filters needed
                break;
                
            case 'lawyer':
            case 'employee':
                // Can see documents from cases assigned to them OR uploaded by them
                $caseIds = Cases::where('assigned_to', $user->id)
                    ->where('organization_id', $organizationId)
                    ->pluck('id');
                
                $query->where(function($q) use ($user, $caseIds) {
                    $q->whereIn('case_id', $caseIds)
                      ->orWhere('uploaded_by', $user->id);
                });
                break;
                
            case 'client':
                // Can see documents from their own cases
                // Get the client record for this user
                $client = \App\Models\Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                
                if ($client) {
                    $caseIds = Cases::where('client_id', $client->id)
                        ->where('organization_id', $organizationId)
                        ->pluck('id');
                    
                    $query->whereIn('case_id', $caseIds);
                } else {
                    // If no client record, return empty
                    return response()->json([]);
                }
                break;
                
            default:
                return response()->json(['error' => 'Unauthorized to access documents'], 403);
        }
        
        $documents = $query->latest()->get()->map(function ($doc) {
            return [
                'id' => $doc->id,
                'file_name' => $doc->file_name ?? ($doc->file_path ? basename($doc->file_path) : null),
                'file_path' => $doc->file_path,
                'size' => $doc->file_size ?? ($doc->file_path ? Storage::disk('public')->size($doc->file_path) : null),
                'case_name' => $doc->case ? $doc->case->case_name : null,
                'case_id' => $doc->case_id,
                'uploaded_by' => $doc->uploader
                    ? $doc->uploader->first_name . ' ' . $doc->uploader->last_name
                    : 'Unknown',
                'uploaded_by_id' => $doc->uploaded_by,
                'created_at' => $doc->created_at,
                'confidentiality' => $doc->confidentiality,
                'description' => $doc->description,
            ];
        });
        
        return response()->json($documents);
        
    } catch (\Exception $e) {
        Log::error('Error fetching documents: ' . $e->getMessage());
        return response()->json(['error' => 'Failed to fetch documents: ' . $e->getMessage()], 500);
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
                'case_id' => 'nullable|exists:cases,id',
                'description' => 'nullable|string',
                'confidentiality' => 'sometimes|in:public,confidential,highly_confidential',
                'file_path' => 'required|file|mimes:pdf,doc,docx,xlsx,png,jpg,jpeg|max:10240', // 10MB
            ]);

            DB::beginTransaction();

            // If case_id is provided, verify it belongs to the organization
            if ($request->has('case_id') && $request->case_id) {
                $case = Cases::where('id', $request->case_id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$case) {
                    return response()->json(['error' => 'Case not found in your organization'], 404);
                }
            }

            $file = $request->file('file_path');
            $path = $file->store('documents', 'public');

            $document = Document::create([
                'file_path' => $path,
                'organization_id' => $organizationId,
                'case_id' => $validated['case_id'] ?? null,
                'uploaded_by' => $user->id,
                'confidentiality' => $request->confidentiality ?? 'confidential',
                'description' => $validated['description'] ?? null,
                'file_name' => $file->getClientOriginalName(),
                'file_size' => $file->getSize(),
                'file_type' => $file->getMimeType(),
            ]);

            DB::commit();

            return response()->json([
                'message' => 'Document uploaded successfully',
                'data' => $document->load(['uploader:id,first_name,last_name', 'case:id,case_name'])
            ], 201);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Document upload failed: ' . $e->getMessage());
            return response()->json([
                'message' => 'Failed to upload document',
                'error' => $e->getMessage()
            ], 500);
        }
    }

    /**
     * Display the specified resource (filtered by organization)
     */
    public function show($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $document = Document::where('organization_id', $organizationId)
                ->with(['uploader:id,first_name,last_name', 'case:id,case_name'])
                ->find($id);
            
            if (!$document) {
                return response()->json(['error' => 'Document not found'], 404);
            }
            
            return response()->json($document);
        } catch (\Exception $e) {
            Log::error('Error fetching document: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch document'], 500);
        }
    }

    /**
     * Update the specified resource in storage (filtered by organization)
     */
    public function update(Request $request, $id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $document = Document::where('organization_id', $organizationId)->find($id);
            
            if (!$document) {
                return response()->json(['error' => 'Document not found'], 404);
            }
            
            // Check if user has permission to update
            if ($user->role !== 'admin' && $user->role !== 'owner' && $document->uploaded_by !== $user->id) {
                return response()->json(['error' => 'Unauthorized to update this document'], 403);
            }
            
            $validated = $request->validate([
                'description' => 'nullable|string',
                'confidentiality' => 'sometimes|in:public,confidential,highly_confidential',
            ]);
            
            $document->update($validated);
            
            return response()->json([
                'message' => 'Document updated successfully',
                'data' => $document->load(['uploader:id,first_name,last_name', 'case:id,case_name'])
            ]);
        } catch (\Exception $e) {
            Log::error('Error updating document: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update document'], 500);
        }
    }

    /**
     * Remove the specified resource from storage (filtered by organization)
     */
    public function destroy($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $document = Document::where('organization_id', $organizationId)->find($id);
            
            if (!$document) {
                return response()->json(['error' => 'Document not found'], 404);
            }
            
            // Check if user has permission to delete
            if ($user->role !== 'admin' && $user->role !== 'owner' && $document->uploaded_by !== $user->id) {
                return response()->json(['error' => 'Unauthorized to delete this document'], 403);
            }
            
            // Delete physical file
            if ($document->file_path && Storage::disk('public')->exists($document->file_path)) {
                Storage::disk('public')->delete($document->file_path);
            }
            
            $document->delete();
            
            return response()->json(['message' => 'Document deleted successfully']);
        } catch (\Exception $e) {
            Log::error('Error deleting document: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete document'], 500);
        }
    }
    
    /**
     * Download document (filtered by organization)
     */
    public function download($id)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $document = Document::where('organization_id', $organizationId)->find($id);
            
            if (!$document) {
                return response()->json(['error' => 'Document not found'], 404);
            }
            
            // Check if user has permission to download
            $hasAccess = false;
            
            if (in_array($user->role, ['admin', 'owner'])) {
                $hasAccess = true;
            } elseif ($document->uploaded_by === $user->id) {
                $hasAccess = true;
            } elseif ($document->case && $document->case->assigned_to === $user->id) {
                $hasAccess = true;
            } elseif ($user->role === 'client') {
                $client = Client::where('user_id', $user->id)
                    ->where('organization_id', $organizationId)
                    ->first();
                if ($client && $document->case && $document->case->client_id === $client->id) {
                    $hasAccess = true;
                }
            }
            
            if (!$hasAccess) {
                return response()->json(['error' => 'Unauthorized to download this document'], 403);
            }
            
            $filePath = storage_path('app/public/' . $document->file_path);
            
            if (!file_exists($filePath)) {
                return response()->json(['error' => 'File not found'], 404);
            }
            
            return response()->download($filePath, basename($document->file_path));
        } catch (\Exception $e) {
            Log::error('Error downloading document: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to download document'], 500);
        }
    }
}