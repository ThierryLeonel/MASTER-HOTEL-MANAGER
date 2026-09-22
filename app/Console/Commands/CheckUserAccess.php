<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

/**
 * Post-9.8 second fix round diagnostic (PHASE9_AUDIT_AND_PLAN.md §16.4).
 *
 * The front-desk "no dropdown/button appears at all" report for room
 * status was investigated twice at the code level — routes, controller,
 * model, seeder, policies, middleware alias, guard config, and an
 * existing passing test (tests/Feature/FrontOffice/RoomAuthorizationTest.php)
 * all confirm the feature works as coded: the room-status control on
 * resources/views/front-office/rooms/index.blade.php is wrapped in
 * `@can('update-room-status')`, and that permission is granted to the
 * front_desk role by RolesAndPermissionsSeeder. A control that never
 * renders at all (as opposed to rendering but 403ing on click) means
 * Blade's @can check is evaluating false for that specific logged-in
 * user in the LIVE database — which is a live-data/cache state question
 * this sandbox cannot inspect directly, since it has no access to the
 * production database.
 *
 * This command lets the owner (or anyone with shell access on the live
 * server) answer that question themselves in one step, instead of
 * guessing blind: `php artisan hms:check-access frontdesk1@hotel.com`.
 * It reports, for one user: whether the account exists and is active,
 * which role(s) it holds, whether that role actually has the permission
 * in the database, and what `$user->can()` resolves to right now
 * (which also implicitly flushes/uses Spatie's permission cache, so a
 * stale-cache case self-corrects just by running this).
 */
class CheckUserAccess extends Command
{
    protected $signature = 'hms:check-access {email : The user\'s login email} {permission=update-room-status : The permission to check}';

    protected $description = 'Diagnose why a specific user does or does not have a given permission (e.g. update-room-status) on this server.';

    public function handle(): int
    {
        $email = $this->argument('email');
        $permissionName = $this->argument('permission');

        $user = User::where('email', $email)->first();

        if (! $user) {
            $this->error("No user found with email '{$email}'. Check for typos, or that this is the same database the live site uses.");

            return self::FAILURE;
        }

        $this->info("User: {$user->name} <{$user->email}> (id {$user->id})");
        $this->line('Active: '.($user->is_active ? 'yes' : 'NO — this account is deactivated and cannot log in or use any permission.'));

        $roles = $user->getRoleNames();

        if ($roles->isEmpty()) {
            $this->error('This user has NO role assigned at all. Every permission check will fail. Assign a role from the Users admin screen (manage-users), or re-run the seeder for demo accounts: php artisan db:seed --class=UserSeeder --force');

            return self::FAILURE;
        }

        $this->line('Role(s): '.$roles->implode(', '));

        $permissionExists = Permission::where('name', $permissionName)->where('guard_name', 'web')->exists();

        if (! $permissionExists) {
            $this->error("The permission '{$permissionName}' does not exist in this database at all. This means RolesAndPermissionsSeeder has never been run (or was run against a different database than the one the live site is using). Fix: php artisan db:seed --class=RolesAndPermissionsSeeder --force");

            return self::FAILURE;
        }

        // Force-refresh Spatie's permission cache before checking — if the
        // live symptom is a stale cache (e.g. the seeder was re-run but the
        // cached permission map on disk/Redis was never cleared), this line
        // alone fixes it for the rest of this command's run, and the
        // explicit permission:cache-reset instruction below fixes it for
        // the live web requests too.
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $rolesWithPermission = [];
        $rolesWithoutPermission = [];

        foreach ($roles as $roleName) {
            $role = \Spatie\Permission\Models\Role::findByName($roleName, 'web');
            if ($role->hasPermissionTo($permissionName)) {
                $rolesWithPermission[] = $roleName;
            } else {
                $rolesWithoutPermission[] = $roleName;
            }
        }

        if (! empty($rolesWithPermission)) {
            $this->line("Role(s) that DO hold '{$permissionName}' in the database: ".implode(', ', $rolesWithPermission));
        }

        if (! empty($rolesWithoutPermission)) {
            $this->line("Role(s) that do NOT hold '{$permissionName}' in the database: ".implode(', ', $rolesWithoutPermission));
        }

        $canNow = $user->can($permissionName);

        $this->newLine();

        if ($canNow) {
            $this->info("RESULT: {$user->email} DOES have '{$permissionName}' right now (cache just cleared and re-checked).");
            $this->line('If the control still does not appear in the browser, the fix is on the browser/session side, not the database: have this user log out and log back in (their session may have been created before the role/permission was corrected), and confirm they are logging into the same domain the live database above belongs to.');

            return self::SUCCESS;
        }

        $this->error("RESULT: {$user->email} does NOT have '{$permissionName}' right now.");
        $this->line('Most likely cause: RolesAndPermissionsSeeder has not been (re-)run against this live database since this permission was introduced, so the front_desk role row here was never granted it. Fix, in order:');
        $this->line('  1. php artisan db:seed --class=RolesAndPermissionsSeeder --force');
        $this->line('  2. php artisan permission:cache-reset');
        $this->line('  3. php artisan optimize:clear   (clears any cached routes/config/views left from before the deploy)');
        $this->line('  4. Re-run this command to confirm, then have the user log out and back in.');
        $this->line('If step 1 reports nothing to do and this user still fails, double-check this user is actually assigned the front_desk role (not e.g. accountant or bar_staff) from the Users admin screen.');

        return self::FAILURE;
    }
}
