<?php

namespace App\Http\Controllers;

use App\Services\Integrity\DataIntegrityCheckerService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * A one-time, browser-based installer/migration runner for shared hosting
 * with no SSH and no server-side Composer (spec section 41 / README §10
 * step 5) — the last unbuilt item from the original roadmap (README §11).
 *
 * SAFETY MODEL, read this before changing anything below:
 *
 *  - Gated by TWO things, both required: ENABLE_DEPLOY_HELPER=true in
 *    .env, and a `token` request value that timing-safe-matches
 *    DEPLOY_TOKEN. Either missing/wrong -> abort(404), not 403 — a wrong
 *    guess should look exactly like the route doesn't exist.
 *  - The example DEPLOY_TOKEN value shipped in .env.example is refused
 *    outright even if somehow set, so a deployer who forgot to change it
 *    is not left with a merely-obscure-but-known secret protecting a
 *    migration runner.
 *  - This controller is intentionally a fixed, hardcoded menu of a
 *    handful of safe, standard artisan commands — migrate, db:seed, and
 *    cache/config/route/view:clear. There is deliberately NO generic
 *    "run any artisan command" action and never should be; that would
 *    turn a recovery tool into a remote-code-execution primitive the
 *    moment the token ever leaked. There is also deliberately no
 *    migrate:fresh / migrate:rollback / db:wipe — nothing destructive.
 *  - seed() refuses to run if the `users` table already has a row,
 *    unless `force=1` is explicitly submitted — re-running the demo
 *    seeders blindly would duplicate demo accounts/data (see
 *    DatabaseSeeder's docblock; most seeders here `create()` rather than
 *    `firstOrCreate()`, since they are meant to run exactly once on a
 *    fresh install).
 *  - Routes for this controller are registered OUTSIDE the normal `web`
 *    middleware group (see routes/deploy.php + bootstrap/app.php's
 *    `then` callback) specifically so this page still works on a
 *    completely fresh, unmigrated database — the default
 *    SESSION_DRIVER=database and CACHE_STORE=database would otherwise
 *    make EVERY request 500 before a single migration has run, since
 *    Laravel's session/cache middleware would try to query tables that
 *    don't exist yet. This controller must stay free of anything that
 *    touches session or cache for that same reason.
 *  - Every successful action is logged via Laravel's own file log
 *    (`storage/logs/laravel.log`), not the `audit_logs` table — that
 *    table may not exist yet the first time this runs, and there is no
 *    authenticated User to attribute the entry to.
 */
class DeployHelperController extends Controller
{
    private const PLACEHOLDER_TOKEN = 'change-this-to-a-random-64-character-string';

    private const SAFE_CACHE_CLEAR_COMMANDS = ['config:clear', 'cache:clear', 'route:clear', 'view:clear'];

    public function index(Request $request)
    {
        $this->authorizeRequest($request);

        return view('deploy-helper.index', [
            'token' => $request->query('token'),
            'dbStatus' => $this->databaseStatus(),
            'usersAlreadySeeded' => $this->usersTableHasRows(),
            'storageLinkExists' => $this->storageLinkExists(),
        ]);
    }

    /**
     * New requirement 7 ("hotel logo fix + browser favicon") diagnosis:
     * the uploaded hotel logo is stored on the `public` disk
     * (SettingsController::uploadLogo() -> `store('settings', 'public')`)
     * and served via `Storage::disk('public')->url(...)`, which resolves
     * to `/storage/settings/<file>` — a URL that only actually works when
     * `public/storage` is a symlink to `storage/app/public`. Laravel never
     * creates that symlink automatically; it requires an explicit
     * `php artisan storage:link`, normally run once during deployment.
     * On a host with SSH this is a one-line fix, but this whole
     * controller exists BECAUSE some hosts (the shared-hosting target
     * spec section 41 describes) give no terminal at all — the exact
     * scenario `seedRolesAndPermissions()`/`seedFinancialAccounts()`
     * above were already added for. Without this button, a host in that
     * situation had no way to ever create the symlink, and every
     * uploaded logo (and the favicon route this round adds, which reuses
     * the same public-disk logo) would 404 forever no matter how many
     * times the logo was re-uploaded — the uploaded FILE was always fine;
     * only the missing symlink stood between it and the browser.
     * `storage:link` itself only ever creates a symlink (or reports one
     * already exists) — it never touches the database or deletes
     * anything, so it's as safe as the cache-clear commands already
     * exposed here.
     */
    public function storageLink(Request $request)
    {
        $this->authorizeRequest($request);

        Artisan::call('storage:link', ['--force' => true]);
        $output = Artisan::output();

        $this->logAction('deploy.storage_link_created', $request);

        $disabled = $request->boolean('disable_after') ? $this->disableHelper() : null;

        return view('deploy-helper.result', [
            'token' => $request->input('token'),
            'heading' => 'Storage link created',
            'output' => $output ?: "public/storage now links to storage/app/public. Uploaded logos and other public-disk files will load correctly.",
            'disabled' => $disabled,
        ]);
    }

    public function migrate(Request $request)
    {
        $this->authorizeRequest($request);

        Artisan::call('migrate', ['--force' => true]);
        $output = Artisan::output();

        $this->logAction('deploy.migrate_ran', $request);

        $disabled = $request->boolean('disable_after') ? $this->disableHelper() : null;

        return view('deploy-helper.result', [
            'token' => $request->input('token'),
            'heading' => 'Migration output',
            'output' => $output,
            'disabled' => $disabled,
        ]);
    }

    public function seed(Request $request)
    {
        $this->authorizeRequest($request);

        if ($this->usersTableHasRows() && ! $request->boolean('force')) {
            return view('deploy-helper.result', [
                'token' => $request->input('token'),
                'heading' => 'Seeding refused',
                'output' => "The users table already has at least one row. Re-running the demo seeders would "
                    ."duplicate demo accounts and reference data (most of this project's seeders create() rows "
                    ."unconditionally rather than firstOrCreate(), since they're meant to run exactly once on a "
                    ."fresh install).\n\nIf you specifically intend to re-seed anyway, resubmit with the \"I "
                    ."understand this may duplicate data\" box checked.",
                'disabled' => null,
            ]);
        }

        Artisan::call('db:seed', ['--force' => true]);
        $output = Artisan::output();

        $this->logAction('deploy.seed_ran', $request);

        $disabled = $request->boolean('disable_after') ? $this->disableHelper() : null;

        return view('deploy-helper.result', [
            'token' => $request->input('token'),
            'heading' => 'Seeding output',
            'output' => $output,
            'disabled' => $disabled,
        ]);
    }

    /**
     * Seventh post-9.8 fix round (`PHASE9_AUDIT_AND_PLAN.md` §20.11):
     * every round since §18.3 has told the owner to re-run
     * `php artisan db:seed --class=RolesAndPermissionsSeeder --force` (a
     * terminal command) after a permission-grant change — but this whole
     * controller exists BECAUSE some hosts give no terminal at all. On
     * exactly that kind of host, the only seeding path available was the
     * generic seed() above, which refuses outright once real guests/users
     * exist (correctly — most seeders in DatabaseSeeder::run() are NOT
     * safe to re-run). That left no safe way to actually apply a
     * permission-grant fix at all on a no-SSH host once the hotel was
     * live — a real, previously-invisible cause behind "the fix didn't
     * seem to take effect" reports.
     *
     * RolesAndPermissionsSeeder itself IS always safe to re-run, at any
     * time, on a database with any amount of real data: it only ever
     * touches the roles/permissions tables via firstOrCreate() and
     * syncPermissions() (never create()), and its own last line clears
     * the Spatie permission cache (§20.8.2's fix) — so this one specific
     * seeder gets its own always-available button, deliberately exempt
     * from the "users already seeded" refusal that rightly still guards
     * the generic seed() action above.
     */
    public function seedRolesAndPermissions(Request $request)
    {
        $this->authorizeRequest($request);

        Artisan::call('db:seed', ['--class' => \Database\Seeders\RolesAndPermissionsSeeder::class, '--force' => true]);
        $output = Artisan::output();

        $this->logAction('deploy.roles_and_permissions_seed_ran', $request);

        $disabled = $request->boolean('disable_after') ? $this->disableHelper() : null;

        return view('deploy-helper.result', [
            'token' => $request->input('token'),
            'heading' => 'Roles & permissions updated',
            'output' => $output,
            'disabled' => $disabled,
        ]);
    }

    /**
     * Same reasoning as seedRolesAndPermissions() above, for the other
     * seeder this project's changelog has repeatedly asked a no-SSH host
     * to run standalone: FinancialAccountSeeder only ever calls
     * firstOrCreate() keyed on `code`, so running it a second (or tenth)
     * time on a live database with real guests, real payments, and real
     * financial accounts already in place changes nothing for any
     * account that already exists — it only ever fills in whichever of
     * Cash/Bank/MTN Mobile Money/Orange Money is still missing. This is
     * the direct fix for "the account dropdown is empty" when that's
     * because this seeder never actually ran against the live database
     * (the most likely cause on a host where the only seeding path was
     * the generic, refused-once-live seed() action above).
     */
    public function seedFinancialAccounts(Request $request)
    {
        $this->authorizeRequest($request);

        Artisan::call('db:seed', ['--class' => \Database\Seeders\FinancialAccountSeeder::class, '--force' => true]);
        $output = Artisan::output();

        $this->logAction('deploy.financial_accounts_seed_ran', $request);

        $disabled = $request->boolean('disable_after') ? $this->disableHelper() : null;

        return view('deploy-helper.result', [
            'token' => $request->input('token'),
            'heading' => 'Financial accounts set up',
            'output' => $output,
            'disabled' => $disabled,
        ]);
    }

    public function clearCaches(Request $request)
    {
        $this->authorizeRequest($request);

        $output = '';
        foreach (self::SAFE_CACHE_CLEAR_COMMANDS as $command) {
            Artisan::call($command);
            $output .= "\$ php artisan {$command}\n".Artisan::output()."\n";
        }

        $this->logAction('deploy.caches_cleared', $request);

        return view('deploy-helper.result', [
            'token' => $request->input('token'),
            'heading' => 'Cache/config clear output',
            'output' => trim($output),
            'disabled' => null,
        ]);
    }

    /**
     * Phase 9.6 (PHASE9_AUDIT_AND_PLAN.md §3.7): wires
     * DataIntegrityCheckerService into the deploy helper as the "optional
     * post-deploy step" the plan calls for — read-only (unlike
     * migrate/seed above, it never writes anything), so it's a GET
     * action rather than a POST/form submission. Requires the schema to
     * actually exist first (unlike migrate/seed, which this whole
     * controller exists to run on a table-less database) — checked
     * explicitly rather than letting a "table doesn't exist" SQL error
     * surface as an unhandled 500.
     */
    public function integrityCheck(Request $request)
    {
        $this->authorizeRequest($request);

        if (! Schema::hasTable('folios')) {
            return view('deploy-helper.result', [
                'token' => $request->input('token') ?? $request->query('token'),
                'heading' => 'Data integrity check skipped',
                'output' => "The database doesn't have its tables yet — run migrations first (step 1 on the main page), then come back to this check.",
                'disabled' => null,
            ]);
        }

        $results = app(DataIntegrityCheckerService::class)->run();

        $this->logAction('deploy.integrity_check_ran', $request);

        return view('deploy-helper.integrity-result', [
            'token' => $request->input('token') ?? $request->query('token'),
            'results' => $results,
        ]);
    }

    public function disable(Request $request)
    {
        $this->authorizeRequest($request);

        $result = $this->disableHelper();

        $this->logAction('deploy.disabled', $request);

        return view('deploy-helper.result', [
            'token' => null,
            'heading' => 'Disabling the deploy helper',
            'output' => $result['message'],
            'disabled' => $result,
        ]);
    }

    /**
     * Both conditions are required: the feature flag AND a timing-safe
     * token match against a real (non-placeholder, non-empty) secret.
     * Fails as a 404 deliberately — a wrong guess should look exactly
     * like this route doesn't exist at all, not like a locked door.
     */
    private function authorizeRequest(Request $request): void
    {
        $configuredToken = (string) env('DEPLOY_TOKEN', '');
        $submittedToken = (string) ($request->input('token') ?? $request->query('token') ?? '');

        $enabled = filter_var(env('ENABLE_DEPLOY_HELPER', false), FILTER_VALIDATE_BOOLEAN);

        $tokenIsUsable = $configuredToken !== '' && $configuredToken !== self::PLACEHOLDER_TOKEN;

        abort_unless(
            $enabled && $tokenIsUsable && $submittedToken !== '' && hash_equals($configuredToken, $submittedToken),
            404
        );
    }

    private function databaseStatus(): array
    {
        try {
            DB::connection()->getPdo();
            $connected = true;
            $error = null;
        } catch (\Throwable $e) {
            $connected = false;
            $error = $e->getMessage();
        }

        return [
            'connected' => $connected,
            'error' => $error,
            'migrationsTableExists' => $connected && Schema::hasTable('migrations'),
        ];
    }

    /**
     * `is_link()` (not `file_exists()`/`is_dir()`) specifically — a real
     * `public/storage` DIRECTORY left over from some other cause would
     * make `file_exists()` true while logos still 404, since Laravel's
     * `Storage::disk('public')->url()` needs the symlink itself, not
     * merely something present at that path.
     */
    private function storageLinkExists(): bool
    {
        return is_link(public_path('storage'));
    }

    private function usersTableHasRows(): bool
    {
        try {
            return Schema::hasTable('users') && DB::table('users')->exists();
        } catch (\Throwable $e) {
            return false;
        }
    }

    /**
     * Best-effort rewrite of the .env file's ENABLE_DEPLOY_HELPER line to
     * false, via a read -> regex-replace -> write-to-temp -> atomic
     * rename, so a crash mid-write can never leave a half-written .env
     * behind. Whether this actually succeeds depends on the host's file
     * permissions for the PHP process, which this sandbox cannot verify
     * against a real shared-hosting account — see README §10 / the
     * NOT VERIFIED note there. If it fails for any reason, the failure is
     * reported plainly with the manual fallback instructions rather than
     * silently swallowed.
     */
    private function disableHelper(): array
    {
        $path = base_path('.env');

        try {
            if (! is_file($path) || ! is_readable($path) || ! is_writable($path)) {
                throw new \RuntimeException('.env is missing or not writable by the web server process.');
            }

            $contents = file_get_contents($path);
            if ($contents === false) {
                throw new \RuntimeException('Could not read .env.');
            }

            $pattern = '/^ENABLE_DEPLOY_HELPER=.*$/m';
            if (preg_match($pattern, $contents)) {
                $updated = preg_replace($pattern, 'ENABLE_DEPLOY_HELPER=false', $contents, 1);
            } else {
                $updated = rtrim($contents)."\nENABLE_DEPLOY_HELPER=false\n";
            }

            $tempPath = $path.'.tmp-'.bin2hex(random_bytes(4));
            if (file_put_contents($tempPath, $updated) === false) {
                throw new \RuntimeException('Could not write a temporary copy of .env.');
            }
            if (! rename($tempPath, $path)) {
                @unlink($tempPath);
                throw new \RuntimeException('Could not replace .env with the updated copy.');
            }

            return [
                'success' => true,
                'message' => 'ENABLE_DEPLOY_HELPER has been set back to false in .env. This page will now 404 '
                    .'on every request, exactly like before it was ever enabled.',
            ];
        } catch (\Throwable $e) {
            return [
                'success' => false,
                'message' => "Could not automatically disable the helper ({$e->getMessage()}). ".
                    'You MUST do this manually right now: edit .env, set ENABLE_DEPLOY_HELPER=false, and save. '
                    .'Leaving this enabled on a live site is a real security exposure — anyone who ever learns '
                    .'your DEPLOY_TOKEN can re-run migrations/seeders against your production database for as '
                    .'long as this stays true.',
            ];
        }
    }

    private function logAction(string $action, Request $request): void
    {
        Log::warning($action, [
            'ip' => $request->ip(),
            'at' => now()->toIso8601String(),
        ]);
    }
}
