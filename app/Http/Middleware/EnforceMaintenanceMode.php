<?php

namespace App\Http\Middleware;

use App\Services\SettingsService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class EnforceMaintenanceMode
{
    public function __construct(private SettingsService $settings) {}

    public function handle(Request $request, Closure $next): Response
    {
        $maintenance = (bool) $this->settings->systemGet('maintenance_mode', false);

        if (!$maintenance) {
            return $next($request);
        }

        // Always allow the public app-config probe and login/logout, so the UI
        // can show a maintenance banner and super admins can still authenticate.
        $alwaysAllow = [
            'api/app-config',
            'api/login',
            'api/logout',
            'api/me',
        ];

        $path = $request->path();
        foreach ($alwaysAllow as $allowed) {
            if ($path === $allowed) {
                return $next($request);
            }
        }

        // Super admins bypass maintenance mode.
        $user = Auth::user() ?: $request->user('sanctum');
        if ($user && $user->role === 'super_admin') {
            return $next($request);
        }

        return response()->json([
            'success' => false,
            'maintenance_mode' => true,
            'message' => 'The system is undergoing maintenance. Please try again shortly.',
        ], 503);
    }
}
