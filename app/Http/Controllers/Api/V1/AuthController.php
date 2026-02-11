<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Organization;
use App\Models\TeamInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    // Register
    public function register(Request $request)
    {
        $validated = $request->validate([
            // User fields
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|string|email|max:255|unique:users',
            'password'   => 'required|string|min:8|confirmed',

            // Organization fields
            'name'    => 'required|string|max:255',
            'phone'   => 'nullable|string|max:20',
            'organisation_email'   => 'nullable|email|max:255',
            'website' => 'nullable|url|max:255',
            'team'    => 'nullable|string|max:255',

            // Goals (array of strings or IDs)
            'goals' => 'nullable|array',
            'goals.*' => 'string|max:255',

            // Team invites (array of emails)
            'invites' => 'nullable|array',
            'invites.*' => 'email|distinct',
        ]);

        return DB::transaction(function () use ($validated) {
            // 1. Create Organization
            $organization = Organization::create([
                'name'    => $validated['name'],
                'phone'   => $validated['phone'] ?? null,
                'organisation_email'   => $validated['email'] ?? null,
                'website' => $validated['website'] ?? null,
                'goals'   => $validated['goals'] ?? [], // store as JSON
                'team'    => $validated['team'] ?? null,
            ]);

            // 2. Create User (as owner)
            $user = User::create([
                'organization_id' => $organization->id,
                'first_name'      => $validated['first_name'],
                'last_name'       => $validated['last_name'],
                'email'           => $validated['email'],
                'password'        => Hash::make($validated['password']),
                'role'            => 'owner', // mark as owner
                'status'          => 'active',
                'onboarding_completed' => true,
            ]);

            // 3. Set organization owner
            $organization->owner_id = $user->id;
            $organization->save();

            // 4. Create team invites
            if (!empty($validated['invites'])) {
                foreach ($validated['invites'] as $email) {
                    TeamInvite::create([
                        'organization_id' => $organization->id,
                        'email'           => $email,
                    ]);
                }
            }

            // 5. Generate token
            $token = $user->createToken('auth_token')->plainTextToken;

            return response()->json([
                'access_token' => $token,
                'token_type'   => 'Bearer',
                'user'         => $user->load('organization'), // eager load org
                'organization' => $organization,
                'invites'      => $organization->invites, // return created invites
            ], 201);
        });
    }

    // Login
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
        'access_token' => $token,
        'token_type'   => 'Bearer',
        'user'         => [
            'id'                   => $user->id,
            'first_name'           => $user->first_name,
            'last_name'            => $user->last_name,
            'email'                => $user->email,
            'organization_id'      => $user->organization_id,
            'role'                 => $user->role,
            'onboarding_completed' => $user->onboarding_completed, // 👈 include
        ],
    ]);
    }

    // Logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json(['message' => 'Logged out successfully']);
    }

    // Get authenticated user
    public function user(Request $request)
    {
        return response()->json($request->user());
    }
}