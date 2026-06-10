<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Organization;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class AppConfigController extends Controller
{
    public function __construct(private SettingsService $settings) {}

    /**
     * Public app config — system_name, branding, public flags.
     * Safe to call without auth. When the user IS authenticated their
     * organization's settings are folded in.
     */
    public function index(Request $request)
    {
        $sys = $this->settings->system();

        $data = [
            'system_name'       => $sys['system_name']      ?? config('app.name', 'Legal ERP'),
            'language'          => $sys['language']         ?? 'en',
            'timezone'          => $sys['timezone']         ?? config('app.timezone', 'UTC'),
            'date_format'       => $sys['date_format']      ?? 'YYYY-MM-DD',
            'time_format'       => $sys['time_format']      ?? '24h',
            'currency'          => $sys['currency']         ?? 'USD',
            'maintenance_mode'  => (bool) ($sys['maintenance_mode'] ?? false),
            'require_strong_password' => (bool) ($sys['require_strong_password'] ?? false),
            'min_password_length'     => (int)  ($sys['min_password_length'] ?? 8),
            'session_timeout_minutes' => (int)  ($sys['session_timeout']     ?? 0),
            'api_rate_limit_per_min'  => (int)  ($sys['api_rate_limit']      ?? 600),
            'allowed_domains'         => is_array($sys['allowed_domains'] ?? null) ? $sys['allowed_domains'] : [],
            'logo_url'          => $sys['logo_url'] ?? null,
            'favicon_url'       => $sys['favicon_url'] ?? null,
        ];

        $user = Auth::user();
        if ($user && $user->organization_id) {
            $org = $this->settings->org($user->organization_id);
            $organization = Organization::find($user->organization_id);

            $data['organization'] = [
                'id'        => $organization?->id,
                'name'      => $organization?->name,
                'email'     => $organization?->organisation_email ?? $organization?->email,
                'logo_url'  => $organization?->logo_url,
                'timezone'  => $org['timezone']    ?? $data['timezone'],
                'currency'  => $org['currency']    ?? $data['currency'],
                'language'  => $org['language']    ?? $data['language'],
                'date_format' => $org['date_format'] ?? $data['date_format'],
                'time_format' => $org['time_format'] ?? $data['time_format'],
            ];

            // Org overrides win when present
            foreach (['timezone', 'currency', 'language', 'date_format', 'time_format'] as $key) {
                if (!empty($org[$key])) {
                    $data[$key] = $org[$key];
                }
            }
        }

        return response()->json([
            'success' => true,
            'data'    => $data,
        ]);
    }
}
