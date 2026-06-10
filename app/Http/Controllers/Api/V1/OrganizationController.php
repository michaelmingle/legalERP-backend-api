<?php
// app/Http/Controllers/Api/V1/OrganizationController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeamInvite;
use App\Models\Organization;
use Illuminate\Http\Request;
use App\Mail\TeamInviteMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;


class OrganizationController extends Controller
{

    public function index(Request $request)
    {
        try {
            $query = Organization::query();
            
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }
            
            if ($request->has('search') && !empty($request->search)) {
                $search = $request->search;
                $query->where('name', 'like', "%{$search}%")
                      ->orWhere('organisation_email', 'like', "%{$search}%");
            }
            
            $organizations = $query->latest()->get();
            
            return response()->json([
                'success' => true,
                'data' => $organizations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    // Add other methods as needed
    public function show($id)
    {
        try {
            $org = Organization::findOrFail($id);
            return response()->json([
                'success' => true,
                'data' => $org
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function updateStatus(Request $request, $id)
    {
        try {
            $org = Organization::findOrFail($id);
            $org->status = $request->status;
            $org->save();
            
            return response()->json([
                'success' => true,
                'message' => 'Status updated successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function destroy($id)
    {
        try {
            $org = Organization::findOrFail($id);
            $org->delete();
            
            return response()->json([
                'success' => true,
                'message' => 'Organization deleted successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
    
    public function list()
    {
        try {
            $organizations = Organization::select('id', 'name')->get();
            
            return response()->json([
                'success' => true,
                'data' => $organizations
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }


    public function updateGoals(Request $request)
    {
        $request->validate([
            'goals' => 'required|array',
            'goals.*' => 'string|max:255',
        ]);

        $user = $request->user();
        $org = $user->organization;

        if (!$user->isOwner()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $org->goals = $request->goals;
        $org->save();

        return response()->json($org);
    }

    public function sendInvite(Request $request)
    {
        $request->validate([
            'email' => 'required|email|distinct',
        ]);

        $user = $request->user();
        $org = $user->organization;

        if (!$user->isOwner() && $user->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if already invited or member
        $existingInvite = TeamInvite::where('organization_id', $org->id)
            ->where('email', $request->email)
            ->first();
            
        if ($existingInvite && $existingInvite->status === 'pending' && !$existingInvite->isExpired()) {
            return response()->json(['message' => 'Invite already sent'], 422);
        }

        $invite = TeamInvite::updateOrCreate(
            ['organization_id' => $org->id, 'email' => $request->email],
            [
                'status' => 'pending',
                'token' => Str::random(64),
                'expires_at' => now()->addDays(7)
            ]
        );

        // Send email
        try {
            Mail::to($invite->email)->send(new TeamInviteMail($invite, $org));
            
            return response()->json([
                'success' => true,
                'message' => 'Invitation sent successfully',
                'data' => $invite
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send invitation: ' . $e->getMessage()
            ], 500);
        }
    }

    public function revokeInvite(Request $request, TeamInvite $invite)
    {
        $user = $request->user();

        if ($invite->organization_id !== $user->organization_id || (!$user->isOwner() && $user->role !== 'admin')) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invite->delete();

        return response()->json(['message' => 'Invite revoked']);
    }
}