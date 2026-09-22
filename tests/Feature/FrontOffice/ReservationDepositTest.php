<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Payment;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * "the client can also make advance payment for the lodging so that
 * should be included in the folio so as to get the clients outstanding
 * balance... the advance on reservation are also payments" (user's own
 * words). ReservationService::recordDeposit() is the write path this
 * unlocks — previously `deposit_paid` was a schema column nobody could
 * ever actually set through the app, and even if it had been set it
 * never touched the central payments ledger. See
 * CheckInCheckOutTest::test_check_in_applies_a_previously_recorded_deposit_as_a_folio_credit_without_double_ledgering()
 * for the end-to-end proof that check-in applies this deposit to the
 * folio without ledgering it a second time.
 */
class ReservationDepositTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): a
        // reservation deposit is attributed to Front Office, which now
        // requires an open shift.
        $this->openShiftFor('FO');
    }

    public function test_front_desk_can_record_a_deposit_on_a_pending_reservation(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->create(['status' => 'pending', 'deposit_required' => 10000]);

        $this->actingAs($frontDesk)->post(route('reservations.deposit', $reservation), [
            'amount' => 10000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $reservation->refresh();
        $this->assertSame('10000.00', $reservation->deposit_paid);

        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertSame('10000.00', $payment->amount);
        $this->assertSame('cash', $payment->method);
        $this->assertSame('reservation_deposit', $payment->source_module);
        $this->assertSame($reservation->guest_id, $payment->guest_id);
        $this->assertSame($this->financialAccount('CASH')->id, $payment->financial_account_id);
    }

    public function test_a_deposit_can_be_recorded_in_more_than_one_instalment(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->create(['status' => 'confirmed', 'deposit_required' => 20000]);

        $this->actingAs($frontDesk)->post(route('reservations.deposit', $reservation), [
            'amount' => 12000, 'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $this->actingAs($frontDesk)->post(route('reservations.deposit', $reservation), [
            'amount' => 8000, 'financial_account_id' => $this->financialAccount('MTN')->id,
        ])->assertRedirect();

        $this->assertSame('20000.00', $reservation->fresh()->deposit_paid);
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_a_deposit_cannot_be_recorded_on_a_cancelled_reservation(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->create(['status' => 'cancelled']);

        $this->actingAs($frontDesk)->post(route('reservations.deposit', $reservation), [
            'amount' => 5000, 'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertSessionHasErrors('error');

        $this->assertSame('0.00', $reservation->fresh()->deposit_paid);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_role_without_post_folio_payment_cannot_record_a_deposit(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $reservation = Reservation::factory()->create(['status' => 'pending']);

        $this->actingAs($barStaff)->post(route('reservations.deposit', $reservation), [
            'amount' => 5000, 'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertForbidden();
    }

    public function test_deposit_amount_must_be_positive(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->create(['status' => 'pending']);

        $this->actingAs($frontDesk)->post(route('reservations.deposit', $reservation), [
            'amount' => 0, 'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertSessionHasErrors('amount');
    }
}
