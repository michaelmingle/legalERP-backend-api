<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\TeamInvite;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TeamInviteController extends Controller
{
    public function accept($token)
    {
        $invite = TeamInvite::where('token', $token)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->first();

        if (!$invite) {
            return response()->json(['message' => 'Invalid or expired invite'], 404);
        }

        // If user already exists with this email, attach to organization
        $user = User::where('email', $invite->email)->first();

        if ($user) {
            $user->update(['organization_id' => $invite->organization_id]);
        } else {
            // Otherwise create a placeholder – they’ll finish registration later
            // For simplicity, we require them to register first, then accept.
            return response()->json([
                'message' => 'Invite accepted. Please create an account with this email.',
                'email' => $invite->email,
                'organization_id' => $invite->organization_id,
                'token' => $invite->token, // we can reuse token to verify after registration
            ]);
        }

        $invite->update(['status' => 'accepted']);

        return response()->json(['message' => 'You are now part of the organization']);
    }
}