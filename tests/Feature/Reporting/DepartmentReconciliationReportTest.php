<?php

namespace Tests\Feature\Reporting;

use App\Models\MenuItem;
use App\Models\Setting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §19) — "we will
 * need a report that reconciles daily payment by the various
 * departments... and their income recorded from their sales, lodging
 * etc."
 */
class DepartmentReconciliationReportTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        Setting::where('key', 'tax_rate_percent')->update(['value' => '0']);
    }

    public function test_a_closed_bar_order_appears_as_bar_sales_and_cash_in(): void
    {
        $this->openShiftFor('BAR');
        $staff = $this->userWithRole('bar_staff', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
        $item = MenuItem::factory()->forOutlet('bar')->create(['price' => 2000]);

        $this->actingAs($staff)->post(route('pos.orders.store', 'bar'));
        $order = \App\Models\Order::first();

        $this->actingAs($staff)->post(route('pos.orders.items.store', ['bar', $order]), [
            'menu_item_id' => $item->id,
            'quantity' => 2,
        ]);
        $this->actingAs($staff)->post(route('pos.orders.confirm', ['bar', $order]));
        $this->actingAs($staff)->post(route('pos.orders.close', ['bar', $order]), ['financial_account_id' => $this->financialAccount('CASH')->id]);

        $owner = $this->userWithRole('owner');
        $today = now()->toDateString();

        $response = $this->actingAs($owner)->get(route('reports.department-reconciliation', ['start' => $today, 'end' => $today]));
        $response->assertOk();

        $report = app(\App\Services\Reporting\DepartmentReconciliationReportService::class)
            ->forRange(now()->startOfDay(), now()->endOfDay());

        $barRow = $report['departments']->firstWhere(fn ($row) => $row['department']->code === 'BAR');

        $this->assertSame(4000.0, $barRow['sales']);
        $this->assertSame(4000.0, $barRow['cash_in']);
        $this->assertSame(4000.0, $barRow['net_cash']);
    }

    /**
     * Sept 2026 revenue-recognition fix ("the bar and the restaurant are
     * not having their own device at the moment so they pay their total
     * amount sold to the accountant and he reconciles them"): a general
     * payment explicitly attributed to Bar must count as that
     * department's own sales here, on top of any real POS orders it
     * also has — not show as 0 just because there was no POS device for
     * this particular handover.
     */
    public function test_a_manually_recorded_bar_handover_counts_as_bar_sales(): void
    {
        $accountant = $this->userWithRole('accountant');
        $bar = \App\Models\Department::where('code', 'BAR')->first();

        app(\App\Services\PaymentLedgerService::class)->record(
            amount: 27500,
            method: 'cash',
            sourceModule: 'general_payment',
            recordedBy: $accountant,
            financialAccountId: $this->financialAccount('CASH')->id,
            incomeSource: 'BAR AND RESTAURANT',
            departmentId: $bar->id,
        );

        $report = app(\App\Services\Reporting\DepartmentReconciliationReportService::class)
            ->forRange(now()->startOfDay(), now()->endOfDay());

        $barRow = $report['departments']->firstWhere(fn ($row) => $row['department']->code === 'BAR');

        $this->assertSame(27500.0, $barRow['sales']);
        $this->assertSame(27500.0, $barRow['cash_in'], 'The same payment already counted as Bar cash-in before this fix.');
    }

    public function test_the_print_and_csv_variants_are_reachable(): void
    {
        $owner = $this->userWithRole('owner');
        $today = now()->toDateString();

        $this->actingAs($owner)->get(route('reports.department-reconciliation.print', ['start' => $today, 'end' => $today]))->assertOk();
        $this->actingAs($owner)->get(route('reports.department-reconciliation.csv', ['start' => $today, 'end' => $today]))->assertOk();
    }

    public function test_a_cashier_without_financial_report_access_can_still_see_it_via_view_shift_reports(): void
    {
        $cashier = $this->userWithRole('cashier');
        $today = now()->toDateString();

        $this->actingAs($cashier)->get(route('reports.department-reconciliation', ['start' => $today, 'end' => $today]))->assertOk();
    }
}
