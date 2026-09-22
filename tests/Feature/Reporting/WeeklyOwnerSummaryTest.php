<?php

namespace Tests\Feature\Reporting;

use App\Models\Folio;
use App\Models\Stay;
use App\Services\Finance\ExpenseService;
use App\Services\FrontOffice\FolioService;
use App\Services\Reporting\WeeklyOwnerSummaryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): the "Weekly Owner Summary"
 * the plan calls for — WeeklyOwnerSummaryService computes nothing on its
 * own, it only calls FinancialReportService/OperationsReportService for a
 * 7-day window, so these tests check the window boundaries and the
 * permission gate rather than re-testing arithmetic already covered by
 * FinancialReportTest/OperationsReportTest.
 */
class WeeklyOwnerSummaryTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function openFolio(): Folio
    {
        $stay = Stay::factory()->create(['status' => 'active']);

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
    }

    public function test_the_summary_window_runs_from_six_days_before_the_anchor_through_the_anchor_itself(): void
    {
        $summary = app(WeeklyOwnerSummaryService::class)->forWeekEnding(Carbon::parse('2026-03-15'));

        $this->assertSame('2026-03-09', $summary['start']->toDateString());
        $this->assertSame('2026-03-15', $summary['end']->toDateString());
        $this->assertSame(7, $summary['occupancy']['days']);
    }

    public function test_the_summary_combines_real_revenue_and_expense_figures_for_the_window(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolio();

        app(FolioService::class)->postCharge($folio, 'room_charge', 40000, 'Room', $frontDesk);
        app(ExpenseService::class)->request(10000, 'Supplies', 'Cleaning', $accountant);

        // Outside the 7-day window entirely — must not be picked up.
        $oldFolio = $this->openFolio();
        app(FolioService::class)->postCharge($oldFolio, 'room_charge', 999999, 'Old room charge', $frontDesk)
            ->forceFill(['transaction_date' => Carbon::today()->subDays(30)])->save();

        $summary = app(WeeklyOwnerSummaryService::class)->forWeekEnding(Carbon::today());

        $this->assertSame(40000.0, $summary['statement']['total_revenue']);
        $this->assertSame(10000.0, $summary['statement']['total_expenses']);
        $this->assertSame(30000.0, $summary['statement']['net_operating_result']);
    }

    public function test_duty_manager_cannot_view_the_weekly_owner_summary(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.weekly-owner-summary'))->assertForbidden();
    }

    public function test_owner_and_accountant_can_view_the_weekly_owner_summary(): void
    {
        $owner = $this->userWithRole('owner');
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($owner)->get(route('reports.weekly-owner-summary'))->assertOk();
        $this->actingAs($accountant)->get(route('reports.weekly-owner-summary'))->assertOk();
    }

    public function test_the_week_ending_query_parameter_selects_a_past_week(): void
    {
        $owner = $this->userWithRole('owner');

        $response = $this->actingAs($owner)->get(route('reports.weekly-owner-summary', ['week_ending' => '2026-01-15']));

        $response->assertOk();
        $response->assertSee('Jan 9');
        $response->assertSee('Jan 15, 2026');
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): the printable version of
     * this same report — same permission, same underlying service call.
     */
    public function test_the_printable_version_renders_for_a_permitted_role(): void
    {
        $owner = $this->userWithRole('owner');

        $this->actingAs($owner)->get(route('reports.weekly-owner-summary.print'))->assertOk();
    }

    public function test_duty_manager_cannot_view_the_printable_weekly_owner_summary(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.weekly-owner-summary.print'))->assertForbidden();
    }
}
