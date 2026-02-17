<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Document;

class DocumentController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        //
        $documents = Document::all();
        return response()->json($documents);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'file_path' => 'required|string|max:255',
            'organization_id' => 'required|exists:organizations,id',
            'uploaded_by' => 'required|exists:users,id',
            'case_id' => 'nullable|exists:cases,id',
            'description' => 'nullable|string',
            'original_filename' => 'required|string|max:255',
            'mime_type' => 'required|string|max:255',
            'confidentiality' => 'required|in:public,confidential,highly_confidential',
        ]);

        $document = Document::create($validated);
        return response()->json($document, 201);
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
