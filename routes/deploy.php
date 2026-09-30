<?php

use App\Http\Controllers\DeployHelperController;
use Illuminate\Support\Facades\Route;

/**
 * Deliberately NOT part of routes/web.php. These routes are registered
 * from bootstrap/app.php's `withRouting(..., then: ...)` callback, which
 * means they get NONE of the default `web` middleware group — no
 * StartSession, no VerifyCsrfToken, no cookie encryption. That's
 * intentional and load-bearing, not an oversight: the whole point of
 * this controller is to run migrations on a database that may not have a
 * single table yet, including the `sessions` and `cache` tables that
 * SESSION_DRIVER=database / CACHE_STORE=database (this project's own
 * .env.example defaults) would need. If these routes sat under the normal
 * `web` group, every request here would throw a "table doesn't exist"
 * error before DeployHelperController ever ran — defeating the entire
 * purpose of a pre-migration installer.
 *
 * Authorization is handled entirely inside DeployHelperController
 * (ENABLE_DEPLOY_HELPER + a timing-safe DEPLOY_TOKEN comparison), not by
 * route middleware, for the same reason: `auth`/`permission` middleware
 * need the `users`/permission tables to exist and a session to carry the
 * logged-in user, neither of which can be assumed here.
 *
 * `throttle` is the one piece of middleware deliberately kept, since it
 * only touches the cache store in-memory per request via the rate
 * limiter and does not require any database table to exist.
 */
Route::middleware('throttle:10,1')->prefix('deploy-helper')->name('deploy-helper.')->group(function () {
    Route::get('/', [DeployHelperController::class, 'index'])->name('index');
    Route::post('/migrate', [DeployHelperController::class, 'migrate'])->name('migrate');
    Route::post('/seed', [DeployHelperController::class, 'seed'])->name('seed');
    // Seventh post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §20.11): two
    // always-safe, individually re-runnable seeders, deliberately
    // exempt from the "users already seeded" refusal above — see
    // DeployHelperController::seedRolesAndPermissions()'s docblock for
    // why a no-SSH host specifically needed these.
    Route::post('/seed-roles-and-permissions', [DeployHelperController::class, 'seedRolesAndPermissions'])->name('seed-roles-and-permissions');
    Route::post('/seed-financial-accounts', [DeployHelperController::class, 'seedFinancialAccounts'])->name('seed-financial-accounts');
    // Charge-types unification round: see DeployHelperController::
    // seedDepartments()'s docblock — backfills new departments (Bar
    // Outside, Bucareaux, ...) and each system charge type's department
    // on an already-live hotel with no SSH access.
    Route::post('/seed-departments', [DeployHelperController::class, 'seedDepartments'])->name('seed-departments');
    Route::post('/clear-caches', [DeployHelperController::class, 'clearCaches'])->name('clear-caches');
    // New requirement 7 ("hotel logo fix + browser favicon"): the
    // diagnosed root cause of "the hotel logo doesn't show up" — see
    // DeployHelperController::storageLink()'s docblock.
    Route::post('/storage-link', [DeployHelperController::class, 'storageLink'])->name('storage-link');
    // Phase 9.6 (PHASE9_AUDIT_AND_PLAN.md §3.7): read-only, so GET rather
    // than POST like the mutating actions above.
    Route::get('/integrity-check', [DeployHelperController::class, 'integrityCheck'])->name('integrity-check');
    Route::post('/disable', [DeployHelperController::class, 'disable'])->name('disable');
});
