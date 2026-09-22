<?php

namespace Tests\Feature\Finance;

use App\Models\Folio;
use App\Models\RefundRequest;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function openFolioWithBalance(float $balance): Folio
    {
        $stay = Stay::factory()->create(['status' => 'active']);

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => $balance]);
    }

    public function test_front_desk_can_request_a_refund_without_moving_any_money(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $folio = $this->openFolioWithBalance(-10000); // guest overpaid, is owed money back

        $this->actingAs($frontDesk)->post(route('finance.refunds.store', $folio), [
            'amount' => 10000,
            'reason' => 'Guest overpaid at checkout',
            'method' => 'cash',
        ])->assertRedirect();

        $refund = RefundRequest::first();
        $this->assertSame('pending', $refund->status);
        $this->assertSame('-10000.00', $folio->fresh()->balance, 'Requesting a refund must not, by itself, touch the folio.');
        $this->assertDatabaseCount('payments', 0);
    }

    /**
     * Sixth post-9.8 fix round ("all payments are to be made in the
     * accounts that control the payment methods"): the requester's
     * `method` guess is stored on the request purely as a record of what
     * was asked for — it's the approver's `financial_account_id` that
     * actually determines what reaches the ledger, exactly like paying an
     * expense already works. Here the requester asks for mobile_money but
     * the approver actually pays from the Bank account, and it's the Bank
     * account's translated method (`bank_transfer`) that's posted, not
     * the original mobile_money guess.
     */
    public function test_approving_a_refund_posts_to_the_folio_and_the_central_ledger_using_the_approvers_chosen_account(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolioWithBalance(-10000);

        $this->actingAs($frontDesk)->post(route('finance.refunds.store', $folio), [
            'amount' => 10000,
            'reason' => 'Guest overpaid at checkout',
            'method' => 'mobile_money',
        ]);
        $refund = RefundRequest::first();

        $this->actingAs($accountant)->post(route('finance.refunds.approve', $refund), [
            'financial_account_id' => $this->financialAccount('BANK')->id,
        ])->assertRedirect();

        $refund->refresh();
        $this->assertSame('approved', $refund->status);
        $this->assertSame($accountant->id, $refund->approved_by);
        $this->assertSame($this->financialAccount('BANK')->id, $refund->financial_account_id);

        // postRefund posts a positive 'refund' folio transaction, which
        // moves the (negative, credit) balance back toward zero.
        $this->assertSame('0.00', $folio->fresh()->balance);

        $this->assertDatabaseHas('payments', [
            'source_module' => 'finance_refund',
            'direction' => 'out',
            'method' => 'bank_transfer',
            'amount' => '10000.00',
        ]);
    }

    /**
     * The route-level validation half of the same fix: approving with no
     * account picked at all is rejected before it ever reaches the
     * service, so a refund can never be approved with an unattributed
     * payment method again.
     */
    public function test_approving_a_refund_without_picking_a_financial_account_is_rejected(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolioWithBalance(-10000);

        $this->actingAs($frontDesk)->post(route('finance.refunds.store', $folio), [
            'amount' => 10000,
            'reason' => 'Guest overpaid at checkout',
            'method' => 'cash',
        ]);
        $refund = RefundRequest::first();

        $response = $this->actingAs($accountant)->post(route('finance.refunds.approve', $refund), []);

        $response->assertSessionHasErrors('financial_account_id');
        $this->assertSame('pending', $refund->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_rejecting_a_refund_leaves_the_folio_and_ledger_untouched(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $accountant = $this->userWithRole('accountant');
        $folio = $this->openFolioWithBalance(-10000);

        $this->actingAs($frontDesk)->post(route('finance.refunds.store', $folio), [
            'amount' => 10000,
            'reason' => 'Disputed',
            'method' => 'cash',
        ]);
        $refund = RefundRequest::first();

        $this->actingAs($accountant)->post(route('finance.refunds.reject', $refund), [
            'reason' => 'Guest already received cash back at the desk',
        ])->assertRedirect();

        $refund->refresh();
        $this->assertSame('rejected', $refund->status);
        $this->assertSame('-10000.00', $folio->fresh()->balance);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_front_desk_cannot_approve_their_own_refund_request(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $folio = $this->openFolioWithBalance(-10000);

        $this->actingAs($frontDesk)->post(route('finance.refunds.store', $folio), [
            'amount' => 10000,
            'reason' => 'Guest overpaid at checkout',
            'method' => 'cash',
        ]);
        $refund = RefundRequest::first();

        $this->actingAs($frontDesk)->post(route('finance.refunds.approve', $refund), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertForbidden();
    }

    public function test_a_gm_cannot_approve_their_own_refund_request_even_though_they_hold_both_permissions(): void
    {
        // Unlike front_desk (who lacks approve-refund entirely, covered
        // above by a route-level 403), GM holds both request-refund and
        // approve-refund in the role matrix. The security-audit fix in
        // RefundService::approve() is the only thing stopping a single GM
        // account from filing and then approving its own refund.
        $gm = $this->userWithRole('gm');
        $folio = $this->openFolioWithBalance(-10000);

        $this->actingAs($gm)->post(route('finance.refunds.store', $folio), [
            'amount' => 10000,
            'reason' => 'Guest overpaid at checkout',
            'method' => 'cash',
        ]);
        $refund = RefundRequest::first();

        $response = $this->actingAs($gm)->post(route('finance.refunds.approve', $refund), [
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $refund->fresh()->status);
    }

    public function test_cannot_request_a_refund_against_a_closed_folio(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = Stay::factory()->create(['status' => 'closed']);
        $folio = Folio::factory()->for($stay)->create(['status' => 'closed', 'balance' => 0]);

        $response = $this->actingAs($frontDesk)->post(route('finance.refunds.store', $folio), [
            'amount' => 5000,
            'reason' => 'Too late',
            'method' => 'cash',
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseCount('refund_requests', 0);
    }
}
