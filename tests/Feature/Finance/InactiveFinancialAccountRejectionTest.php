<?php

namespace Tests\Feature\Finance;

use App\Exceptions\HmsException;
use App\Models\Folio;
use App\Models\Stay;
use App\Services\FrontOffice\FolioService;
use App\Services\PaymentLedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Correction round (audited defect #31, "require active financial account
 * for receipts/payouts including website mobile-money deposits"): every
 * manual money-entry point used to look up a FinancialAccount purely by
 * id (`FinancialAccount::findOrFail($id)`), trusting that the account
 * picklist in the view only ever offers active accounts. That's a UI
 * convenience, not a server-side guarantee — a crafted request naming a
 * since-deactivated account's id would previously succeed anyway. Fixed
 * two ways: every controller/service that resolves an account from a
 * submitted id now filters `is_active` before `findOrFail()` (a 404 for a
 * deactivated id, same as a genuinely nonexistent one), AND
 * PaymentLedgerService::record() — the single funnel every payment in the
 * system passes through — independently refuses an explicitly-passed
 * inactive account id, so no caller can bypass the guarantee even if a
 * future entry point forgets its own check.
 */
class InactiveFinancialAccountRejectionTest extends TestCase
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

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 30000]);
    }

    public function test_payment_ledger_service_refuses_an_explicitly_named_inactive_account(): void
    {
        $this->openShiftFor('FO');
        $cash = $this->financialAccount('CASH');
        $cash->update(['is_active' => false]);

        $actor = $this->userWithRole('accountant');

        $this->expectException(HmsException::class);

        app(PaymentLedgerService::class)->record(
            amount: 10000,
            method: 'cash',
            sourceModule: 'front_office',
            recordedBy: $actor,
            financialAccountId: $cash->id,
        );
    }

    public function test_folio_service_post_payment_refuses_an_inactive_account_via_the_central_ledger(): void
    {
        $this->openShiftFor('FO');
        $folio = $this->openFolio();
        $mtn = $this->financialAccount('MTN');
        $mtn->update(['is_active' => false]);

        $actor = $this->userWithRole('accountant');

        $this->expectException(HmsException::class);

        app(FolioService::class)->postPayment(
            $folio,
            10000,
            'mobile_money',
            $actor,
            financialAccountId: $mtn->id,
        );
    }

    public function test_deactivating_a_financial_account_does_not_stop_a_normal_payment_against_a_different_active_account(): void
    {
        $this->openShiftFor('FO');
        $folio = $this->openFolio();
        $mtn = $this->financialAccount('MTN');
        $mtn->update(['is_active' => false]);
        $bank = $this->financialAccount('BANK');

        $actor = $this->userWithRole('accountant');

        $transaction = app(FolioService::class)->postPayment(
            $folio,
            10000,
            'bank_transfer',
            $actor,
            financialAccountId: $bank->id,
        );

        $this->assertNotNull($transaction);
        $this->assertSame('-10000.00', $transaction->amount);
    }

    /**
     * HTTP-level check on one representative controller (the others —
     * PaymentController, FacilityAccessController, OrderController,
     * AccountsReceivableController, RefundController, ExpenseController,
     * PayrollController, CashDisbursementService, ReservationController —
     * all received the identical `where('is_active', true)` fix and share
     * the same underlying FinancialAccount model, so this pattern
     * generalizes; a 404 here (not a 500 or a silent success) is exactly
     * what `findOrFail()` on a filtered query produces.
     */
    public function test_posting_a_folio_payment_against_a_deactivated_account_id_returns_not_found(): void
    {
        $this->openShiftFor('FO');
        $folio = $this->openFolio();
        $cash = $this->financialAccount('CASH');
        $cash->update(['is_active' => false]);

        $accountant = $this->userWithRole('accountant');

        $this->actingAs($accountant)->post(route('folios.payment', $folio), [
            'amount' => 10000,
            'financial_account_id' => $cash->id,
        ])->assertNotFound();

        $this->assertSame('30000.00', $folio->fresh()->balance);
    }
}
