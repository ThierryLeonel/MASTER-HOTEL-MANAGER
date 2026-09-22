<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Guest;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Post-9.8 fix round ("there is supposed to be a payment module, where
 * the receptionist records all payments... other payments from may be
 * rent, etc can be made by visitors") — PaymentController, a
 * general-purpose entry point into PaymentLedgerService that isn't tied
 * to a folio, POS order, expense, or payroll run.
 */
class PaymentModuleTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        // No explicit setup needed for PaymentLedgerService::record()'s
        // open-accounting-day requirement — AccountingDayService::today()
        // auto-creates today's day as 'open' on first use.

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): a
        // general payment is attributed to Front Office, which now
        // requires an open shift.
        $this->openShiftFor('FO');
    }

    public function test_front_desk_can_record_a_general_payment_not_tied_to_a_guest(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);

        $this->actingAs($frontDesk)->post(route('payments.store'), [
            'amount' => 15000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'payer_name' => 'Event hall visitor',
            'notes' => 'Event hall hire, one evening',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertSame('15000.00', $payment->amount);
        $this->assertSame('general_payment', $payment->source_module);
        $this->assertNull($payment->guest_id);
        $this->assertStringContainsString('Event hall visitor', $payment->notes);
        // Fourth post-9.8 fix round (day-close rebuild): this is exactly
        // the kind of payment the owner described as needing to
        // reconcile — it now carries the real account it landed in.
        $this->assertSame($this->financialAccount('CASH')->id, $payment->financial_account_id);
    }

    public function test_front_desk_can_record_a_payment_against_an_existing_guest(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();

        $this->actingAs($frontDesk)->post(route('payments.store'), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('MTN')->id,
            'guest_id' => $guest->id,
            'notes' => 'Advance payment',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame($guest->id, $payment->guest_id);
        $this->assertSame('mobile_money', $payment->method);
    }

    /**
     * Follow-up round ("payment entries" — the accountant should be able
     * to make payment entries too): the general-purpose Payments module
     * had never included the accountant, even though recording this kind
     * of income (rent, a one-off visitor payment) is squarely accountant
     * work — 'record-payment' was added to close that gap.
     */
    public function test_accountant_can_record_a_general_payment(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 25000,
            'financial_account_id' => $this->financialAccount('BANK')->id,
            'payer_name' => 'Event hall visitor',
            'notes' => 'Event hall hire, one evening',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertSame('25000.00', $payment->amount);
        $this->assertSame('bank_transfer', $payment->method);
    }

    public function test_a_role_without_record_payment_cannot_access_the_payments_module(): void
    {
        $barStaff = $this->pinUser('bar_staff');

        $this->actingAs($barStaff)->get(route('payments.index'))->assertForbidden();
        $this->actingAs($barStaff)->post(route('payments.store'), [
            'amount' => 1000, 'financial_account_id' => $this->financialAccount('CASH')->id, 'notes' => 'x',
        ])->assertForbidden();
    }
}
