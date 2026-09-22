<?php

use App\Exceptions\HmsException;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
        then: function (): void {
            // Phase 8 (shared-hosting deployment helper, README §10 step
            // 5): registered here, NOT inside routes/web.php's `web:`
            // parameter above, specifically so it gets none of the `web`
            // middleware group (no session/CSRF/cookie handling) — see
            // routes/deploy.php's own docblock for why that's load-bearing
            // rather than an oversight.
            require __DIR__.'/../routes/deploy.php';

            // Phase 9.7.4: the sync transport's server-to-server JSON
            // endpoints — same reasoning as deploy.php just above (no
            // session/CSRF for a request that never has a browser on the
            // other end), see routes/sync.php's own docblock for the
            // full explanation.
            require __DIR__.'/../routes/sync.php';
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Spec section 27: never surface a raw stack trace to a normal
        // user. Business-rule violations become a friendly validation
        // error; unexpected errors are logged and shown a generic 500.
        $exceptions->render(function (HmsException $e, $request) {
            if ($request->expectsJson()) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return back()->withErrors(['error' => $e->getMessage()])->withInput();
        });

        $exceptions->report(function (\Throwable $e) {
            Log::error($e->getMessage(), ['exception' => $e]);
        });
    })->create();
