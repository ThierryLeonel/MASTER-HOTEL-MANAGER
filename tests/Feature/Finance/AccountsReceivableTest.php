<?php

namespace Tests\Feature\Finance;

use App\Models\AccountsReceivable;
use App\Models\Company;
use App\Models\Folio;
use App\Models\Payment;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * "Client debt" (PHASE9_AUDIT_AND_PLAN.md §22) — the user's clarifying
 * answer picked "record a payment against it" (never a write-off/forgive
 * feature — that option was explicitly declined) plus "track debt for a
 * company/corporate account, not just one guest." This covers both:
 * AccountsReceivableService::recordSettlement() and Company's debt
 * rollup across several stays.
 */
class AccountsReceivableTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function openAr(array $overrides = []): AccountsReceivable
    {
        $stay = Stay::factory()->create(['status' => 'closed']);
        $folio = Folio::factory()->for($stay)->create(['status' => 'closed', 'balance' => 0]);
        $actor = $this->userWithRole('front_desk');

        return AccountsReceivable::create(array_merge([
            'folio_id' => $folio->id,
            'guest_id' => $stay->guest_id,
            'original_amount' => 20000,
            'amount_outstanding' => 20000,
            'status' => 'open',
            'due_date' => now()->addDays(30),
            'created_by' => $actor->id,
        ], $overrides));
    }

    public function test_a_partial_settlement_reduces_the_outstanding_balance_and_leaves_it_open(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $ar = $this->openAr();

        $this->actingAs($frontDesk)->post(route('finance.accounts-receivable.settle', $ar), [
            'amount' => 8000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $ar->refresh();
        $this->assertSame('12000.00', $ar->amount_outstanding);
        $this->assertSame('partially_paid', $ar->status);

        $payment = Payment::where('source_module', 'ar_settlement')->first();
        $this->assertNotNull($payment, 'A debt settlement must post to the central payment ledger.');
        $this->assertSame('in', $payment->direction);
        $this->assertSame('8000.00', $payment->amount);
    }

    public function test_settling_the_full_outstanding_amount_marks_the_debt_paid(): void
    {
        $accountant = $this->userWithRole('accountant');
        $ar = $this->openAr(['amount_outstanding' => 20000]);

        $this->actingAs($accountant)->post(route('finance.accounts-receivable.settle', $ar), [
            'amount' => 20000,
            'financial_account_id' => $this->financialAccount('BANK')->id,
        ])->assertRedirect();

        $ar->refresh();
        $this->assertSame('0.00', $ar->amount_outstanding);
        $this->assertSame('paid', $ar->status);
    }

    public function test_a_settlement_exceeding_the_outstanding_balance_is_rejected(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $ar = $this->openAr(['amount_outstanding' => 5000]);

        $response = $this->actingAs($frontDesk)->post(route('finance.accounts-receivable.settle', $ar), [
            'amount' => 9000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame('5000.00', $ar->fresh()->amount_outstanding);
        $this->assertSame(0, Payment::where('source_module', 'ar_settlement')->count());
    }

    public function test_an_already_paid_debt_cannot_be_settled_again(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $ar = $this->openAr(['status' => 'paid', 'amount_outstanding' => 0]);

        $response = $this->actingAs($frontDesk)->post(route('finance.accounts-receivable.settle', $ar), [
            'amount' => 1000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
    }

    public function test_bar_staff_cannot_settle_a_debt(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $ar = $this->openAr();

        $this->actingAs($barStaff)->post(route('finance.accounts-receivable.settle', $ar), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertForbidden();
    }

    /**
     * ar_settlement is deliberately absent from
     * PaymentLedgerService::SOURCE_MODULE_DEPARTMENT_CODES, so it's never
     * auto-attributed to a department and the shift gate never applies —
     * settling old debt shouldn't require today's Front Office shift to
     * be open.
     */
    public function test_settling_a_debt_never_requires_an_open_shift(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $ar = $this->openAr();

        // Deliberately no openShiftFor() call anywhere in this test.
        $this->actingAs($frontDesk)->post(route('finance.accounts-receivable.settle', $ar), [
            'amount' => 5000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $this->assertSame('15000.00', $ar->fresh()->amount_outstanding);
    }

    /**
     * The second half of the user's clarification: debt tracked at the
     * COMPANY level, rolled up across every stay it was billed for, not
     * just a single guest's own balance.
     */
    public function test_a_companys_total_outstanding_debt_is_summed_across_every_stay_it_was_billed_for(): void
    {
        $company = Company::create(['name' => 'Acme Logistics Ltd', 'is_active' => true]);

        $this->openAr(['company_id' => $company->id, 'amount_outstanding' => 15000, 'status' => 'open']);
        $this->openAr(['company_id' => $company->id, 'amount_outstanding' => 5000, 'status' => 'partially_paid']);
        // A fully paid debt for the same company must not count.
        $this->openAr(['company_id' => $company->id, 'amount_outstanding' => 0, 'status' => 'paid']);
        // A different company's debt must not bleed into this one's total.
        $otherCompany = Company::create(['name' => 'Other Corp', 'is_active' => true]);
        $this->openAr(['company_id' => $otherCompany->id, 'amount_outstanding' => 99999, 'status' => 'open']);

        $this->assertSame(20000.0, $company->fresh()->totalOutstanding());
    }

    public function test_front_desk_can_register_a_new_company(): void
    {
        $frontDesk = $this->userWithRole('front_desk');

        $this->actingAs($frontDesk)->post(route('companies.store'), [
            'name' => 'Kribi Exports SARL',
            'contact_name' => 'Paul Biya Jr.',
            'phone' => '677000111',
            'email' => 'billing@kribiexports.example',
        ])->assertRedirect();

        $company = Company::where('name', 'Kribi Exports SARL')->first();
        $this->assertNotNull($company);
        $this->assertTrue($company->is_active);
    }

    public function test_bar_staff_cannot_register_a_company(): void
    {
        $barStaff = $this->pinUser('bar_staff');

        $this->actingAs($barStaff)->post(route('companies.store'), [
            'name' => 'Should Not Be Created SARL',
        ])->assertForbidden();

        $this->assertSame(0, Company::count());
    }
}
