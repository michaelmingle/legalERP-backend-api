<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeamInvite;
use Illuminate\Http\Request;

class OrganizationController extends Controller
{
    public function updateGoals(Request $request)
    {
        $request->validate([
            'goals' => 'required|array',
            'goals.*' => 'string|max:255',
        ]);

        $user = $request->user();
        $org = $user->organization;

        // Only owner can update goals
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

        if (!$user->isOwner()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if already invited or member
        $existing = $org->invites()->where('email', $request->email)->first();
        if ($existing && $existing->status !== 'expired') {
            return response()->json(['message' => 'Invite already sent'], 422);
        }

        $invite = TeamInvite::updateOrCreate(
            ['organization_id' => $org->id, 'email' => $request->email],
            ['status' => 'pending', 'token' => \Illuminate\Support\Str::random(32), 'expires_at' => now()->addDays(7)]
        );

        // TODO: Send email with accept link

        return response()->json($invite, 201);
    }

    public function revokeInvite(Request $request, TeamInvite $invite)
    {
        $user = $request->user();

        if ($invite->organization_id !== $user->organization_id || !$user->isOwner()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invite->delete();

        return response()->json(['message' => 'Invite revoked']);
    }
}