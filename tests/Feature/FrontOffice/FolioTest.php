<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Room;
use App\Models\Stay;
use App\Services\FrontOffice\FolioService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class FolioTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Front
        // Office now refuses a folio charge/payment without an open shift.
        $this->openShiftFor('FO');
    }

    private function openFolio(): Folio
    {
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create(['status' => 'active']);

        return Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);
    }

    public function test_posting_a_charge_increases_folio_balance(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $folio = $this->openFolio();

        $this->actingAs($frontDesk)->post(route('folios.charge', $folio), [
            'type' => 'bar_charge',
            'amount' => 5000,
            'description' => 'Two beers',
        ])->assertRedirect();

        $this->assertSame('5000.00', $folio->fresh()->balance);
        $this->assertSame('5000.00', $folio->fresh()->recomputeBalance());
    }

    public function test_posting_a_payment_decreases_folio_balance(): void
    {
        $cashier = $this->userWithRole('cashier', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
        $folio = $this->openFolio();
        $folio->update(['balance' => 10000]);
        app(FolioService::class)->postCharge($folio, 'room_charge', 10000, 'Room charge', $cashier);

        $this->actingAs($cashier)->post(route('folios.payment', $folio), [
            'amount' => 6000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $this->assertSame('14000.00', $folio->fresh()->balance);
    }

    public function test_voiding_a_transaction_posts_a_reversing_entry_and_never_deletes_the_original(): void
    {
        $accountant = $this->userWithRole('accountant', ['auth_type' => 'password_mfa']);
        $folio = $this->openFolio();
        $service = app(FolioService::class);
        $charge = $service->postCharge($folio, 'other_service', 8000, 'Laundry', $accountant);

        $this->actingAs($accountant)->post(route('folios.void', $charge), [
            'reason' => 'Charged in error',
        ])->assertRedirect();

        $charge->refresh();
        $this->assertNotNull($charge->voided_at);
        $this->assertSame('Charged in error', $charge->void_reason);

        // Original row still exists, untouched in amount/type — history preserved.
        $this->assertDatabaseHas('folio_transactions', [
            'id' => $charge->id,
            'amount' => '8000.00',
            'type' => 'other_service',
        ]);

        // A reversing adjustment was posted referencing the original.
        $reversal = FolioTransaction::where('reverses_transaction_id', $charge->id)->first();
        $this->assertNotNull($reversal);
        $this->assertSame('-8000.00', $reversal->amount);
        $this->assertSame('adjustment', $reversal->type);

        // Net effect on the folio balance is zero.
        $this->assertSame('0.00', $folio->fresh()->balance);
    }

    public function test_cannot_post_a_charge_to_a_closed_folio(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $folio = $this->openFolio();
        $folio->update(['status' => 'closed']);

        $this->actingAs($frontDesk)->post(route('folios.charge', $folio), [
            'type' => 'bar_charge',
            'amount' => 1000,
            'description' => 'Should fail',
        ])->assertSessionHasErrors('error');

        $this->assertDatabaseCount('folio_transactions', 0);
    }
}
