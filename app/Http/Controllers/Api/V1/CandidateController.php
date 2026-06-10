<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Candidate;
use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class CandidateController extends Controller
{
    /** Whether the candidates table is org-scoped. */
    private function candidatesHaveOrg(): bool
    {
        return Schema::hasColumn('candidates', 'organization_id');
    }

    /** Whether the job_openings table is org-scoped. */
    private function jobsHaveOrg(): bool
    {
        return Schema::hasColumn('job_openings', 'organization_id');
    }

    private function scopeForOrg($query, ?int $organizationId)
    {
        if (!$organizationId) return $query;

        if ($this->candidatesHaveOrg()) {
            $query->where(function ($q) use ($organizationId) {
                $q->where('candidates.organization_id', $organizationId)
                  ->orWhereNull('candidates.organization_id'); // legacy rows
            });
        } elseif ($this->jobsHaveOrg()) {
            $query->whereHas('jobOpening', function ($q) use ($organizationId) {
                $q->where('organization_id', $organizationId);
            });
        }
        // else: no org scoping available; return all rows.
        return $query;
    }

    public function index(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user?->organization_id;

            $query = $this->scopeForOrg(Candidate::query(), $organizationId)->with('jobOpening');

            if ($request->filled('stage')) {
                $query->where('stage', $request->stage);
            }
            if ($request->filled('role')) {
                $query->where('role', $request->role);
            }
            if ($request->filled('search')) {
                $search = $request->search;
                $query->where(function ($q) use ($search) {
                    $q->where('full_name', 'like', "%{$search}%")
                      ->orWhere('email', 'like', "%{$search}%");
                });
            }
            if ($request->filled('job_opening_id')) {
                $query->where('job_opening_id', $request->job_opening_id);
            }

            $candidates = $query->latest('date_applied')->paginate(20);

            $candidates->getCollection()->transform(function ($candidate) {
                return [
                    'id'                => $candidate->id,
                    'name'              => $candidate->full_name,
                    'email'             => $candidate->email,
                    'role'              => $candidate->role,
                    'date'              => optional($candidate->date_applied)->format('d/m/Y'),
                    'attachments'       => $candidate->attachments ? 1 : 0,
                    'avatar'            => $candidate->avatar
                        ? (str_starts_with($candidate->avatar, 'http') ? $candidate->avatar : asset('storage/' . $candidate->avatar))
                        : null,
                    'status'            => ucfirst((string) $candidate->stage),
                    'stage'             => $candidate->stage,
                    'job_opening_id'    => $candidate->job_opening_id,
                    'job_opening_title' => $candidate->jobOpening?->job_title,
                ];
            });

            return response()->json($candidates);
        } catch (\Throwable $e) {
            Log::error('Error fetching candidates: ' . $e->getMessage());
            return response()->json([
                'error' => 'Failed to fetch candidates',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $user = Auth::user();
            $organizationId = $user?->organization_id;

            if ($request->filled('job_opening_id') && $this->jobsHaveOrg()) {
                $jobOpening = JobOpening::where('id', $request->job_opening_id)
                    ->where(function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId)
                          ->orWhereNull('organization_id');
                    })
                    ->first();

                if (!$jobOpening) {
                    return response()->json(['error' => 'Job opening not found'], 404);
                }
            }

            $validated = $request->validate([
                'full_name'      => 'required|string|max:255',
                'email'          => 'required|email|unique:candidates,email',
                'role'           => 'required|string|max:255',
                'date_applied'   => 'required|date',
                'attachments'    => 'nullable|file|mimes:pdf,doc,docx|max:5120',
                'stage'          => ['required', Rule::in(['applied', 'reviewing', 'interview', 'hired', 'rejected'])],
                'avatar'         => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'job_opening_id' => 'nullable|exists:job_openings,id',
            ]);

            if ($request->hasFile('attachments')) {
                $validated['attachments'] = $request->file('attachments')->store('candidates/attachments', 'public');
            }
            if ($request->hasFile('avatar')) {
                $validated['avatar'] = $request->file('avatar')->store('candidates/avatars', 'public');
            }

            if ($this->candidatesHaveOrg() && $organizationId) {
                $validated['organization_id'] = $organizationId;
            }

            $candidate = Candidate::create($validated);

            return response()->json([
                'message'   => 'Candidate added successfully',
                'candidate' => $candidate->load('jobOpening'),
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Error creating candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create candidate', 'detail' => $e->getMessage()], 500);
        }
    }

    public function show(Candidate $candidate)
    {
        try {
            $organizationId = Auth::user()?->organization_id;
            if (!$this->candidateBelongsToOrg($candidate, $organizationId)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            return response()->json($candidate->load('jobOpening'));
        } catch (\Throwable $e) {
            Log::error('Error fetching candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch candidate', 'detail' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, Candidate $candidate)
    {
        try {
            $organizationId = Auth::user()?->organization_id;

            if (!$this->candidateBelongsToOrg($candidate, $organizationId)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if ($request->filled('job_opening_id') && $this->jobsHaveOrg()) {
                $jobOpening = JobOpening::where('id', $request->job_opening_id)
                    ->where(function ($q) use ($organizationId) {
                        $q->where('organization_id', $organizationId)
                          ->orWhereNull('organization_id');
                    })
                    ->first();
                if (!$jobOpening) {
                    return response()->json(['error' => 'Job opening not found'], 404);
                }
            }

            $validated = $request->validate([
                'full_name'      => 'sometimes|string|max:255',
                'email'          => ['sometimes', 'email', Rule::unique('candidates')->ignore($candidate->id)],
                'role'           => 'sometimes|string|max:255',
                'date_applied'   => 'sometimes|date',
                'attachments'    => 'nullable|file|mimes:pdf,doc,docx|max:5120',
                'stage'          => ['sometimes', Rule::in(['applied', 'reviewing', 'interview', 'hired', 'rejected'])],
                'avatar'         => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'job_opening_id' => 'nullable|exists:job_openings,id',
            ]);

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

            // Backfill org_id on legacy rows
            if ($this->candidatesHaveOrg() && $organizationId && $candidate->organization_id === null) {
                $candidate->organization_id = $organizationId;
                $candidate->saveQuietly();
            }

            return response()->json([
                'message'   => 'Candidate updated successfully',
                'candidate' => $candidate->fresh()->load('jobOpening'),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error updating candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update candidate', 'detail' => $e->getMessage()], 500);
        }
    }

    public function destroy(Candidate $candidate)
    {
        try {
            $organizationId = Auth::user()?->organization_id;
            if (!$this->candidateBelongsToOrg($candidate, $organizationId)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            if ($candidate->attachments) {
                Storage::disk('public')->delete($candidate->attachments);
            }
            if ($candidate->avatar) {
                Storage::disk('public')->delete($candidate->avatar);
            }

            $candidate->delete();

            return response()->json(['message' => 'Candidate deleted successfully']);
        } catch (\Throwable $e) {
            Log::error('Error deleting candidate: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to delete candidate', 'detail' => $e->getMessage()], 500);
        }
    }

    /** True when the candidate is visible to the org (or any tolerated NULL case). */
    private function candidateBelongsToOrg(Candidate $candidate, ?int $organizationId): bool
    {
        if (!$organizationId) return true;

        if ($this->candidatesHaveOrg()) {
            return $candidate->organization_id === null
                || (int) $candidate->organization_id === (int) $organizationId;
        }

        if ($this->jobsHaveOrg() && $candidate->jobOpening) {
            return $candidate->jobOpening->organization_id === null
                || (int) $candidate->jobOpening->organization_id === (int) $organizationId;
        }

        return true; // no scoping available
    }
}
