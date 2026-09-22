<?php

namespace Tests\Feature;

use App\Models\Folio;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Services\Finance\CashReconciliationService;
use App\Services\FrontOffice\CheckOutService;
use App\Services\FrontOffice\FolioService;
use App\Services\Pos\OrderService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): the printable-document
 * routes added this phase — reservation confirmation, guest invoice,
 * final hotel bill, payment receipt, bar/restaurant receipt, daily cash
 * closing. Every one of these reuses an existing screen's own
 * permission/data rather than introducing a parallel authorization path,
 * so these tests check that reuse held (same access as the screen the
 * print link is reached from) plus the one genuine new business rule:
 * the final bill is gated on the stay actually being closed.
 */
class PrintableDocumentsTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21):
        // FolioService::postPayment() (used directly by a couple of tests
        // below) always ledgers through PaymentLedgerService, which now
        // gates Front Office — harmless for every other test here, which
        // either doesn't touch the ledger or calls a service directly in
        // a way that bypasses the controller-level gate entirely.
        $this->openShiftFor('FO');
    }

    public function test_reservation_confirmation_is_printable_by_a_role_with_manage_reservations(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $reservation = Reservation::factory()->create();

        $response = $this->actingAs($frontDesk)->get(route('reservations.print', $reservation));

        $response->assertOk();
        $response->assertSee($reservation->reservation_number);
        $response->assertSee('Reservation Confirmation');
    }

    public function test_reservation_confirmation_is_blocked_for_a_role_without_manage_reservations(): void
    {
        $cashier = $this->userWithRole('cashier');
        $reservation = Reservation::factory()->create();

        $this->actingAs($cashier)->get(route('reservations.print', $reservation))->assertForbidden();
    }

    private function activeStayWithFolio(): Stay
    {
        $room = Room::factory()->create();
        $stay = Stay::factory()->for($room)->create(['status' => 'active']);
        Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);

        return $stay->fresh(['folio']);
    }

    public function test_guest_invoice_is_printable_for_an_active_stay_and_shows_real_charges(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = $this->activeStayWithFolio();
        app(FolioService::class)->postCharge($stay->folio, 'room_charge', 30000, 'Room', $frontDesk);

        $response = $this->actingAs($frontDesk)->get(route('stays.invoice.print', $stay));

        $response->assertOk();
        $response->assertSee('Guest Invoice');
        $response->assertSee('30,000');
    }

    public function test_final_bill_is_blocked_until_the_stay_is_actually_closed(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = $this->activeStayWithFolio();

        $this->actingAs($frontDesk)->get(route('stays.final-bill.print', $stay))
            ->assertSessionHasErrors('error');
    }

    public function test_final_bill_is_printable_once_the_stay_is_closed(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $stay = $this->activeStayWithFolio();

        app(CheckOutService::class)->checkOut($stay, $dutyManager);

        $response = $this->actingAs($dutyManager)->get(route('stays.final-bill.print', $stay->fresh()));

        $response->assertOk();
        $response->assertSee('Final Hotel Bill');
    }

    public function test_payment_receipt_is_printable_and_shows_the_real_amount(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = $this->activeStayWithFolio();
        $payment = app(FolioService::class)->postPayment($stay->folio, 20000, 'cash', $frontDesk);

        $response = $this->actingAs($frontDesk)->get(route('folios.receipt.print', $payment));

        $response->assertOk();
        $response->assertSee('Payment Receipt');
        $response->assertSee('20,000');
    }

    public function test_payment_receipt_404s_for_a_charge_transaction_not_a_payment(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = $this->activeStayWithFolio();
        $charge = app(FolioService::class)->postCharge($stay->folio, 'room_charge', 30000, 'Room', $frontDesk);

        $this->actingAs($frontDesk)->get(route('folios.receipt.print', $charge))->assertNotFound();
    }

    public function test_a_cashier_without_manage_guests_can_still_print_a_receipt_for_a_payment_they_posted(): void
    {
        $cashier = $this->userWithRole('cashier');
        $stay = $this->activeStayWithFolio();
        $payment = app(FolioService::class)->postPayment($stay->folio, 15000, 'cash', $cashier);

        $this->actingAs($cashier)->get(route('folios.receipt.print', $payment))->assertOk();
    }

    public function test_pos_receipt_is_reachable_only_by_the_matching_outlet_role(): void
    {
        $barStaff = $this->userWithRole('bar_staff');
        $restaurantStaff = $this->userWithRole('restaurant_staff');
        $order = app(OrderService::class)->open('bar', $barStaff, null);

        $this->actingAs($barStaff)->get(route('pos.orders.receipt', ['bar', $order]))->assertOk();
        $this->actingAs($restaurantStaff)->get(route('pos.orders.receipt', ['bar', $order]))->assertForbidden();
    }

    public function test_cash_closing_is_printable_by_whoever_recorded_it(): void
    {
        $cashier = $this->userWithRole('cashier');
        $reconciliation = app(CashReconciliationService::class)->record(50000, $cashier, 'Test note');

        $this->actingAs($cashier)->get(route('finance.accounting-days.cash-reconciliation.print', $reconciliation))->assertOk();
    }

    public function test_cash_closing_print_is_blocked_for_a_role_with_neither_reconciliation_permission(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $cashier = $this->userWithRole('cashier');
        $reconciliation = app(CashReconciliationService::class)->record(50000, $cashier, null);

        $this->actingAs($frontDesk)->get(route('finance.accounting-days.cash-reconciliation.print', $reconciliation))->assertForbidden();
    }
}
