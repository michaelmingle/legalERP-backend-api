<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Support\Facades\Log;

class CandidateController extends Controller
{
    /**
     * Display a listing of candidates (filtered by organization)
     */
    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            $query = Candidate::whereHas('jobOpening', function($q) use ($organizationId) {
                    $q->where('organization_id', $organizationId);
                })
                ->with('jobOpening');

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

            // Transform data to match frontend format
            $candidates->getCollection()->transform(function ($candidate) {
                return [
                    'id'          => $candidate->id,
                    'name'        => $candidate->full_name,
                    'email'       => $candidate->email,
                    'role'        => $candidate->role,
                    'date'        => $candidate->date_applied->format('d/m/Y'),
                    'attachments' => $candidate->attachments ? 1 : 0,
                    'avatar'      => $candidate->avatar ? asset('storage/' . $candidate->avatar) : null,
                    'status'      => ucfirst($candidate->stage),
                    'stage'       => $candidate->stage,
                    'job_opening_id' => $candidate->job_opening_id,
                    'job_opening_title' => $candidate->jobOpening ? $candidate->jobOpening->title : null,
                ];
            });

            return response()->json($candidates);
            
        } catch (\Exception $e) {
            Log::error('Error fetching candidates: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch candidates'], 500);
        }
    }

    /**
     * Store a newly created candidate (filtered by organization)
     */
    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify job opening belongs to organization
            if ($request->has('job_opening_id')) {
                $jobOpening = JobOpening::where('id', $request->job_opening_id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$jobOpening) {
                    return response()->json(['error' => 'Job opening not found'], 404);
                }
            }
            
            $validated = $request->validate([
                'full_name'       => 'required|string|max:255',
                'email'           => 'required|email|unique:candidates,email',
                'role'            => 'required|string|max:255',
                'date_applied'    => 'required|date',
                'attachments'     => 'nullable|file|mimes:pdf,doc,docx|max:5120',
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
            
        } catch (\Exception $e) {
            Log::error('Error creating candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create candidate'], 500);
        }
    }

    /**
     * Display the specified candidate (filtered by organization)
     */
    public function show(Candidate $candidate)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify candidate belongs to organization
            if ($candidate->jobOpening && $candidate->jobOpening->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            return response()->json($candidate->load('jobOpening'));
            
        } catch (\Exception $e) {
            Log::error('Error fetching candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch candidate'], 500);
        }
    }

    /**
     * Update the specified candidate (filtered by organization)
     */
    public function update(Request $request, Candidate $candidate)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify candidate belongs to organization
            if ($candidate->jobOpening && $candidate->jobOpening->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            // Verify new job opening belongs to organization
            if ($request->has('job_opening_id')) {
                $jobOpening = JobOpening::where('id', $request->job_opening_id)
                    ->where('organization_id', $organizationId)
                    ->first();
                    
                if (!$jobOpening) {
                    return response()->json(['error' => 'Job opening not found'], 404);
                }
            }
            
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

            // Handle file uploads
            if ($request->hasFile('attachments')) {
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
            
        } catch (\Exception $e) {
            Log::error('Error updating candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update candidate'], 500);
        }
    }

    /**
     * Remove the specified candidate (filtered by organization)
     */
    public function destroy(Candidate $candidate)
    {
        try {
            $user = Auth::user();
            $organizationId = $user->organization_id;
            
            // Verify candidate belongs to organization
            if ($candidate->jobOpening && $candidate->jobOpening->organization_id !== $organizationId) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            
            // Delete associated files
            if ($candidate->attachments) {
                Storage::disk('public')->delete($candidate->attachments);
            }
            if ($candidate->avatar) {
                Storage::disk('public')->delete($candidate->avatar);
            }

            $candidate->delete();

            return response()->json(['message' => 'Candidate deleted successfully']);
            
        } catch (\Exception $e) {
            Log::error('Error deleting candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete candidate'], 500);
        }
    }
}