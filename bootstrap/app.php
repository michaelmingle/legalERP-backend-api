<?php

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use App\Http\Middleware\CorsMiddleware;
use App\Http\Middleware\CheckRole; // Add this line

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        then: function () {
            // Authenticated users get a generous limit (driven by the super-admin
            // "api_rate_limit" system setting, default 600/min). Unauthenticated
            // traffic stays tighter to limit abuse.
            RateLimiter::for('api', function (Request $request) {
                $user = $request->user();
                if ($user) {
                    $perMin = 600;
                    try {
                        $perMin = max(
                            60,
                            (int) app(\App\Services\SettingsService::class)
                                ->systemGet('api_rate_limit', 600)
                        );
                    } catch (\Throwable $e) {
                        // SettingsService not available at boot time — keep default.
                    }
                    return Limit::perMinute($perMin)->by('user:' . $user->id);
                }
                return Limit::perMinute(60)->by('ip:' . $request->ip());
            });
        }
    )
    ->withMiddleware(function (Middleware $middleware): void {

        // ✅ GLOBAL CORS MIDDLEWARE
        $middleware->append(CorsMiddleware::class);
        $middleware->append(\App\Http\Middleware\EnforceMaintenanceMode::class);

        $middleware->alias([
            'track.activity' => \App\Http\Middleware\TrackUserActivity::class,
            'role' => \App\Http\Middleware\CheckRole::class, // Add this line
        ]);

        $middleware->validateCsrfTokens(except: [
            'api/*',
        ]);

        $middleware->group('api', [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            'track.activity',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();