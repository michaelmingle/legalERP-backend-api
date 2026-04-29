<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Organization;
use App\Models\TeamInvite;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use App\Traits\LogsActivity;
use App\Mail\TeamInviteMail;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class AuthController extends Controller
{
    use LogsActivity;

    public function index(Request $request)
{
    $user = Auth::user();
    $organizationId = $user->organization_id;
    
    $users = User::where('organization_id', $organizationId)->get();
    return response()->json($users);
}

    public function getCurrentUser(Request $request)
{
    try {
        $user = $request->user();
        
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Unauthorized - User not authenticated'
            ], 401);
        }
        
        // Ensure role is set, default to 'employee' if null
        if (!$user->role) {
            $user->role = 'employee';
            $user->save();
        }
        
        // Return the user data with role explicitly included
        return response()->json([
            'success' => true,
            'data' => [
                'id' => $user->id,
                'first_name' => $user->first_name,
                'last_name' => $user->last_name,
                'email' => $user->email,
                'username' => $user->username,
                'role' => $user->role,
                'photo_url' => $user->photo_url,
                'organization_id' => $user->organization_id,
                'status' => $user->status,
                'created_at' => $user->created_at,
                'updated_at' => $user->updated_at,
            ]
        ], 200);
        $this->logActivity('get_current_user', 'User Management', $user->id, $user->email);
    } catch (\Exception $e) {
        Log::error('Error in getCurrentUser: ' . $e->getMessage());
        
        return response()->json([
            'success' => false,
            'message' => 'Failed to get current user: ' . $e->getMessage()
        ], 500);
    }
}
    
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
                    $invite = TeamInvite::create([
                        'organization_id' => $organization->id,
                        'email' => $email,
                        'token' => Str::random(64),
                        'status' => 'pending',
                        'expires_at' => now()->addDays(7),
                    ]);
                    
                    // Send email invitation
                    try {
                        Mail::to($email)->send(new TeamInviteMail($invite, $organization));
                    } catch (\Exception $e) {
                        Log::error('Failed to send invite to ' . $email . ': ' . $e->getMessage());
                    }
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

        $user->update([
            'last_login_at' => now(),
            'last_activity_at' => now(),
        ]);

        $this->logActivity('login', 'Authentication', $user->id, $user->email);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'access_token' => $token,
            'token_type' => 'Bearer',
            'user' => $user,
            'organization' => $user->organization, // include organization details
        ]);
    }

    public function getOnlineStatus($id)
    {
        $user = User::find($id);
        return response()->json(['is_online' => $user ? $user->is_online : false]);
    }

    // store new user (admin only)
    public function store(Request $request)
    {
        $request->validate([
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|string|email|max:255|unique:users',
            'password'   => 'required|string|min:8|confirmed',
            'role'       => 'required|in:admin,lawyer,paralegal,staff,client,hr,owner',
            'photo_url'     => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048', // 2MB max
        ]);

        // Handle avatar upload
        $photoUrl = null;
        if ($request->hasFile('photo_url')) {
            $path = $request->file('photo_url')->store('avatars', 'public');
            $photoUrl = asset('storage/' . $path);
        }

        $user = User::create([
            'organization_id' => $request->user()->organization_id, // from authenticated user
            'first_name'      => $request->first_name,
            'last_name'       => $request->last_name,
            'email'           => $request->email,
            'password'        => Hash::make($request->password),
            'address'         => $request->address,
            'role'            => $request->role,
            'status'          => 'active', // default status
            'photo_url'       => $photoUrl, // 👈 save the URL
        ]);

        // Load relationship if needed
        $user->load('organization');

        return response()->json($user, 201);
    }

    // update user (admin only)
    public function update(Request $request, User $user)
    {
        // Ensure user belongs to same org
        if ($user->organization_id !== $request->user()->organization_id) {
            return response()->json(['message' => 'Unauthorized'], 403);
        }

        $request->validate([
            'first_name' => 'sometimes|required|string|max:255',
            'last_name'  => 'sometimes|required|string|max:255',
            'email'      => 'sometimes|required|string|email|max:255|unique:users,email,' . $user->id,
            'role'       => 'sometimes|required|in:admin,lawyer,paralegal,staff,client,hr,owner',
            'status'     => 'sometimes|required|in:active,inactive,suspended,invited',
            'phone'      => 'nullable|string|max:20',
            'address'    => 'nullable|string|max:255',
            'avatar'     => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        // Handle avatar upload
        if ($request->hasFile('avatar')) {
            // Delete old avatar if exists
            if ($user->photo_url) {
                $oldPath = str_replace(asset('storage/'), '', $user->photo_url);
                Storage::disk('public')->delete($oldPath);
            }
            $path = $request->file('avatar')->store('avatars', 'public');
            $photoUrl = asset('storage/' . $path);
        }

        $user->update([
            'first_name' => $request->first_name ?? $user->first_name,
            'last_name'  => $request->last_name ?? $user->last_name,
            'email'      => $request->email ?? $user->email,
            'role'       => $request->role ?? $user->role,
            'status'     => $request->status ?? $user->status,
            'phone'      => $request->phone ?? $user->phone,
            'address'    => $request->address ?? $user->address,
            'photo_url'  => $photoUrl ?? $user->photo_url,
        ]);

        return response()->json($user);
    }

    // delete user (admin only)
    public function destroy(Request $request, User $user)
    {
        $user->delete();
        return response()->json(['message' => 'User deleted']);
    }

    // Logout
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();
        $this->logActivity('logout', 'Authentication', $request->user()->id, $request->user()->email);

        return response()->json(['message' => 'Logged out successfully']);
    }

    // Get authenticated user (alternative method)
    public function user(Request $request)
    {
        try {
            $user = $request->user();
            
            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Unauthorized'
                ], 401);
            }
            
            $this->logActivity('get_current_user', 'User Management', $user->id, $user->email);
            return response()->json([
                'success' => true,
                'data' => $user
            ], 200);
            
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage()
            ], 500);
        }
    }
}