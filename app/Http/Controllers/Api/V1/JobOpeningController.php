<?php
namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\JobOpening;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class JobOpeningController extends Controller
{
    /**
     * Display a listing of job openings.
     */
    public function index(Request $request)
    {
        $query = JobOpening::query();

        // Optional search by title
        if ($request->has('search')) {
            $search = $request->search;
            $query->where('job_title', 'like', "%{$search}%");
        }

        $jobOpenings = $query->latest()->paginate(15);

        return response()->json($jobOpenings);
    }

    /**
     * Store a newly created job opening.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'job_title'           => 'required|string|max:255',
            'description'         => 'required|string',
            'number_of_openings'  => 'required|integer|min:1',
            'location'            => 'nullable|string|max:255',
            'posting_date'        => 'required|date',
            'closing_date'        => 'required|date|after_or_equal:posting_date',
        ]);

        $jobOpening = JobOpening::create($validated);

        return response()->json([
            'message' => 'Job opening created successfully',
            'job'     => $jobOpening
        ], 201);
    }

    /**
     * Display the specified job opening.
     */
    public function show(JobOpening $jobOpening)
    {
        return response()->json($jobOpening);
    }

    /**
     * Update the specified job opening.
     */
    public function update(Request $request, JobOpening $jobOpening)
    {
        $validated = $request->validate([
            'job_title'           => 'sometimes|string|max:255',
            'description'         => 'sometimes|string',
            'number_of_openings'  => 'sometimes|integer|min:1',
            'location'            => 'nullable|string|max:255',
            'posting_date'        => 'sometimes|date',
            'closing_date'        => 'sometimes|date|after_or_equal:posting_date',
        ]);

        $jobOpening->update($validated);

        return response()->json([
            'message' => 'Job opening updated successfully',
            'job'     => $jobOpening
        ]);
    }

    /**
     * Remove the specified job opening.
     */
    public function destroy(JobOpening $jobOpening)
    {
        $jobOpening->delete();

        return response()->json(['message' => 'Job opening deleted successfully']);
    }
}