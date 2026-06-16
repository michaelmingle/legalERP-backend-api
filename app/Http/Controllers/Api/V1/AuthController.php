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
        $sys = app(\App\Services\SettingsService::class)->system();
        $minLength = max(8, (int) ($sys['min_password_length'] ?? 8));
        $strong    = (bool) ($sys['require_strong_password'] ?? false);
        $passwordRule = "required|string|min:{$minLength}|confirmed";
        if ($strong) {
            $passwordRule .= '|regex:/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/';
        }

        // Enforce super-admin "allowed_domains" allow-list (if any).
        $allowedDomains = $sys['allowed_domains'] ?? [];
        if (is_array($allowedDomains) && !empty($allowedDomains)) {
            $email = strtolower((string) $request->input('email', ''));
            $domain = substr(strrchr($email, '@') ?: '', 1);
            $allowed = array_map(fn ($d) => strtolower(trim((string) $d)), $allowedDomains);
            $allowed = array_filter($allowed, fn ($d) => $d !== '');
            if (!empty($allowed) && !in_array($domain, $allowed, true)) {
                throw \Illuminate\Validation\ValidationException::withMessages([
                    'email' => ['Registrations are restricted to: ' . implode(', ', $allowed)],
                ]);
            }
        }

        $validated = $request->validate([
            // User fields
            'first_name' => 'required|string|max:255',
            'last_name'  => 'required|string|max:255',
            'email'      => 'required|string|email|max:255|unique:users',
            'password'   => $passwordRule,

            // Organization fields
            'name'    => 'required|string|max:255',
            'phone'   => 'nullable|string|max:20',
            'organisation_email'   => 'nullable|email|max:255',
            // Accept any website format (with or without scheme). We'll
            // normalize it below by prepending https:// if missing, then
            // sanity-check that it's a valid URL.
            'website' => 'nullable|string|max:255',
            'team'    => 'nullable|string|max:255',

            // Goals (array of strings or IDs)
            'goals' => 'nullable|array',
            'goals.*' => 'string|max:255',

            // Team invites (array of emails)
            'invites' => 'nullable|array',
            'invites.*' => 'email|distinct',
        ]);

        // Normalize the website: if the user typed "acme.com" we save
        // "https://acme.com" so links work everywhere they appear.
        if (!empty($validated['website'])) {
            $w = trim($validated['website']);
            if (!preg_match('#^https?://#i', $w)) {
                $w = 'https://' . ltrim($w, '/');
            }
            $validated['website'] = $w;
        }

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

    // Login (with system-setting-aware lockout)
    public function login(Request $request)
    {
        $request->validate([
            'email'    => 'required|email',
            'password' => 'required',
        ]);

        $settings = app(\App\Services\SettingsService::class)->system();
        $maxAttempts     = max(3, (int) ($settings['max_login_attempts'] ?? 5));
        $lockoutMinutes  = max(1, (int) ($settings['lockout_duration'] ?? 15));

        $cacheKey = 'login_attempts:' . sha1(strtolower($request->email) . '|' . $request->ip());
        $attempts = (int) cache()->get($cacheKey, 0);

        if ($attempts >= $maxAttempts) {
            throw ValidationException::withMessages([
                'email' => ["Too many failed attempts. Try again in {$lockoutMinutes} minutes."],
            ]);
        }

        $user = User::where('email', $request->email)->first();

        if (! $user || ! Hash::check($request->password, $user->password)) {
            cache()->put($cacheKey, $attempts + 1, now()->addMinutes($lockoutMinutes));
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        // Optional: refuse login for non-active users.
        if (isset($user->status) && in_array(strtolower($user->status ?? ''), ['suspended', 'inactive'])) {
            throw ValidationException::withMessages([
                'email' => ['This account is not active. Please contact your administrator.'],
            ]);
        }

        cache()->forget($cacheKey);

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