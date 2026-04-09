<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Document;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

class DocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $documents = Document::all();
        $documents->load('uploader:id,first_name,last_name', 'case:id,case_name', 'organization:id,name');
        return response()->json($documents);
    }

    // My Documents
    public function myDocument()
    {
        $documents = Document::with('case')
        ->whereHas('case', function ($query) {
            $query->where('assigned_to', Auth::user()->id);
        })
        ->latest()
        ->get();

        // dd($documents);

        return response()->json($documents);
    }

    // Client Documents
    public function clientDocuments()
    {
        $clientDocuments = Document::whereHas('case', function ($query) {
            $query->where('client_id', Auth::user()->id);
        })->get();

        return response()->json($clientDocuments);
    }

    // Lawyer Documents where assigned_to is user id with role lawyer or employee
//     public function lawyerDocuments()
// {
//     $lawyerDocuments = Document::whereHas('uploader', function ($query) {
//         $query->whereIn('role', ['lawyer', 'employee']);
//     })->get();

//     return response()->json($lawyerDocuments);
// }

public function lawyerDocuments()
{
    $lawyerDocuments = Document::with(['uploader', 'case'])
        ->whereHas('uploader', function ($query) {
            $query->whereIn('role', ['lawyer', 'employee']);
        })
        ->get()
        ->map(function ($doc) {
            return [
                'id' => $doc->id,
                'file_name' => $doc->file_path ? basename($doc->file_path) : null,
                'file_path' => $doc->file_path,
                'size' => $doc->file_path 
                    ? Storage::disk('public')->size($doc->file_path) 
                    : null,
                'case_name' => $doc->case 
                    ? $doc->case->case_name   
                    : null,

                // uploader name
                'uploaded_by' => $doc->uploader
                    ? $doc->uploader->first_name . ' ' . $doc->uploader->last_name
                    : null,

                'created_at' => $doc->created_at,
            ];
        });

    return response()->json($lawyerDocuments);
}
    

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
{
    $validated = $request->validate([
        'organization_id' => 'required|exists:organizations,id',
        'case_id' => 'nullable|exists:cases,id',
        'description' => 'nullable|string',
        'confidentiality' => 'required|in:public,confidential,highly_confidential',
        'file_path' => 'required|file|mimes:pdf,doc,docx,xlsx,png,jpg,jpeg|max:10240', // 10MB
    ]);

    DB::beginTransaction();

    try {

        $file = $request->file('file_path');

        // Store file in public disk
        $path = $file->store('documents', 'public');

        $document = Document::create([
            'file_path' => $path,
            'organization_id' => $validated['organization_id'],
            'case_id' => $validated['case_id'] ?? null,
            'uploaded_by' => Auth::id(),
            'confidentiality' => "confidential",
        ]);

        DB::commit();

        return response()->json([
            'message' => 'Document uploaded successfully',
            'data' => $document
        ], 201);

    } catch (\Exception $e) {

        DB::rollBack();

        return response()->json([
            'message' => 'Failed to upload document',
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
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $id)
    {
        //
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $id)
    {
        //
    }
}
