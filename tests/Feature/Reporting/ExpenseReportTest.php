<?php

namespace Tests\Feature\Reporting;

use App\Services\Finance\ExpenseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): the "Expense Report"
 * printable/CSV document — individual expense line items in a date
 * range, distinct from the Financial report's category-only aggregate.
 */
class ExpenseReportTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_expense_report_lists_individual_expenses_with_their_status(): void
    {
        $accountant = $this->userWithRole('accountant');
        app(ExpenseService::class)->request(5000, 'Supplies', 'Cleaning', $accountant);
        app(ExpenseService::class)->request(3000, 'Utilities', 'Electricity', $accountant);

        $response = $this->actingAs($accountant)->get(route('reports.expense-report'));

        $response->assertOk();
        $response->assertSee('Cleaning');
        $response->assertSee('Electricity');
        $response->assertSee('pending');
    }

    public function test_front_desk_cannot_view_the_expense_report(): void
    {
        $frontDesk = $this->userWithRole('front_desk');

        $this->actingAs($frontDesk)->get(route('reports.expense-report'))->assertForbidden();
    }

    public function test_expense_report_print_view_renders(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->get(route('reports.expense-report.print'))->assertOk();
    }

    public function test_expense_report_csv_contains_the_real_expense_number_and_amount(): void
    {
        $accountant = $this->userWithRole('accountant');
        app(ExpenseService::class)->request(5000, 'Supplies', 'Cleaning', $accountant);
        $expense = \App\Models\Expense::first();

        $response = $this->actingAs($accountant)->get(route('reports.expense-report.csv'));

        $response->assertOk();
        $response->assertHeader('content-type', 'text/csv; charset=UTF-8');
        $content = $response->streamedContent();
        $this->assertStringContainsString($expense->expense_number, $content);
        $this->assertStringContainsString('5000', $content);
    }
}
