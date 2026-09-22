<?php

namespace Tests\Feature;

use App\Models\AccountsReceivable;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.0 honesty/security fix: DashboardController's docblock claimed
 * "Role-specific dashboards" while every authenticated user received an
 * identical $data array, including a hotel-wide financial total
 * (openReceivablesTotal). This is now scoped behind
 * view-financial-reports/view-operational-reports.
 */
class DashboardAuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function createOpenReceivable(): void
    {
        $stay = Stay::factory()->create(['status' => 'closed']);
        $folio = Folio::factory()->for($stay)->create(['status' => 'closed', 'balance' => 0]);

        // No AccountsReceivable factory exists yet in this project, so the
        // record is built directly against its migration's required
        // columns (see database/migrations/*_create_accounts_receivable_table.php).
        AccountsReceivable::create([
            'folio_id' => $folio->id,
            'guest_id' => Guest::factory()->create()->id,
            'original_amount' => 77000,
            'amount_outstanding' => 77000,
            'status' => 'open',
            'created_by' => User::factory()->create()->id,
        ]);
    }

    /** @dataProvider rolesThatCanSeeFinancials */
    public function test_roles_with_financial_or_operational_reporting_permission_see_open_receivables(string $role): void
    {
        $this->createOpenReceivable();
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Open receivables');
        $response->assertSee('77,000');
    }

    public static function rolesThatCanSeeFinancials(): array
    {
        return [
            ['owner'],
            ['general_manager'],
            ['accountant'],
            ['duty_manager'],
        ];
    }

    /** @dataProvider rolesThatCannotSeeFinancials */
    public function test_front_line_roles_do_not_see_the_open_receivables_total(string $role): void
    {
        $this->createOpenReceivable();
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Open receivables');
        $response->assertDontSee('77,000');
    }

    public static function rolesThatCannotSeeFinancials(): array
    {
        return [
            ['front_desk'],
            ['cashier'],
            ['bar_staff'],
            ['restaurant_staff'],
        ];
    }

    public function test_the_dashboard_itself_remains_reachable_by_every_role(): void
    {
        // The dashboard route stays ungated (every role needs a landing
        // page) — only the financial widget within it is scoped.
        foreach (\Database\Seeders\RolesAndPermissionsSeeder::ROLES as $role) {
            $user = $this->userWithRole($role, ['auth_type' => 'password_only']);
            $this->actingAs($user)->get(route('dashboard'))->assertOk();
        }
    }

    /**
     * Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): the "pending approvals"
     * widget is purely permission-driven (PendingApprovalsService) — a
     * role with at least one approve-* permission (e.g. duty_manager holds
     * approve-maintenance/approve-requisition/etc.) must see the section,
     * a role with none of them (cashier) must not.
     */
    public function test_a_role_with_approval_permissions_sees_the_pending_approvals_widget(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);

        $response = $this->actingAs($dutyManager)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Pending approvals');
    }

    public function test_a_role_with_no_approval_permissions_does_not_see_the_pending_approvals_widget(): void
    {
        $cashier = $this->userWithRole('cashier', ['auth_type' => 'password_only']);

        $response = $this->actingAs($cashier)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Pending approvals');
    }

    /**
     * Phase 9.4: the trailing-7-day financial summary widget is gated on
     * view-financial-reports specifically — duty_manager holds
     * view-operational-reports (enough to see Open receivables above) but
     * NOT view-financial-reports, so it must not see this widget.
     */
    public function test_a_role_with_view_financial_reports_sees_the_weekly_financial_summary_widget(): void
    {
        $accountant = $this->userWithRole('accountant', ['auth_type' => 'password_only']);

        $response = $this->actingAs($accountant)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('Last 7 days');
        $response->assertSee('Full weekly summary');
    }

    public function test_a_role_with_only_operational_reports_permission_does_not_see_the_weekly_financial_summary_widget(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);

        $response = $this->actingAs($dutyManager)->get(route('dashboard'));

        $response->assertOk();
        $response->assertDontSee('Last 7 days');
    }
}
