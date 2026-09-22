<?php

namespace Tests\Feature\Reporting;

use App\Models\Folio;
use App\Models\Room;
use App\Models\Stay;
use App\Services\FrontOffice\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): the "Management Report"
 * printable document — combines KpiDashboardService's headline tiles
 * with FinancialReportService's category breakdowns. Every figure comes
 * from a service already covered by FinancialReportTest/
 * OperationsReportTest, so this only checks that the combination screen
 * itself renders correctly and is gated correctly — not the underlying
 * arithmetic again.
 */
class ManagementReportTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_management_report_combines_kpi_and_financial_breakdown_figures(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $room = Room::factory()->create(['is_active' => true]);
        $stay = Stay::factory()->for($room)->create(['status' => 'active', 'check_in_at' => Carbon::today()]);
        $folio = Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
        app(FolioService::class)->postCharge($folio, 'room_charge', 40000, 'Room', $frontDesk);

        $response = $this->actingAs($accountant)->get(route('reports.management-report'));

        $response->assertOk();
        $response->assertSee('40,000');
        $response->assertSee('Occupancy rate');
    }

    public function test_duty_manager_cannot_view_the_management_report(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.management-report'))->assertForbidden();
    }

    public function test_management_report_print_view_renders(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get(route('reports.management-report.print'))->assertOk();
    }
}
