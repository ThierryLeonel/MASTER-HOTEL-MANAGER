<?php

namespace Tests\Feature\FrontOffice;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.0 security fix: maintenance.index (viewing tickets) previously
 * had no permission gate at all, while store/approve/resolve were already
 * correctly gated. Now gated on 'report-maintenance' — the broadest
 * maintenance permission, held by every role that legitimately interacts
 * with tickets — so nobody who currently uses this screen loses access.
 */
class MaintenanceAuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    /** @dataProvider rolesThatCanViewMaintenance */
    public function test_roles_with_report_maintenance_permission_can_view_the_ticket_list(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $response = $this->actingAs($user)->get(route('maintenance.index'));

        $response->assertOk();
    }

    public static function rolesThatCanViewMaintenance(): array
    {
        return [
            ['owner'],
            ['general_manager'],
            ['duty_manager'],
            ['front_desk'],
            ['bar_staff'],
            ['restaurant_staff'],
        ];
    }

    /** @dataProvider rolesThatCannotViewMaintenance */
    public function test_roles_without_report_maintenance_permission_are_forbidden(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $response = $this->actingAs($user)->get(route('maintenance.index'));

        $response->assertForbidden();
    }

    public static function rolesThatCannotViewMaintenance(): array
    {
        return [
            ['accountant'],
            ['cashier'],
        ];
    }
}
