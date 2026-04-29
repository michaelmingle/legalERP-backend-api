<?php
// app/Http/Controllers/Api/V1/TeamInviteController.php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeamInvite;
use App\Models\User;
use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use App\Mail\TeamInviteMail;

class TeamInviteController extends Controller
{
    /**
     * Send an invitation to a team member
     */
    public function sendInvite(Request $request)
    {
        $request->validate([
            'email' => 'required|email|distinct',
        ]);

        $user = $request->user();
        $org = $user->organization;

        // Only owner and admins can send invites
        if (!$user->isOwner() && $user->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        // Check if user already exists and is already in this organization
        $existingUser = User::where('email', $request->email)->first();
        if ($existingUser && $existingUser->organization_id === $org->id) {
            return response()->json(['message' => 'User is already a member of this organization'], 422);
        }

        // Check if there's already a pending invite
        $existingInvite = TeamInvite::where('organization_id', $org->id)
            ->where('email', $request->email)
            ->where('status', 'pending')
            ->first();
            
        if ($existingInvite && !$existingInvite->isExpired()) {
            return response()->json(['message' => 'An invitation has already been sent to this email'], 422);
        }

        // Create new invite
        $invite = TeamInvite::create([
            'organization_id' => $org->id,
            'email' => $request->email,
            'token' => Str::random(64),
            'status' => 'pending',
            'expires_at' => now()->addDays(7),
        ]);

        // Send email notification
        try {
            Mail::to($invite->email)->send(new TeamInviteMail($invite, $org));
            
            return response()->json([
                'success' => true,
                'message' => 'Invitation sent successfully',
                'data' => $invite
            ], 201);
        } catch (\Exception $e) {
            // If email fails, still return the invite but note the error
            return response()->json([
                'success' => true,
                'message' => 'Invitation created but email could not be sent',
                'data' => $invite,
                'email_error' => $e->getMessage()
            ], 201);
        }
    }

    /**
     * Resend an invitation
     */
    public function resendInvite(Request $request, $inviteId)
    {
        $user = $request->user();
        $invite = TeamInvite::where('id', $inviteId)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (!$invite) {
            return response()->json(['message' => 'Invite not found'], 404);
        }

        if ($invite->isExpired()) {
            $invite->update([
                'token' => Str::random(64),
                'expires_at' => now()->addDays(7),
                'status' => 'pending'
            ]);
        }

        $org = $user->organization;
        
        try {
            Mail::to($invite->email)->send(new TeamInviteMail($invite, $org));
            
            return response()->json([
                'success' => true,
                'message' => 'Invitation resent successfully'
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to send email: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Accept an invitation
     */
    public function accept($token)
    {
        $invite = TeamInvite::where('token', $token)
            ->where('status', 'pending')
            ->first();

        if (!$invite) {
            return response()->json(['message' => 'Invalid invitation token'], 404);
        }

        if ($invite->isExpired()) {
            $invite->markAsExpired();
            return response()->json(['message' => 'Invitation has expired'], 410);
        }

        $user = User::where('email', $invite->email)->first();

        if ($user) {
            // User exists - add them to organization
            $user->update(['organization_id' => $invite->organization_id]);
            $invite->markAsAccepted();
            
            return response()->json([
                'success' => true,
                'message' => 'You have been added to the organization',
                'user_exists' => true,
                'redirect_url' => '/login'
            ]);
        } else {
            // User doesn't exist - return invitation data for registration
            return response()->json([
                'success' => true,
                'message' => 'Please complete your registration to join the organization',
                'user_exists' => false,
                'invitation' => [
                    'email' => $invite->email,
                    'organization_id' => $invite->organization_id,
                    'organization_name' => $invite->organization->name,
                    'token' => $invite->token,
                ],
                'redirect_url' => '/register'
            ]);
        }
    }

    /**
     * Complete registration from invitation
     */
    public function completeRegistration(Request $request)
    {
        $request->validate([
            'token' => 'required|string',
            'first_name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'password' => 'required|string|min:8|confirmed',
        ]);

        $invite = TeamInvite::where('token', $request->token)
            ->where('status', 'pending')
            ->first();

        if (!$invite) {
            return response()->json(['message' => 'Invalid or expired invitation'], 404);
        }

        if ($invite->isExpired()) {
            $invite->markAsExpired();
            return response()->json(['message' => 'Invitation has expired'], 410);
        }

        // Check if email matches the invite
        if ($invite->email !== $request->email) {
            return response()->json(['message' => 'Email does not match invitation'], 422);
        }

        // Create user
        $user = User::create([
            'first_name' => $request->first_name,
            'last_name' => $request->last_name,
            'email' => $invite->email,
            'password' => Hash::make($request->password),
            'organization_id' => $invite->organization_id,
            'role' => 'employee',
            'status' => 'active',
            'onboarding_completed' => true,
        ]);

        $invite->markAsAccepted();

        // Generate token
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Registration completed successfully',
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            'organization' => $user->organization,
        ], 201);
    }

    /**
     * Get all invites for the organization
     */
    public function getInvites(Request $request)
    {
        $user = $request->user();
        $invites = TeamInvite::where('organization_id', $user->organization_id)
            ->orderBy('created_at', 'desc')
            ->get();
            
        return response()->json([
            'success' => true,
            'data' => $invites
        ]);
    }

    /**
     * Revoke an invitation
     */
    public function revokeInvite(Request $request, $inviteId)
    {
        $user = $request->user();
        
        $invite = TeamInvite::where('id', $inviteId)
            ->where('organization_id', $user->organization_id)
            ->first();

        if (!$invite) {
            return response()->json(['message' => 'Invite not found'], 404);
        }

        // Only owner and admins can revoke invites
        if (!$user->isOwner() && $user->role !== 'admin') {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $invite->delete();

        return response()->json(['message' => 'Invite revoked successfully']);
    }
}