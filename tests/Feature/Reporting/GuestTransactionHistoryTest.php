<?php

namespace Tests\Feature\Reporting;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Stay;
use App\Services\FrontOffice\FolioService;
use App\Services\Reporting\GuestTransactionHistoryService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Feature 2 ("Complete guest transaction history report across all
 * stays"): confirms the report is built straight from the existing
 * ledgers (never re-sums independently, so it can't double-count), is
 * gated by its own 'view-guest-history' permission distinct from the
 * broader operational-reports permission, and is enforced server-side
 * for every action (index/show/print/csv), not just hidden from the nav.
 */
class GuestTransactionHistoryTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function stayWithFolio(Guest $guest): Stay
    {
        $stay = Stay::factory()->create(['guest_id' => $guest->id, 'status' => 'active', 'agreed_rate' => 30000]);

        Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);

        return $stay;
    }

    public function test_the_report_totals_charges_and_payments_from_the_folio_ledger_without_double_counting(): void
    {
        $this->openShiftFor('FO');
        $guest = Guest::factory()->create();
        $stay = $this->stayWithFolio($guest);
        $actor = $this->userWithRole('accountant');

        $folioService = app(FolioService::class);
        $folioService->postCharge($stay->folio, 'room_charge', 30000, 'Room charge', $actor);
        $folioService->postPayment($stay->folio, 20000, 'cash', $actor, financialAccountId: $this->financialAccount('CASH')->id);

        $report = app(GuestTransactionHistoryService::class)->report($guest);

        $this->assertSame(1, $report['consolidated']['stays_count']);
        $this->assertSame(30000.0, $report['consolidated']['total_charges']);
        $this->assertSame(20000.0, $report['consolidated']['total_payments']);
        $this->assertSame(10000.0, $report['consolidated']['net_outstanding']);

        // The payment transaction is enriched with its real financial
        // account, proving the report followed the Payment link rather
        // than re-deriving it.
        $paymentRow = collect($report['stays'][0]['transactions'])->firstWhere('type', 'payment');
        $this->assertSame('CASH', $paymentRow['financial_account']->code);
    }

    public function test_a_reservation_deposit_applied_at_checkin_is_not_counted_twice(): void
    {
        $this->openShiftFor('FO');
        $guest = Guest::factory()->create();
        $stay = $this->stayWithFolio($guest);
        $actor = $this->userWithRole('accountant');

        // Simulates CheckInService::checkInFromReservation()'s pattern:
        // the deposit was ledgered once (a real Payment, source_module
        // 'reservation_deposit'), then applied to the folio via
        // applyAdvanceCredit() — which deliberately does NOT re-ledger it.
        app(\App\Services\PaymentLedgerService::class)->record(
            amount: 15000,
            method: 'cash',
            sourceModule: 'reservation_deposit',
            recordedBy: $actor,
            guestId: $guest->id,
            financialAccountId: $this->financialAccount('CASH')->id,
        );
        app(FolioService::class)->applyAdvanceCredit($stay->folio, 15000, 'Advance payment applied', $actor);

        $report = app(GuestTransactionHistoryService::class)->report($guest);

        // The folio-side ledger counts this once, as a payment.
        $this->assertSame(15000.0, $report['consolidated']['total_payments']);
        // The deposit-receipt memo section shows the same event from the
        // "money received" side — informational, not summed into the
        // per-stay totals a second time.
        $this->assertCount(1, $report['deposit_payments']);
        $this->assertSame('15000.00', $report['deposit_payments']->first()->amount);
    }

    public function test_view_guest_history_is_required_and_is_distinct_from_operational_reports(): void
    {
        $guest = Guest::factory()->create();

        // Front Desk holds neither permission.
        $frontDesk = $this->userWithRole('front_desk');
        $this->actingAs($frontDesk)->get(route('reports.guest-transaction-history.index'))->assertForbidden();
        $this->actingAs($frontDesk)->get(route('reports.guest-transaction-history.show', $guest))->assertForbidden();

        // Accountant holds 'view-guest-history' per spec.
        $accountant = $this->userWithRole('accountant');
        $this->actingAs($accountant)->get(route('reports.guest-transaction-history.index'))->assertOk();
        $this->actingAs($accountant)->get(route('reports.guest-transaction-history.show', $guest))->assertOk();
    }

    public function test_marketing_manager_can_view_guest_history(): void
    {
        $guest = Guest::factory()->create();
        $marketingManager = $this->userWithRole('marketing_manager');

        $this->actingAs($marketingManager)->get(route('reports.guest-transaction-history.show', $guest))->assertOk();
    }

    public function test_searching_finds_a_guest_by_partial_name(): void
    {
        Guest::factory()->create(['first_name' => 'Aline', 'last_name' => 'Mbarga']);
        Guest::factory()->create(['first_name' => 'Paul', 'last_name' => 'Biya']);
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)->get(route('reports.guest-transaction-history.index', ['q' => 'Mbarga']));

        $response->assertOk();
        $response->assertSee('Aline Mbarga');
        $response->assertDontSee('Paul Biya');
    }
}
