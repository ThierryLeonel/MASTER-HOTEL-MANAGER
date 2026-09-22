<?php

namespace Tests\Feature\Reporting;

use App\Models\Expense;
use App\Models\Folio;
use App\Models\Stay;
use App\Services\Finance\ExpenseService;
use App\Services\FrontOffice\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): the "Payment Summary"
 * printable/CSV document — lists real rows from the central `payments`
 * ledger, the same ledger FinancialReportService::cashFlow() already
 * aggregates from (see FinancialReportServiceTest... actually
 * FinancialReportTest — for the aggregate-level coverage).
 */
class PaymentSummaryReportTest extends TestCase
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

    public function test_payment_summary_lists_real_payments_from_both_front_office_and_expense_payouts(): void
    {
        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): a
        // folio payment (default sourceModule 'front_office') now also
        // requires an open Front Office shift.
        $this->openShiftFor('FO');

        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolio();

        app(FolioService::class)->postPayment($folio, 20000, 'cash', $frontDesk);

        app(ExpenseService::class)->request(5000, 'Supplies', 'Cleaning', $accountant);
        $expense = Expense::first();
        app(ExpenseService::class)->approve($expense, $accountant);
        app(ExpenseService::class)->markPaid($expense, $accountant, $this->financialAccount('CASH'));

        $response = $this->actingAs($accountant)->get(route('reports.payment-summary', [
            'start' => Carbon::today()->toDateString(), 'end' => Carbon::today()->toDateString(),
        ]));

        $response->assertOk();
        $response->assertSee('20,000');
        $response->assertSee('5,000');
    }

    public function test_duty_manager_cannot_view_the_payment_summary(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');

        $this->actingAs($dutyManager)->get(route('reports.payment-summary'))->assertForbidden();
    }

    public function test_payment_summary_print_view_renders_for_a_permitted_role(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get(route('reports.payment-summary.print'))->assertOk();
    }

    public function test_payment_summary_csv_downloads_with_the_correct_content_type_and_real_rows(): void
    {
        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): a
        // folio payment (default sourceModule 'front_office') now also
        // requires an open Front Office shift.
        $this->openShiftFor('FO');

        $accountant = $this->userWithRole('accountant');
        $frontDesk = $this->userWithRole('front_desk');
        $folio = $this->openFolio();
        app(FolioService::class)->postPayment($folio, 20000, 'cash', $frontDesk);

        $response = $this->actingAs($accountant)->get(route('reports.payment-summary.csv'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $this->assertStringContainsString('20000', $response->streamedContent());
    }
}
