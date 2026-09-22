<?php

namespace Tests\Feature\Reporting;

use App\Models\AccountsReceivable;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Stay;
use App\Services\Finance\ExpenseService;
use App\Services\FrontOffice\FolioService;
use App\Services\Reporting\FinancialReportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class FinancialReportTest extends TestCase
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

    public function test_revenue_by_charge_type_reflects_real_posted_charges_and_excludes_voided_ones(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $folio = $this->openFolio();
        $folioService = app(FolioService::class);

        $roomTx = $folioService->postCharge($folio, 'room_charge', 30000, 'Room', $frontDesk);
        $folioService->postCharge($folio, 'bar_charge', 5000, 'Bar tab', $frontDesk);
        $voided = $folioService->postCharge($folio, 'other_service', 9999, 'Mistake', $frontDesk);
        $folioService->void($voided, 'Posted in error', $frontDesk);

        $report = app(FinancialReportService::class)->revenueByChargeType(Carbon::today(), Carbon::today());

        $this->assertSame(30000.0, $report['by_type']['room_charge']);
        $this->assertSame(5000.0, $report['by_type']['bar_charge']);
        $this->assertSame(0.0, $report['by_type']['other_service'], 'A voided charge must not count as revenue.');
        $this->assertSame(35000.0, $report['total']);
    }

    public function test_revenue_outside_the_date_range_is_excluded(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $folio = $this->openFolio();
        app(FolioService::class)->postCharge($folio, 'room_charge', 30000, 'Room', $frontDesk);

        $report = app(FinancialReportService::class)->revenueByChargeType(
            Carbon::yesterday()->subDays(5), Carbon::yesterday()->subDays(1)
        );

        $this->assertSame(0.0, $report['total']);
    }

    public function test_cash_flow_separates_in_and_out_by_source_module(): void
    {
        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): a
        // folio payment (default sourceModule 'front_office') now also
        // requires an open Front Office shift.
        $this->openShiftFor('FO');

        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolio();
        $folioService = app(FolioService::class);

        $folioService->postPayment($folio, 20000, 'cash', $frontDesk);

        app(ExpenseService::class)->request(5000, 'Supplies', 'Cleaning', $accountant);
        $expense = Expense::first();
        app(ExpenseService::class)->approve($expense, $accountant);
        app(ExpenseService::class)->markPaid($expense, $accountant, $this->financialAccount('CASH'));

        $report = app(FinancialReportService::class)->cashFlow(Carbon::today(), Carbon::today());

        $this->assertSame(20000.0, $report['cash_in']);
        $this->assertSame(5000.0, $report['cash_out']['finance_expense']);
        $this->assertSame(15000.0, $report['net']);
    }

    public function test_expense_summary_splits_requested_from_actually_paid(): void
    {
        $accountant = $this->userWithRole('accountant');

        app(ExpenseService::class)->request(5000, 'Supplies', 'Cleaning', $accountant);
        app(ExpenseService::class)->request(3000, 'Supplies', 'More cleaning', $accountant);
        $paid = Expense::first();
        app(ExpenseService::class)->approve($paid, $accountant);
        app(ExpenseService::class)->markPaid($paid, $accountant, $this->financialAccount('CASH'));

        $report = app(FinancialReportService::class)->expenseSummary(Carbon::today(), Carbon::today());

        $this->assertSame(8000.0, $report['by_category']['Supplies']);
        $this->assertSame(8000.0, $report['total_requested']);
        $this->assertSame(5000.0, $report['total_paid'], 'Only the paid expense should count toward total_paid.');
    }

    public function test_receivables_aging_buckets_open_balances_by_age(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $folio = $this->openFolio();

        $ar = AccountsReceivable::create([
            'folio_id' => $folio->id,
            'guest_id' => $folio->stay->guest_id,
            'original_amount' => 15000,
            'amount_outstanding' => 15000,
            'status' => 'open',
            'due_date' => now()->addDays(30),
            'created_by' => $frontDesk->id,
        ]);
        $ar->forceFill(['created_at' => now()->subDays(45)])->save();

        $report = app(FinancialReportService::class)->receivablesAging();

        $this->assertSame(1, $report['buckets']['31-60 days']['count']);
        $this->assertSame(15000.0, $report['buckets']['31-60 days']['amount']);
        $this->assertSame(15000.0, $report['total_outstanding']);
    }

    public function test_income_statement_is_total_revenue_minus_total_requested_expenses(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolio();
        $folioService = app(FolioService::class);

        $folioService->postCharge($folio, 'room_charge', 50000, 'Room', $frontDesk);
        $folioService->postCharge($folio, 'restaurant_charge', 12000, 'Dinner', $frontDesk);
        app(ExpenseService::class)->request(8000, 'Supplies', 'Cleaning', $accountant);

        $statement = app(FinancialReportService::class)->incomeStatement(Carbon::today(), Carbon::today());

        $this->assertSame(62000.0, $statement['total_revenue']);
        $this->assertSame(8000.0, $statement['total_expenses']);
        $this->assertSame(54000.0, $statement['net_operating_result']);
    }

    public function test_income_statement_can_show_a_net_loss_when_expenses_exceed_revenue(): void
    {
        $accountant = $this->userWithRole('accountant');
        app(ExpenseService::class)->request(20000, 'Utilities', 'Electricity bill', $accountant);

        $statement = app(FinancialReportService::class)->incomeStatement(Carbon::today(), Carbon::today());

        $this->assertSame(0.0, $statement['total_revenue']);
        $this->assertSame(20000.0, $statement['total_expenses']);
        $this->assertSame(-20000.0, $statement['net_operating_result']);
    }

    public function test_duty_manager_cannot_view_the_income_statement(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.income-statement'))->assertForbidden();
    }

    public function test_duty_manager_cannot_view_financial_reports(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.financial'))->assertForbidden();
    }

    public function test_accountant_can_view_the_financial_report_and_kpi_dashboard(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get(route('reports.financial'))->assertOk();
        $this->actingAs($accountant)->get(route('reports.kpi-dashboard'))->assertOk();
    }
}
