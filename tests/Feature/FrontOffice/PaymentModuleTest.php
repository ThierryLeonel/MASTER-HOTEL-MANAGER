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
            'income_source' => 'Event hall hire',
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
        // Direct request ("all in payment must be attached to an income
        // source ... in the income statement").
        $this->assertSame('Event hall hire', $payment->income_source);
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
            'income_source' => 'Guest advance',
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
            'income_source' => 'Event hall hire',
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
            'income_source' => 'x',
        ])->assertForbidden();
    }

    /**
     * Direct request ("all in payment must be attached to an income
     * source that is to be represented or seen in the income statement").
     */
    public function test_recording_a_general_payment_without_an_income_source_is_rejected(): void
    {
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 10000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'payer_name' => 'Visitor',
            'notes' => 'Rent',
        ]);

        $response->assertSessionHasErrors('income_source');
        $this->assertDatabaseMissing('payments', ['amount' => '10000.00']);
    }

    /**
     * Direct request ("payments can involve discount if granted") —
     * scoped to this screen. `amount` stays the real cash received;
     * discount fields are informational (gross vs net), no approval
     * workflow.
     */
    public function test_a_payment_can_record_a_discount_that_was_granted(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 18000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'payer_name' => 'Regular visitor',
            'notes' => 'Event hall hire',
            'income_source' => 'Event hall hire',
            'discount_amount' => 2000,
            'discount_reason' => 'Loyal customer',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame('18000.00', $payment->amount, 'The recorded amount must stay the real net cash received.');
        $this->assertSame('2000.00', $payment->discount_amount);
        $this->assertSame('Loyal customer', $payment->discount_reason);
        $this->assertTrue($payment->hasDiscount());
        $this->assertSame(20000.0, $payment->grossAmount());
    }

    public function test_a_discount_amount_without_a_reason_is_rejected(): void
    {
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 18000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Event hall hire',
            'income_source' => 'Event hall hire',
            'discount_amount' => 2000,
        ]);

        $response->assertSessionHasErrors('discount_reason');
        $this->assertDatabaseMissing('payments', ['amount' => '18000.00']);
    }

    /**
     * Follow-up request ("let the income source be a dropdown of the
     * income sources if other then state"): the form now submits the
     * sentinel '__other__' plus a free-text `income_source_other` field
     * when the accountant picks "Other" — the controller must resolve
     * that into the actual stated text, not store the literal sentinel.
     */
    public function test_a_payment_can_record_an_other_income_source_with_a_stated_value(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 7500,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Vending machine collection',
            'income_source' => '__other__',
            'income_source_other' => 'Vending machine income',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertSame('Vending machine income', $payment->income_source);
    }

    public function test_choosing_other_income_source_without_stating_it_is_rejected(): void
    {
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 7500,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Vending machine collection',
            'income_source' => '__other__',
        ]);

        $response->assertSessionHasErrors('income_source_other');
        $this->assertDatabaseMissing('payments', ['amount' => '7500.00']);
    }

    /**
     * Sept 2026 revenue-recognition fix ("the income payment recorded by
     * the accountant is not completely recognised as income... because
     * the bar and the restaurant are not having their own device at the
     * moment so they pay their total amount sold to the accountant and
     * he reconciles them"): the form can now explicitly attribute a
     * general payment to Bar/Restaurant/Bar Outside/Bucareaux.
     */
    public function test_a_payment_can_be_explicitly_attributed_to_the_bar_department(): void
    {
        $accountant = $this->userWithRole('accountant');
        $bar = \App\Models\Department::where('code', 'BAR')->first();

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 27500,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Bar takings handed over — no device yet',
            'income_source' => 'BAR AND RESTAURANT',
            'department_id' => $bar->id,
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame($bar->id, $payment->department_id);
    }

    /**
     * A crafted request naming a department this screen was never meant
     * to attribute to (e.g. Finance or Management, which have no sales
     * of their own) must be refused server-side, the same way an
     * inactive financial account is.
     */
    public function test_a_payment_cannot_be_attributed_to_a_non_outlet_department(): void
    {
        $accountant = $this->userWithRole('accountant');
        $finance = \App\Models\Department::where('code', 'FIN')->first();

        $response = $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 10000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Should not be allowed',
            'income_source' => 'Rent',
            'department_id' => $finance->id,
        ]);

        $response->assertSessionHasErrors('department_id');
        $this->assertDatabaseMissing('payments', ['amount' => '10000.00']);
    }

    /**
     * Leaving the new department selector blank must keep today's
     * behaviour exactly — auto-attributed to Front Office, same as every
     * general payment before this fix.
     */
    public function test_leaving_the_department_blank_still_defaults_to_front_office(): void
    {
        $accountant = $this->userWithRole('accountant');
        $fo = \App\Models\Department::where('code', 'FO')->first();

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Rent',
            'income_source' => 'Rent',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame($fo->id, $payment->department_id);
    }

    /**
     * Sept 2026 refinement ("the place in the payment form that request
     * on who handed the sales should either be the department or a
     * textbox that receive the name of the person handing"): a payment
     * can note who physically handed the cash over, independent of
     * (alongside, or instead of) a formal department attribution.
     */
    public function test_a_payment_can_note_who_personally_handed_it_in(): void
    {
        $accountant = $this->userWithRole('accountant');
        $bar = \App\Models\Department::where('code', 'BAR')->first();

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 27500,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Bar takings handed over',
            'income_source' => 'BAR AND RESTAURANT',
            'department_id' => $bar->id,
            'handed_over_by' => 'Ngozi, bar attendant',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertSame($bar->id, $payment->department_id);
        $this->assertSame('Ngozi, bar attendant', $payment->handed_over_by);
    }

    /**
     * A payment can name who handed it in WITHOUT attributing it to any
     * department — the two fields are independent, since sometimes
     * there's a name to record but no department the money should be
     * formally recognised under.
     */
    public function test_a_payment_can_note_who_handed_it_in_without_a_department(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Rent',
            'income_source' => 'Rent',
            'handed_over_by' => 'Mr. Tanga (tenant)',
        ])->assertRedirect();

        $payment = Payment::first();
        $fo = \App\Models\Department::where('code', 'FO')->first();
        $this->assertSame($fo->id, $payment->department_id, 'No department was chosen, so this still defaults to Front Office as always.');
        $this->assertSame('Mr. Tanga (tenant)', $payment->handed_over_by);
    }

    public function test_a_payment_with_no_discount_reports_none(): void
    {
        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('payments.store'), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'notes' => 'Rent',
            'income_source' => 'Rent',
        ])->assertRedirect();

        $payment = Payment::first();
        $this->assertFalse($payment->hasDiscount());
        $this->assertSame(5000.0, $payment->grossAmount());
    }
}
