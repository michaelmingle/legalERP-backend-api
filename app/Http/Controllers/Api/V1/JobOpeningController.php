<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class JobOpeningController extends Controller
{
    private function jobsHaveOrg(): bool
    {
        return Schema::hasColumn('job_openings', 'organization_id');
    }

    public function index(Request $request)
    {
        try {
            $orgId = Auth::user()?->organization_id;
            $query = JobOpening::query();

            if ($this->jobsHaveOrg() && $orgId) {
                $query->where(function ($q) use ($orgId) {
                    $q->where('organization_id', $orgId)->orWhereNull('organization_id');
                });
            }

            if ($request->filled('search')) {
                $search = $request->search;
                $query->where('job_title', 'like', "%{$search}%");
            }

            return response()->json($query->latest()->paginate(15));
        } catch (\Throwable $e) {
            Log::error('Error fetching job openings: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to fetch job openings', 'detail' => $e->getMessage()], 500);
        }
    }

    public function store(Request $request)
    {
        try {
            $validated = $request->validate([
                'job_title'           => 'required|string|max:255',
                'description'         => 'required|string',
                'number_of_openings'  => 'required|integer|min:1',
                'location'            => 'nullable|string|max:255',
                'posting_date'        => 'required|date',
                'closing_date'        => 'required|date|after_or_equal:posting_date',
            ]);

            if ($this->jobsHaveOrg() && Auth::user()?->organization_id) {
                $validated['organization_id'] = Auth::user()->organization_id;
            }

            $jobOpening = JobOpening::create($validated);

            return response()->json([
                'message' => 'Job opening created successfully',
                'job'     => $jobOpening,
            ], 201);
        } catch (\Throwable $e) {
            Log::error('Error creating job opening: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to create job opening', 'detail' => $e->getMessage()], 500);
        }
    }

    public function show(JobOpening $jobOpening)
    {
        try {
            if (!$this->jobBelongsToOrg($jobOpening)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            return response()->json($jobOpening);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    public function update(Request $request, JobOpening $jobOpening)
    {
        try {
            if (!$this->jobBelongsToOrg($jobOpening)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }

            $validated = $request->validate([
                'job_title'           => 'sometimes|string|max:255',
                'description'         => 'sometimes|string',
                'number_of_openings'  => 'sometimes|integer|min:1',
                'location'            => 'nullable|string|max:255',
                'posting_date'        => 'sometimes|date',
                'closing_date'        => 'sometimes|date|after_or_equal:posting_date',
            ]);

            $jobOpening->update($validated);

            if ($this->jobsHaveOrg() && Auth::user()?->organization_id && $jobOpening->organization_id === null) {
                $jobOpening->organization_id = Auth::user()->organization_id;
                $jobOpening->saveQuietly();
            }

            return response()->json([
                'message' => 'Job opening updated successfully',
                'job'     => $jobOpening->fresh(),
            ]);
        } catch (\Throwable $e) {
            Log::error('Error updating job opening: ' . $e->getMessage());
            return response()->json(['error' => 'Failed to update job opening', 'detail' => $e->getMessage()], 500);
        }
    }

    public function destroy(JobOpening $jobOpening)
    {
        try {
            if (!$this->jobBelongsToOrg($jobOpening)) {
                return response()->json(['error' => 'Unauthorized'], 403);
            }
            $jobOpening->delete();
            return response()->json(['message' => 'Job opening deleted successfully']);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 500);
        }
    }

    private function jobBelongsToOrg(JobOpening $job): bool
    {
        $orgId = Auth::user()?->organization_id;
        if (!$orgId || !$this->jobsHaveOrg()) return true;
        return $job->organization_id === null
            || (int) $job->organization_id === (int) $orgId;
    }
}
