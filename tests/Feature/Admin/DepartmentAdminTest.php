<?php

namespace Tests\Feature\Admin;

use App\Models\Department;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.2): Department has existed since
 * Phase 1 but had no CRUD screen until DepartmentController.
 */
class DepartmentAdminTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_create_a_department(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->post(route('admin.departments.store'), [
            'name' => 'Security',
            'code' => 'sec',
            'description' => 'Night watch and access control',
        ]);

        $response->assertRedirect(route('admin.departments.index'));
        $this->assertDatabaseHas('departments', ['name' => 'Security', 'code' => 'SEC', 'is_active' => true]);
    }

    public function test_department_codes_must_be_unique(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        Department::create(['name' => 'Existing', 'code' => 'DUP', 'is_active' => true]);

        $response = $this->actingAs($owner)->post(route('admin.departments.store'), [
            'name' => 'Another',
            'code' => 'DUP',
        ]);

        $response->assertSessionHasErrors('code');
    }

    public function test_owner_can_update_and_deactivate_a_department(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $department = Department::create(['name' => 'Old Name', 'code' => 'OLD', 'is_active' => true]);

        $this->actingAs($owner)->put(route('admin.departments.update', $department), [
            'name' => 'New Name',
            'code' => 'NEW',
        ])->assertRedirect();

        $department->refresh();
        $this->assertSame('New Name', $department->name);
        $this->assertSame('NEW', $department->code);

        $this->actingAs($owner)->post(route('admin.departments.deactivate', $department))->assertRedirect();
        $this->assertFalse($department->fresh()->is_active);

        $this->actingAs($owner)->post(route('admin.departments.activate', $department))->assertRedirect();
        $this->assertTrue($department->fresh()->is_active);
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs").
     */
    public function test_owner_can_delete_a_department_that_was_never_used(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $department = Department::create(['name' => 'Unused', 'code' => 'UNUSED', 'is_active' => true]);

        $this->actingAs($owner)->delete(route('admin.departments.destroy', $department))->assertRedirect();

        $this->assertDatabaseMissing('departments', ['id' => $department->id]);
    }

    public function test_a_department_with_staff_assigned_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $department = Department::create(['name' => 'Housekeeping', 'code' => 'HK', 'is_active' => true]);
        $this->userWithRole('front_desk', ['department_id' => $department->id]);

        $response = $this->actingAs($owner)->delete(route('admin.departments.destroy', $department));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('departments', ['id' => $department->id]);
    }

    /** @dataProvider rolesWithoutManageDepartments */
    public function test_roles_without_manage_departments_permission_are_forbidden(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('admin.departments.index'))->assertForbidden();
    }

    public static function rolesWithoutManageDepartments(): array
    {
        return [['duty_manager'], ['accountant'], ['cashier'], ['front_desk'], ['bar_staff'], ['restaurant_staff']];
    }
}
