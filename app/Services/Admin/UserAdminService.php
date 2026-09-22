<?php

namespace App\Services\Admin;

use App\Exceptions\HmsException;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Phase 9.2 (see PHASE9_AUDIT_AND_PLAN.md §3.2): before this service and
 * its controller (App\Http\Controllers\Admin\UserController) existed,
 * there was NO way to create, edit, deactivate, or reset the credentials
 * of a staff account except by seeding or by hand-editing the database —
 * every account in every environment came from UserSeeder. This service
 * is the first real Users administration surface in the project.
 *
 * Every mutation here is audit-logged (spec section 21) because account
 * creation, role changes, and credential resets are exactly the kind of
 * "important mutation" AuditLogger exists for — unlike, say, editing a
 * guest's phone number, which this project does not audit-log today.
 */
class UserAdminService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * @param  array{name:string,username:string,email:string,department_id:?int,role:string,auth_type:string,password:?string,pin:?string}  $data
     */
    public function create(array $data, User $actor): User
    {
        $credentials = $this->credentialFields($data['auth_type'], $data['password'] ?? null, $data['pin'] ?? null);

        $user = DB::transaction(function () use ($data, $credentials) {
            $user = User::create([
                'name' => $data['name'],
                'username' => $data['username'],
                'email' => $data['email'],
                'department_id' => $data['department_id'] ?? null,
                'auth_type' => $data['auth_type'],
                'password' => $credentials['password'],
                'pin_hash' => $credentials['pin_hash'],
                'is_active' => true,
                'email_verified_at' => now(),
            ]);

            $user->assignRole($data['role']);

            return $user;
        });

        $this->audit->log(
            'admin.user_created',
            'admin',
            $user,
            newValues: ['name' => $user->name, 'username' => $user->username, 'role' => $data['role'], 'auth_type' => $user->auth_type],
            actor: $actor,
        );

        return $user;
    }

    /**
     * @param  array{name:string,username:string,email:string,department_id:?int,role:string}  $data
     */
    public function update(User $user, array $data, User $actor): User
    {
        $oldValues = $user->only(['name', 'username', 'email', 'department_id']);
        $oldRole = $user->getRoleNames()->first();

        $user->update([
            'name' => $data['name'],
            'username' => $data['username'],
            'email' => $data['email'],
            'department_id' => $data['department_id'] ?? null,
        ]);

        if ($data['role'] !== $oldRole) {
            $user->syncRoles([$data['role']]);
        }

        $this->audit->log(
            'admin.user_updated',
            'admin',
            $user,
            oldValues: [...$oldValues, 'role' => $oldRole],
            newValues: [...$user->only(['name', 'username', 'email', 'department_id']), 'role' => $data['role']],
            actor: $actor,
        );

        return $user->fresh();
    }

    public function setActive(User $user, bool $active, User $actor): User
    {
        if (! $active && $user->id === $actor->id) {
            throw new HmsException('You cannot deactivate your own account.');
        }

        $user->update(['is_active' => $active]);

        $this->audit->log(
            $active ? 'admin.user_activated' : 'admin.user_deactivated',
            'admin',
            $user,
            actor: $actor,
        );

        return $user->fresh();
    }

    public function resetPassword(User $user, string $newPassword, User $actor): User
    {
        if ($user->auth_type === 'pin') {
            throw new HmsException('This account uses PIN login — use "Reset PIN" instead.');
        }

        // Never write the actual password into old/new values — only that
        // a reset happened, and by whom (already captured by AuditLogger's
        // actor/user_id columns).
        $user->update(['password' => Hash::make($newPassword)]);

        $this->audit->log('admin.user_password_reset', 'admin', $user, actor: $actor);

        return $user->fresh();
    }

    public function resetPin(User $user, string $newPin, User $actor): User
    {
        if ($user->auth_type !== 'pin') {
            throw new HmsException('This account does not use PIN login — use "Reset password" instead.');
        }

        $user->update([
            'pin_hash' => Hash::make($newPin),
            'failed_pin_attempts' => 0,
            'pin_locked_until' => null,
        ]);

        $this->audit->log('admin.user_pin_reset', 'admin', $user, actor: $actor);

        return $user->fresh();
    }

    /**
     * Clears a PIN lockout without changing the PIN itself. Documented as
     * a manual `php artisan tinker` workaround in README §12
     * (Troubleshooting) since Phase 1 — this is the admin-UI equivalent
     * the README note said didn't exist yet.
     */
    public function unlockPin(User $user, User $actor): User
    {
        $user->update(['failed_pin_attempts' => 0, 'pin_locked_until' => null]);

        $this->audit->log('admin.user_pin_unlocked', 'admin', $user, actor: $actor);

        return $user->fresh();
    }

    /**
     * @return array{password:?string,pin_hash:?string}
     */
    private function credentialFields(string $authType, ?string $password, ?string $pin): array
    {
        return match ($authType) {
            'pin' => [
                'password' => null,
                'pin_hash' => $pin ? Hash::make($pin) : throw new HmsException('A PIN is required for PIN-login accounts.'),
            ],
            'password_only', 'password_mfa' => [
                'password' => $password ? Hash::make($password) : throw new HmsException('A password is required for this account type.'),
                'pin_hash' => null,
            ],
            default => throw new HmsException("Unknown auth type: {$authType}."),
        };
    }
}
