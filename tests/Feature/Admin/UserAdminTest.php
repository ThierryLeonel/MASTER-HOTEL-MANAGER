<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.2): before UserController/
 * UserAdminService existed, there was no way to create, edit, deactivate,
 * or reset the credentials of a staff account except by seeding or
 * hand-editing the database.
 */
class UserAdminTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_create_a_pin_based_staff_account(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $department = Department::create(['name' => 'Test Dept', 'code' => 'TESTD', 'is_active' => true]);

        $response = $this->actingAs($owner)->post(route('admin.users.store'), [
            'name' => 'New Cashier',
            'username' => 'newcashier',
            'email' => 'newcashier@example.test',
            'department_id' => $department->id,
            'role' => 'cashier',
            'auth_type' => 'pin',
            'pin' => '4321',
        ]);

        $user = User::where('username', 'newcashier')->first();
        $response->assertRedirect(route('admin.users.edit', $user));
        $this->assertNotNull($user);
        $this->assertTrue($user->hasRole('cashier'));
        $this->assertSame('pin', $user->auth_type);
        $this->assertNull($user->password);
        $this->assertNotNull($user->pin_hash);
        $this->assertTrue($user->is_active);
    }

    public function test_creating_a_password_account_without_a_password_is_rejected(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->post(route('admin.users.store'), [
            'name' => 'Missing Password',
            'username' => 'missingpw',
            'email' => 'missingpw@example.test',
            'role' => 'duty_manager',
            'auth_type' => 'password_only',
        ]);

        $response->assertSessionHasErrors('password');
        $this->assertDatabaseMissing('users', ['username' => 'missingpw']);
    }

    /** @dataProvider rolesWithoutManageUsers */
    public function test_roles_without_manage_users_permission_cannot_reach_the_admin_screen(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('admin.users.index'))->assertForbidden();
        $this->actingAs($user)->post(route('admin.users.store'), [])->assertForbidden();
    }

    public static function rolesWithoutManageUsers(): array
    {
        return [['duty_manager'], ['accountant'], ['cashier'], ['front_desk'], ['bar_staff'], ['restaurant_staff']];
    }

    public function test_owner_can_edit_an_existing_users_role_and_department(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $target = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $newDepartment = Department::create(['name' => 'New Dept', 'code' => 'NEWD', 'is_active' => true]);

        $response = $this->actingAs($owner)->put(route('admin.users.update', $target), [
            'name' => $target->name,
            'username' => $target->username,
            'email' => $target->email,
            'department_id' => $newDepartment->id,
            'role' => 'duty_manager',
        ]);

        $response->assertRedirect();
        $target->refresh();
        $this->assertTrue($target->hasRole('duty_manager'));
        $this->assertFalse($target->hasRole('front_desk'));
        $this->assertSame($newDepartment->id, $target->department_id);
    }

    public function test_deactivating_a_user_prevents_nothing_but_is_recorded_and_reversible(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $target = $this->userWithRole('cashier', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->post(route('admin.users.deactivate', $target))->assertRedirect();
        $this->assertFalse($target->fresh()->is_active);

        $this->actingAs($owner)->post(route('admin.users.activate', $target))->assertRedirect();
        $this->assertTrue($target->fresh()->is_active);
    }

    public function test_an_owner_cannot_deactivate_their_own_account(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->post(route('admin.users.deactivate', $owner));

        $response->assertSessionHasErrors('error');
        $this->assertTrue($owner->fresh()->is_active);
    }

    public function test_resetting_a_pin_accounts_password_is_rejected(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $target = $this->pinUser('cashier');

        $response = $this->actingAs($owner)->post(route('admin.users.reset-password', $target), [
            'password' => 'BrandNewPass1',
        ]);

        $response->assertSessionHasErrors('error');
    }

    public function test_resetting_a_pin_also_clears_any_existing_lockout(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $target = $this->pinUser('cashier');
        $target->update(['failed_pin_attempts' => 5, 'pin_locked_until' => now()->addMinutes(15)]);

        $response = $this->actingAs($owner)->post(route('admin.users.reset-pin', $target), ['pin' => '9999']);

        $response->assertRedirect();
        $target->refresh();
        $this->assertSame(0, $target->failed_pin_attempts);
        $this->assertNull($target->pin_locked_until);
        $this->assertTrue(\Illuminate\Support\Facades\Hash::check('9999', $target->pin_hash));
    }

    public function test_unlock_pin_clears_lockout_without_changing_the_pin(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $target = $this->pinUser('cashier', '1234');
        $target->update(['failed_pin_attempts' => 5, 'pin_locked_until' => now()->addMinutes(15)]);
        $originalHash = $target->pin_hash;

        $this->actingAs($owner)->post(route('admin.users.unlock-pin', $target))->assertRedirect();

        $target->refresh();
        $this->assertSame(0, $target->failed_pin_attempts);
        $this->assertNull($target->pin_locked_until);
        $this->assertSame($originalHash, $target->pin_hash);
    }
}
