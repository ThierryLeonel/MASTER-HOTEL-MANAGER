<?php

namespace Tests\Feature\Finance;

use App\Models\CashDisbursement;
use App\Models\Department;
use App\Models\DisbursementTicket;
use App\Models\Payment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §18) — "the
 * accountant or cashier has to be able to record cash disbursement not
 * just expense only," with the user's own two-path authorization model:
 * a management-created ticket normally, or a ticket-less disbursement
 * that must clearly capture the authorizing superior's details instead.
 */
class CashDisbursementTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
    }

    public function test_a_manager_creates_a_ticket_and_a_cashier_disburses_against_it(): void
    {
        $manager = $this->userWithRole('duty_manager');
        $cashier = $this->userWithRole('cashier');
        $department = Department::where('code', 'BAR')->first();

        $this->actingAs($manager)->post(route('finance.disbursement-tickets.store'), [
            'department_id' => $department->id,
            'purpose' => 'Buy ice for the bar',
            'amount' => 10000,
        ])->assertRedirect();

        $ticket = DisbursementTicket::first();
        $this->assertSame('open', $ticket->status);

        $this->actingAs($cashier)->post(route('finance.cash-disbursements.store'), [
            'ticket_id' => $ticket->id,
            'amount' => 10000,
            'paid_to' => 'Ice Supplier Co.',
            'purpose' => 'Buy ice for the bar',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $disbursement = CashDisbursement::first();
        $this->assertSame($ticket->id, $disbursement->ticket_id);
        $this->assertSame('used', $ticket->fresh()->status);
        $this->assertNull($disbursement->authorized_by_user_id);
        $this->assertNull($disbursement->authorized_by_name);

        $payment = Payment::where('reference_type', CashDisbursement::class)->where('reference_id', $disbursement->id)->first();
        $this->assertNotNull($payment, 'A cash disbursement must post to the central payment ledger.');
        $this->assertSame('out', $payment->direction);
        $this->assertSame($department->id, $payment->department_id);
        $this->assertSame('10000.00', $payment->amount);
    }

    public function test_a_disbursement_cannot_exceed_its_tickets_authorized_amount(): void
    {
        $manager = $this->userWithRole('duty_manager');
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($manager)->post(route('finance.disbursement-tickets.store'), [
            'purpose' => 'Fuel for generator',
            'amount' => 5000,
        ]);
        $ticket = DisbursementTicket::first();

        $response = $this->actingAs($cashier)->post(route('finance.cash-disbursements.store'), [
            'ticket_id' => $ticket->id,
            'amount' => 8000,
            'paid_to' => 'Fuel Station',
            'purpose' => 'Fuel for generator',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, CashDisbursement::count());
        $this->assertSame('open', $ticket->fresh()->status);
    }

    public function test_a_ticketless_disbursement_requires_a_named_authorizer(): void
    {
        $cashier = $this->userWithRole('cashier');

        $response = $this->actingAs($cashier)->post(route('finance.cash-disbursements.store'), [
            'amount' => 3000,
            'paid_to' => 'Taxi driver',
            'purpose' => 'Emergency errand',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, CashDisbursement::count(), 'A ticket-less disbursement must not be recorded without a named authorizer.');
    }

    public function test_a_ticketless_disbursement_with_a_named_authorizer_succeeds(): void
    {
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($cashier)->post(route('finance.cash-disbursements.store'), [
            'amount' => 3000,
            'paid_to' => 'Taxi driver',
            'purpose' => 'Emergency errand',
            'financial_account_id' => $this->financialAccount('CASH')->id,
            'authorized_by_name' => 'Gabriel Manager',
            'authorized_by_title' => 'General Manager',
        ])->assertRedirect();

        $disbursement = CashDisbursement::first();
        $this->assertNull($disbursement->ticket_id);
        $this->assertSame('Gabriel Manager', $disbursement->authorized_by_name);
        $this->assertStringContainsString('Gabriel Manager', $disbursement->authorizationLabel());
    }

    public function test_only_an_open_ticket_can_be_disbursed_against(): void
    {
        $manager = $this->userWithRole('duty_manager');
        $cashier = $this->userWithRole('cashier');

        $this->actingAs($manager)->post(route('finance.disbursement-tickets.store'), [
            'purpose' => 'Office supplies',
            'amount' => 4000,
        ]);
        $ticket = DisbursementTicket::first();

        $this->actingAs($manager)->post(route('finance.disbursement-tickets.cancel', $ticket), [
            'reason' => 'No longer needed',
        ])->assertRedirect();

        $this->assertSame('cancelled', $ticket->fresh()->status);

        $response = $this->actingAs($cashier)->post(route('finance.cash-disbursements.store'), [
            'ticket_id' => $ticket->id,
            'amount' => 4000,
            'paid_to' => 'Stationery Shop',
            'purpose' => 'Office supplies',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, CashDisbursement::count());
    }
}
