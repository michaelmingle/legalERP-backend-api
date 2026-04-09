<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CandidateController extends Controller
{
    /**
     * Display a listing of candidates with filtering.
     */
    public function index(Request $request)
    {
        $query = Candidate::with('jobOpening');

        // Filter by stage
        if ($request->has('stage')) {
            $query->where('stage', $request->stage);
        }

        // Filter by role
        if ($request->has('role')) {
            $query->where('role', $request->role);
        }

        // Search by name or email
        if ($request->has('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('full_name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%");
            });
        }

        // Filter by job opening
        if ($request->has('job_opening_id')) {
            $query->where('job_opening_id', $request->job_opening_id);
        }

        $candidates = $query->latest('date_applied')->paginate(20);

        // Transform data to match frontend format (optional)
        $candidates->getCollection()->transform(function ($candidate) {
            return [
                'id'          => $candidate->id,
                'name'        => $candidate->full_name,
                'email'       => $candidate->email,
                'role'        => $candidate->role,
                'date'        => $candidate->date_applied->format('d/m/Y'),
                'attachments' => $candidate->attachments ? 1 : 0, // or count if multiple files
                'avatar'      => $candidate->avatar ? asset('storage/' . $candidate->avatar) : null,
                'status'      => ucfirst($candidate->stage), // for frontend display
                'stage'       => $candidate->stage,          // for filtering
                'job_opening_id' => $candidate->job_opening_id,
            ];
        });

        return response()->json($candidates);
    }

    /**
     * Store a newly created candidate.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'full_name'       => 'required|string|max:255',
            'email'           => 'required|email|unique:candidates,email',
            'role'            => 'required|string|max:255',
            'date_applied'    => 'required|date',
            'attachments'     => 'nullable|file|mimes:pdf,doc,docx|max:5120', // 5MB max
            'stage'           => ['required', Rule::in(['applied', 'reviewing', 'interview', 'hired', 'rejected'])],
            'avatar'          => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'job_opening_id'  => 'nullable|exists:job_openings,id',
        ]);

        // Handle file uploads
        if ($request->hasFile('attachments')) {
            $validated['attachments'] = $request->file('attachments')->store('candidates/attachments', 'public');
        }
        if ($request->hasFile('avatar')) {
            $validated['avatar'] = $request->file('avatar')->store('candidates/avatars', 'public');
        }

        $candidate = Candidate::create($validated);

        return response()->json([
            'message'   => 'Candidate added successfully',
            'candidate' => $candidate->load('jobOpening')
        ], 201);
    }

    /**
     * Display the specified candidate.
     */
    public function show(Candidate $candidate)
    {
        return response()->json($candidate->load('jobOpening'));
    }

    /**
     * Update the specified candidate (including stage change).
     */
    public function update(Request $request, Candidate $candidate)
    {
        $validated = $request->validate([
            'full_name'       => 'sometimes|string|max:255',
            'email'           => ['sometimes', 'email', Rule::unique('candidates')->ignore($candidate->id)],
            'role'            => 'sometimes|string|max:255',
            'date_applied'    => 'sometimes|date',
            'attachments'     => 'nullable|file|mimes:pdf,doc,docx|max:5120',
            'stage'           => ['sometimes', Rule::in(['applied', 'reviewing', 'interview', 'hired', 'rejected'])],
            'avatar'          => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'job_opening_id'  => 'nullable|exists:job_openings,id',
        ]);

        // Handle file uploads (only if new files provided)
        if ($request->hasFile('attachments')) {
            // Delete old file if exists
            if ($candidate->attachments) {
                Storage::disk('public')->delete($candidate->attachments);
            }
            $validated['attachments'] = $request->file('attachments')->store('candidates/attachments', 'public');
        }
        if ($request->hasFile('avatar')) {
            if ($candidate->avatar) {
                Storage::disk('public')->delete($candidate->avatar);
            }
            $validated['avatar'] = $request->file('avatar')->store('candidates/avatars', 'public');
        }

        $candidate->update($validated);

        return response()->json([
            'message'   => 'Candidate updated successfully',
            'candidate' => $candidate->fresh()->load('jobOpening')
        ]);
    }

    /**
     * Remove the specified candidate.
     */
    public function destroy(Candidate $candidate)
    {
        // Delete associated files
        if ($candidate->attachments) {
            Storage::disk('public')->delete($candidate->attachments);
        }
        if ($candidate->avatar) {
            Storage::disk('public')->delete($candidate->avatar);
        }

        $candidate->delete();

        return response()->json(['message' => 'Candidate deleted successfully']);
    }
}