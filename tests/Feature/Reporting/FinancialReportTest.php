<?php

namespace Tests\Feature\Reporting;

use App\Models\AccountsReceivable;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\Stay;
use App\Services\Finance\CashDisbursementService;
use App\Services\Finance\ExpenseService;
use App\Services\FrontOffice\FolioService;
use App\Services\PaymentLedgerService;
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

    /**
     * Direct request ("all in payment must be attached to an income
     * source that is to be represented or seen in the income statement").
     */
    public function test_income_statement_includes_general_payments_grouped_by_income_source(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->openShiftFor('FO');

        app(PaymentLedgerService::class)->record(
            amount: 20000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Rent',
        );
        app(PaymentLedgerService::class)->record(
            amount: 5000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Rent',
        );

        $statement = app(FinancialReportService::class)->incomeStatement(Carbon::today(), Carbon::today());

        $this->assertSame(25000.0, $statement['revenue_by_type']['Rent']);
        $this->assertSame(25000.0, $statement['total_revenue']);
        $this->assertSame(25000.0, $statement['net_operating_result']);
    }

    /**
     * Direct request ("out payment related to expenses also represented
     * in the income statement too") — audited gap: a ticket-less
     * disbursement with no linked Expense, and a payroll payout, were
     * both previously invisible on this report.
     */
    public function test_income_statement_includes_disbursements_without_an_expense_and_payroll_payouts(): void
    {
        $accountant = $this->userWithRole('accountant');

        app(CashDisbursementService::class)->disburse([
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'paid_to' => 'Local supplier',
            'purpose' => 'Emergency plumbing repair',
            'amount' => 20000,
            'authorized_by_name' => 'General Manager',
        ], $accountant);

        app(PaymentLedgerService::class)->record(
            amount: 150000,
            method: 'bank_transfer',
            sourceModule: 'payroll',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('BANK')->id,
        );

        $statement = app(FinancialReportService::class)->incomeStatement(Carbon::today(), Carbon::today());

        $this->assertSame(20000.0, $statement['expenses_by_category']['Emergency plumbing repair']);
        $this->assertSame(150000.0, $statement['expenses_by_category']['Payroll (salaries)']);
        $this->assertSame(170000.0, $statement['total_expenses']);
        $this->assertSame(-170000.0, $statement['net_operating_result']);
    }

    /**
     * A disbursement that DOES reference an approved Expense must not be
     * counted twice — once via the Expense row (expenseSummary()) and
     * again via the disbursement's own ledger post.
     */
    public function test_income_statement_does_not_double_count_a_disbursement_linked_to_an_expense(): void
    {
        $accountant = $this->userWithRole('accountant');

        app(ExpenseService::class)->request(8000, 'Supplies', 'Cleaning', $accountant);
        $expense = Expense::first();
        app(ExpenseService::class)->approve($expense, $accountant);

        app(CashDisbursementService::class)->disburse([
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'paid_to' => 'Cleaning supplier',
            'purpose' => 'Cleaning supplies',
            'amount' => 8000,
            'expense_id' => $expense->id,
            'authorized_by_name' => 'General Manager',
        ], $accountant);

        $statement = app(FinancialReportService::class)->incomeStatement(Carbon::today(), Carbon::today());

        $this->assertSame(8000.0, $statement['expenses_by_category']['Supplies']);
        $this->assertArrayNotHasKey('Cleaning supplies', $statement['expenses_by_category']);
        $this->assertSame(8000.0, $statement['total_expenses']);
    }

    /**
     * Sept 2026 revenue-recognition fix ("the income payment recorded by
     * the accountant is not completely recognised as income... because
     * the bar and the restaurant are not having their own device at the
     * moment so they pay their total amount sold to the accountant and
     * he reconciles them"): a general payment explicitly attributed to
     * the Bar department must count as real bar_charge revenue here, not
     * a disconnected "other income" line.
     */
    public function test_a_general_payment_attributed_to_bar_is_recognised_as_bar_revenue(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $bar = \App\Models\Department::where('code', 'BAR')->first();

        app(PaymentLedgerService::class)->record(
            amount: 27500,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'BAR AND RESTAURANT',
            departmentId: $bar->id,
        );

        $report = app(FinancialReportService::class)->revenueByChargeType(Carbon::today(), Carbon::today());

        $this->assertSame(27500.0, $report['by_type']['bar_charge']);
        $this->assertSame(27500.0, $report['total']);
    }

    /**
     * The same handover must not also appear under "other income" — it
     * would otherwise be counted twice on the Income Statement.
     */
    public function test_a_bar_attributed_general_payment_is_excluded_from_other_income_to_avoid_double_counting(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $restaurant = \App\Models\Department::where('code', 'REST')->first();

        app(PaymentLedgerService::class)->record(
            amount: 13000,
            method: 'mobile_money',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('MTN')->id,
            incomeSource: 'BAR AND RESTAURANT',
            departmentId: $restaurant->id,
        );
        // An ordinary, unattributed general payment still behaves exactly
        // as before.
        app(PaymentLedgerService::class)->record(
            amount: 5000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Rent',
        );

        $otherIncome = app(FinancialReportService::class)->otherIncomeBySource(Carbon::today(), Carbon::today());
        $statement = app(FinancialReportService::class)->incomeStatement(Carbon::today(), Carbon::today());

        $this->assertArrayNotHasKey('BAR AND RESTAURANT', $otherIncome['by_source']);
        $this->assertSame(5000.0, $otherIncome['total']);
        $this->assertSame(13000.0, $statement['revenue_by_type']['restaurant_charge']);
        $this->assertSame(5000.0, $statement['revenue_by_type']['Rent']);
        $this->assertSame(18000.0, $statement['total_revenue'], 'The 13,000 must be counted exactly once.');
    }

    /**
     * Historical payments recorded before this fix shipped (no
     * department_id at all, or Front Office's own automatic default)
     * must keep behaving exactly as before — never rewritten or
     * reclassified retroactively.
     */
    public function test_an_unattributed_general_payment_still_falls_under_other_income_as_before(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->openShiftFor('FO');

        app(PaymentLedgerService::class)->record(
            amount: 20000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Rent',
        );

        $report = app(FinancialReportService::class)->revenueByChargeType(Carbon::today(), Carbon::today());
        $otherIncome = app(FinancialReportService::class)->otherIncomeBySource(Carbon::today(), Carbon::today());

        $this->assertSame(0.0, $report['total']);
        $this->assertSame(20000.0, $otherIncome['by_source']['Rent']);
    }

    /**
     * Charge-types unification (Sept 2026): the old fixed BAR/REST-only
     * map is now a live read of `charge_types.department_id`, so this
     * mechanism generalizes to Bar Outside and Bucareaux too — see
     * FinancialReportService::manuallyRecognizedOutletRevenue()'s own
     * docblock for the "closing the exact other-income limitation"
     * reasoning.
     */
    public function test_a_general_payment_attributed_to_bar_outside_is_recognised_under_its_own_charge_type(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $barOutside = \App\Models\Department::where('code', 'BAROUT')->first();

        app(PaymentLedgerService::class)->record(
            amount: 9000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'BAR OUTSIDE',
            departmentId: $barOutside->id,
        );

        $report = app(FinancialReportService::class)->revenueByChargeType(Carbon::today(), Carbon::today());

        $this->assertSame(9000.0, $report['by_type']['bar_outside_charge']);
    }

    /**
     * A brand-new, admin-added charge type (not one of the original seven)
     * that an admin attaches to a department must be recognised here too,
     * with no further code changes — the whole point of reading this
     * mapping live from `charge_types` instead of a hardcoded list.
     */
    public function test_a_payment_attributed_to_a_department_on_a_brand_new_charge_type_is_recognised(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $accountant = $this->userWithRole('accountant');
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $bar = \App\Models\Department::where('code', 'BAR')->first();

        $chargeType = app(\App\Services\Finance\ChargeTypeService::class)->create([
            'name' => 'Karaoke Night',
            'department_id' => $bar->id,
        ], $owner);

        app(PaymentLedgerService::class)->record(
            amount: 4000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Karaoke handover',
            departmentId: $bar->id,
        );

        $report = app(FinancialReportService::class)->revenueByChargeType(Carbon::today(), Carbon::today());

        $this->assertSame(4000.0, $report['by_type'][$chargeType->code]);
    }

    /**
     * Front Office is deliberately excluded from this recognition
     * mechanism (it always has its own terminal and posts its own
     * charges directly) — see manuallyRecognizedOutletRevenue()'s own
     * docblock. A payment ever attributed to it falls under "other
     * income," unchanged.
     */
    public function test_a_payment_attributed_to_front_office_is_not_folded_into_any_charge_type(): void
    {
        $accountant = $this->userWithRole('accountant');
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $fo = \App\Models\Department::where('code', 'FO')->first();

        app(PaymentLedgerService::class)->record(
            amount: 6000,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'Front desk handover',
            departmentId: $fo->id,
        );

        $report = app(FinancialReportService::class)->revenueByChargeType(Carbon::today(), Carbon::today());
        $otherIncome = app(FinancialReportService::class)->otherIncomeBySource(Carbon::today(), Carbon::today());

        $this->assertSame(0.0, $report['total']);
        $this->assertSame(6000.0, $otherIncome['by_source']['Front desk handover']);
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
