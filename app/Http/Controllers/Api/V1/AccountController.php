<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class AccountController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /** Update profile fields the current user is allowed to change about themselves. */
    public function updateProfile(Request $request)
    {
        $user = $request->user();

        $validated = $request->validate([
            'first_name' => 'sometimes|string|max:255',
            'last_name'  => 'sometimes|string|max:255',
            'email'      => ['sometimes', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'phone'      => 'sometimes|nullable|string|max:32',
            'address'    => 'sometimes|nullable|string|max:255',
        ]);

        $user->fill($validated);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Profile updated',
            'data'    => $user->fresh(),
        ]);
    }

    /** Change own password — honours system password policy. */
    public function updatePassword(Request $request)
    {
        $user = $request->user();

        $sys = $this->settings->system();
        $minLength  = max(8, (int) ($sys['min_password_length'] ?? 8));
        $strong     = (bool) ($sys['require_strong_password'] ?? false);

        $rules = [
            'current_password' => 'required|string',
            'password'         => "required|string|min:{$minLength}|confirmed",
        ];

        // Strong-password rule: at least one upper, lower, digit, symbol.
        if ($strong) {
            $rules['password'] .= '|regex:/^(?=.*[A-Z])(?=.*[a-z])(?=.*\d)(?=.*[^A-Za-z0-9]).+$/';
        }

        $validated = $request->validate($rules, [
            'password.regex' => 'Password must contain uppercase, lowercase, number and symbol.',
        ]);

        if (!Hash::check($validated['current_password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Current password is incorrect.',
            ], 422);
        }

        $user->password = Hash::make($validated['password']);
        // Track password change date if column exists (used for expiry policy).
        if (Schema()->hasColumn('users', 'password_changed_at')) {
            $user->password_changed_at = now();
        }
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'Password updated successfully.',
        ]);
    }

    /** Return all personal preferences (per-user settings rows). */
    public function getPreferences(Request $request)
    {
        $user = $request->user();

        $rows = Setting::where('group', 'user_preferences')
            ->where('key', 'like', "user_{$user->id}_%")
            ->get();

        $prefs = [
            'email_notifications'    => true,
            'push_notifications'     => true,
            'sound_notifications'    => true,
            'case_assigned_notify'   => true,
            'payment_received_notify'=> true,
            'document_upload_notify' => true,
            'daily_digest'           => false,
            'theme'                  => 'light',
            'language'               => null,
            'timezone'               => null,
        ];

        foreach ($rows as $r) {
            $shortKey = substr($r->key, strlen("user_{$user->id}_"));
            $prefs[$shortKey] = $this->castValue($r);
        }

        return response()->json([
            'success' => true,
            'data'    => $prefs,
        ]);
    }

    /** Save personal preferences. */
    public function updatePreferences(Request $request)
    {
        $user = $request->user();

        $allowed = [
            'email_notifications', 'push_notifications', 'sound_notifications',
            'case_assigned_notify', 'payment_received_notify', 'document_upload_notify',
            'daily_digest', 'theme', 'language', 'timezone',
        ];

        $payload = collect($request->all())->only($allowed)->toArray();

        foreach ($payload as $key => $value) {
            $type = is_bool($value) ? 'boolean'
                  : (is_int($value) || (is_numeric($value) && (string) (int) $value === (string) $value) ? 'integer'
                  : (is_array($value) ? 'json' : 'string'));

            $stored = is_array($value) ? json_encode($value)
                    : (is_bool($value) ? ($value ? 'true' : 'false') : (string) $value);

            Setting::updateOrCreate(
                [
                    'key'             => "user_{$user->id}_{$key}",
                    'group'           => 'user_preferences',
                    'organization_id' => $user->organization_id,
                ],
                ['value' => $stored, 'type' => $type]
            );
        }

        $this->settings->forget($user->organization_id);

        return response()->json([
            'success' => true,
            'message' => 'Preferences saved',
        ]);
    }

    /** Upload an avatar / profile photo. */
    public function uploadAvatar(Request $request)
    {
        $request->validate([
            'avatar' => 'required|image|mimes:jpeg,png,jpg,gif|max:4096',
        ]);

        $user = $request->user();
        $path = $request->file('avatar')->store('avatars', 'public');
        $url  = asset('storage/' . $path);

        $user->photo_url = $url;
        $user->save();

        return response()->json([
            'success'   => true,
            'message'   => 'Avatar updated',
            'photo_url' => $url,
        ]);
    }

    private function castValue(Setting $s)
    {
        $v = $s->value;
        return match ($s->type) {
            'boolean' => filter_var($v, FILTER_VALIDATE_BOOLEAN),
            'integer' => is_numeric($v) ? (int) $v : 0,
            'json'    => is_string($v) ? json_decode($v, true) : $v,
            default   => $v,
        };
    }
}

/** Tiny helper so the controller stays readable. */
function Schema()
{
    return \Illuminate\Support\Facades\Schema::getFacadeRoot();
}
