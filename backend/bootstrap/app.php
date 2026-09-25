<?php

use App\Console\Commands\CheckLongWaitingTickets;
use App\Console\Commands\CheckOverdueWaitingTickets;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    // Phase 8 — Long-Waiting Alert is scheduled, not event-driven: a
    // ticket breaches its threshold just by sitting still, with no
    // state-changing action to hang a listener off. Requires an actual
    // OS-level cron entry running `php artisan schedule:run` every
    // minute in any real deployment — this only registers what to run,
    // Laravel's scheduler isn't a background daemon by itself.
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command(CheckLongWaitingTickets::class)->everyMinute();
        $schedule->command(CheckOverdueWaitingTickets::class)->everyMinute();
    })
    // Registered separately (not via withRouting's `channels:` param) so the
    // broadcasting auth route (POST /broadcasting/auth, which private
    // channel subscriptions call) is protected by auth:sanctum — this app
    // is Bearer-token only, not cookie/session, so the framework's default
    // 'web' guard on that route would reject every real staff request.
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['middleware' => ['auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // /api/track/{trackingToken} and /api/waiting-display/{department}
        // carry no authentication at all — the highest-risk surface in this
        // app for accidental information disclosure — so a 429 from them
        // must never include file paths, exception class, or stack trace,
        // even with APP_DEBUG=true locally. A registered render() callback
        // takes precedence over the framework's default debug-mode
        // rendering, so this holds regardless of APP_DEBUG. Registered for
        // ThrottleRequestsException generally (not scoped to those two
        // routes) so any route that throttles in the future is covered by
        // the same guarantee automatically — no route in this app should
        // ever leak those details on a 429.
        $exceptions->render(function (ThrottleRequestsException $e, Request $request) {
            return response()->json([
                'message' => 'Too Many Attempts.',
                'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
            ], 429, $e->getHeaders());
        });
    })->create();