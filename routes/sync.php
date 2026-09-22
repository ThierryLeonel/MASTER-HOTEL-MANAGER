<?php

use App\Http\Controllers\Sync\SyncTransportController;
use Illuminate\Support\Facades\Route;

/**
 * Phase 9.7.4 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan).
 * Deliberately NOT part of routes/web.php, for the same reason
 * routes/deploy.php isn't: these routes are registered from
 * bootstrap/app.php's `withRouting(..., then: ...)` callback, which gets
 * NONE of the default `web` middleware group — no session, no CSRF, no
 * cookie handling. That's exactly right here too: this is server-to-
 * server JSON traffic between two trusted installations authenticated by
 * a shared device secret (see SyncTransportController's own docblock and
 * App\Services\Sync\DeviceAuthService), not a browser with a logged-in
 * user — CSRF verification in particular would only ever reject every
 * legitimate request, since there is no browser session to carry a
 * token.
 *
 * `throttle` is kept, same as routes/deploy.php: it only touches the
 * cache-backed rate limiter in memory per request and needs no database
 * table, so it doesn't conflict with anything else about this route
 * group. The limit here (60/minute) is far more generous than the
 * deploy helper's 10/minute, since a real sync schedule (Phase 9.7.5)
 * legitimately calls these endpoints on every scheduled run, not just
 * once during a manual install.
 */
Route::middleware('throttle:60,1')->prefix('sync')->name('sync.')->group(function () {
    Route::post('/push', [SyncTransportController::class, 'push'])->name('push');
    Route::post('/pull', [SyncTransportController::class, 'pull'])->name('pull');
    Route::post('/acknowledge', [SyncTransportController::class, 'acknowledge'])->name('acknowledge');
});
