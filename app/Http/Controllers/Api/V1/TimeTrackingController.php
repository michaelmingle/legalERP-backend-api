<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TimeTracking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class TimeTrackingController extends Controller
{
    /**
     * Display a listing of the resource.
     */
    public function index()
    {
        $timeTrackings = TimeTracking::with('case')
        ->whereHas('case', function ($query) {
            $query->where('assigned_to', Auth::id());
        })
        ->latest()
        ->get();

        return response()->json($timeTrackings);
    }

    public function getTimeTracking() 
    {
        $timeTrackings = TimeTracking::with('case')
        ->whereHas('case', function ($query) {
            $query->where('assigned_to', Auth::id());
        })
        ->latest()
        ->get();

        return response()->json($timeTrackings);
    }

    /**
     * Show the form for creating a new resource.
     */
    public function create()
    {
        //
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'case_id' => 'required|exists:cases,id',
            'description' => 'nullable|string',
            'hours' => 'required|numeric',
            'date' => 'required|date',
            'status' => 'required|string'
        ]);

        $timeTracking = TimeTracking::create($request->all());

        return response()->json([
            'message' => 'Time tracking created successfully',
            'data' => $timeTracking
        ], 201);
    }

    /**
     * Display the specified resource.
     */
    public function show($id)
    {
        $timeTracking = TimeTracking::with('case')->findOrFail($id);

        return response()->json($timeTracking);
    }

    /**
     * Show the form for editing the specified resource.
     */
    public function edit(TimeTracking $timeTracking)
    {
        //
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, $id)
    {
         $timeTracking = TimeTracking::findOrFail($id);

        $request->validate([
            'case_id' => 'required|exists:cases,id',
            'description' => 'nullable|string',
            'hours' => 'required|numeric',
            'date' => 'required|date',
            'status' => 'required|string'
        ]);

        $timeTracking->update($request->all());

        return response()->json([
            'message' => 'Time tracking updated successfully',
            'data' => $timeTracking
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy($id)
    {
        $timeTracking = TimeTracking::findOrFail($id);
        $timeTracking->delete();

        return response()->json([
            'message' => 'Time tracking deleted successfully'
        ]);
    }
}
