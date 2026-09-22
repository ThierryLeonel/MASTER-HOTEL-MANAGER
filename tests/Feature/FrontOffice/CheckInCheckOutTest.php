<?php

namespace Tests\Feature\FrontOffice;

use App\Models\AccountsReceivable;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class CheckInCheckOutTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): check-in
        // now refuses to run without an open Front Office shift (it posts
        // the initial lodging charge); checkout stays deliberately
        // ungated (spec section 10's "never blocks checkout" invariant),
        // so this is harmless for the checkout tests below.
        $this->openShiftFor('FO');
    }

    public function test_checking_in_a_confirmed_reservation_creates_stay_and_open_folio_and_occupies_room(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'available']);
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->id,
            'status' => 'confirmed',
            'rate_quoted' => 30000,
        ]);

        $response = $this->actingAs($frontDesk)->post(route('reservations.checkin', $reservation));

        $stay = Stay::first();
        $response->assertRedirect(route('stays.show', $stay));

        $this->assertSame('active', $stay->status);
        $this->assertSame($room->id, $stay->room_id);
        $this->assertNotNull($stay->folio);
        $this->assertSame('open', $stay->folio->status);
        $this->assertSame('occupied', $room->fresh()->status);
        $this->assertSame('checked_in', $reservation->fresh()->status);

        // Global hospitality-standard change ("let the total cost be in
        // the folio"): the full anticipated stay cost — rate x nights —
        // is now a real posted charge, not just informational text.
        $nights = $stay->expectedNights();
        $this->assertGreaterThanOrEqual(1, $nights);
        $this->assertSame($nights, $stay->nights_charged);
        $this->assertSame(bcmul('30000', (string) $nights, 2), $stay->folio->fresh()->balance);
    }

    /**
     * "the client can also make advance payment for the lodging so that
     * should be included in the folio so as to get the clients
     * outstanding balance... the advance on reservation are also
     * payments" — this is the end-to-end proof: a deposit recorded
     * through ReservationService::recordDeposit() lands in the central
     * payments ledger exactly once, and is applied as a folio credit at
     * check-in without being ledgered again.
     */
    public function test_check_in_applies_a_previously_recorded_deposit_as_a_folio_credit_without_double_ledgering(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'available']);
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->id,
            'check_in_date' => now()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'status' => 'confirmed',
            'rate_quoted' => 20000,
        ]);

        $this->actingAs($frontDesk)->post(route('reservations.deposit', $reservation), [
            'amount' => 15000,
            'financial_account_id' => $this->financialAccount('MTN')->id,
        ])->assertRedirect();

        $this->assertSame('15000.00', $reservation->fresh()->deposit_paid);
        $this->assertDatabaseCount('payments', 1);

        $this->actingAs($frontDesk)->post(route('reservations.checkin', $reservation->fresh()));

        $stay = Stay::first();
        $nights = $stay->expectedNights();
        $expectedBalance = bcsub(bcmul('20000', (string) $nights, 2), '15000.00', 2);

        $this->assertSame($expectedBalance, $stay->folio->fresh()->balance);
        // Still exactly one payment in the central ledger — applying the
        // deposit as a folio credit at check-in must not record it again.
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame('reservation_deposit', \App\Models\Payment::first()->source_module);
    }

    public function test_cannot_check_in_to_a_room_that_is_already_occupied(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'occupied']);
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->id,
            'status' => 'confirmed',
        ]);

        $response = $this->actingAs($frontDesk)->post(route('reservations.checkin', $reservation));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseCount('stays', 0);
    }

    public function test_walk_in_guest_can_be_checked_in_directly_without_a_reservation(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 35000,
        ]);

        $stay = Stay::first();
        $response->assertRedirect(route('stays.show', $stay));
        $this->assertNull($stay->reservation_id);
        $this->assertSame('occupied', $room->fresh()->status);

        // Global hospitality-standard change: the walk-in's full 2-night
        // stay cost is posted to the folio immediately, same as a
        // reservation-based check-in.
        $this->assertSame(2, $stay->nights_charged);
        $this->assertSame('70000.00', $stay->folio->fresh()->balance);
    }

    /**
     * Post-9.8 fix round ("even if a guest is not existing the
     * informations should just populate the guest list, rather than
     * going to create a new guest before check in"): no guest_id at all
     * — the guest is created inline from the new_* fields as part of the
     * same check-in submission.
     */
    public function test_walk_in_check_in_creates_a_new_guest_inline_when_none_was_selected(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'new_first_name' => 'Amara',
            'new_last_name' => 'Okafor',
            'new_phone' => '699000111',
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 35000,
        ]);

        $guest = Guest::first();
        $this->assertNotNull($guest);
        $this->assertSame('Amara', $guest->first_name);
        $this->assertSame('Okafor', $guest->last_name);

        $stay = Stay::first();
        $response->assertRedirect(route('stays.show', $stay));
        $this->assertSame($guest->id, $stay->guest_id);
        $this->assertSame('occupied', $room->fresh()->status);
    }

    /**
     * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §22): a walk-in
     * can optionally be billed to a company at check-in — this is the
     * origin point for the company debt rollup tested end-to-end in
     * test_checkout_with_unpaid_balance_for_a_company_billed_stay_tags_the_receivable_with_that_company()
     * above.
     */
    public function test_walk_in_check_in_can_be_billed_to_a_company(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);
        $company = \App\Models\Company::create(['name' => 'Yaoundé Freight SA', 'is_active' => true]);

        $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 35000,
            'company_id' => $company->id,
        ])->assertRedirect();

        $stay = Stay::first();
        $this->assertSame($company->id, $stay->company_id);
    }

    public function test_walk_in_check_in_requires_either_an_existing_guest_or_a_name_for_a_new_one(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 35000,
        ]);

        $response->assertSessionHasErrors('new_first_name');
        $this->assertDatabaseCount('stays', 0);
        $this->assertDatabaseCount('guests', 0);
    }

    public function test_checkout_with_unpaid_balance_creates_accounts_receivable_and_still_succeeds(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create([
            'status' => 'active',
            'agreed_rate' => 30000,
            // Simulates a stay whose 1-night charge was already posted
            // (e.g. by CheckInService at check-in) — set explicitly here
            // since this stay is created directly via the factory rather
            // than through check-in, so checkout's automatic extension
            // calculation doesn't also charge this same night again.
            'nights_charged' => 1,
        ]);
        $folio = \App\Models\Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 30000]);

        $response = $this->actingAs($frontDesk)->post(route('stays.checkout', $stay));

        $response->assertRedirect();
        $stay->refresh();
        $this->assertSame('closed', $stay->status);
        $this->assertSame('closed', $folio->fresh()->status);
        $this->assertSame('available', $room->fresh()->status);

        $ar = AccountsReceivable::where('folio_id', $folio->id)->first();
        $this->assertNotNull($ar, 'Unpaid balance at checkout must create an Accounts Receivable record.');
        $this->assertSame('30000.00', $ar->amount_outstanding);
        $this->assertSame('open', $ar->status);
    }

    /**
     * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §22): "track
     * debt for a company/corporate account, not just one guest." A stay
     * checked in "billed to" a company carries that company through to
     * checkout, so an unpaid balance lands on the COMPANY's ledger, not
     * just the individual guest's — see Company::totalOutstanding().
     */
    public function test_checkout_with_unpaid_balance_for_a_company_billed_stay_tags_the_receivable_with_that_company(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $company = \App\Models\Company::create(['name' => 'Douala Trading Co.', 'is_active' => true]);
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create([
            'status' => 'active',
            'agreed_rate' => 30000,
            'nights_charged' => 1,
            'company_id' => $company->id,
        ]);
        $folio = \App\Models\Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 30000]);

        $this->actingAs($frontDesk)->post(route('stays.checkout', $stay))->assertRedirect();

        $ar = AccountsReceivable::where('folio_id', $folio->id)->first();
        $this->assertNotNull($ar);
        $this->assertSame($company->id, $ar->company_id);
        $this->assertSame(30000.0, $company->totalOutstanding());
    }

    public function test_checkout_with_fully_settled_folio_creates_no_accounts_receivable(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create([
            'status' => 'active',
            'agreed_rate' => 30000,
            // Already fully charged and settled — see comment on the
            // previous test for why this is set explicitly here.
            'nights_charged' => 1,
        ]);
        \App\Models\Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);

        $this->actingAs($frontDesk)->post(route('stays.checkout', $stay))->assertRedirect();

        $this->assertDatabaseCount('accounts_receivable', 0);
    }

    /**
     * Global hospitality-standard change: checkout no longer takes a
     * manual "post final night" flag — it automatically charges only a
     * genuine EXTENSION, the delta between nights actually stayed and
     * nights already charged at check-in.
     */
    public function test_checkout_automatically_charges_for_a_genuine_extension_beyond_the_original_stay(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create([
            'status' => 'active',
            'agreed_rate' => 30000,
            // Checked in 3 days ago for what was quoted as a 2-night
            // stay (already charged in full at check-in)...
            'check_in_at' => now()->subDays(3),
            'expected_check_out_date' => now()->subDay()->toDateString(),
            'nights_charged' => 2,
        ]);
        \App\Models\Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 60000]);

        // ...but the guest is actually checking out today — one genuine
        // extra night beyond what was already charged.
        $this->actingAs($frontDesk)->post(route('stays.checkout', $stay))->assertRedirect();

        $stay->refresh();
        $this->assertSame(3, $stay->nights_charged);
        $this->assertSame('90000.00', $stay->folio->fresh()->balance);
    }

    /**
     * Global hospitality-standard change: an early departure is never
     * auto-charged for extra nights (nothing to charge) nor auto-credited
     * for the unused nights — standard non-refundable nightly-rate
     * policy. A refund for an early departure is a separate, manual
     * Finance decision, not something checkout does silently.
     */
    public function test_checkout_does_not_auto_credit_an_early_departure(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create([
            'status' => 'active',
            'agreed_rate' => 30000,
            // Checked in yesterday, already charged for a full 4-night
            // stay at check-in...
            'check_in_at' => now()->subDay(),
            'expected_check_out_date' => now()->addDays(3)->toDateString(),
            'nights_charged' => 4,
        ]);
        \App\Models\Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 120000]);

        // ...but the guest is actually leaving after one night.
        $this->actingAs($frontDesk)->post(route('stays.checkout', $stay))->assertRedirect();

        $stay->refresh();
        $this->assertSame(4, $stay->nights_charged);
        $this->assertSame('120000.00', $stay->folio->fresh()->balance);
        $this->assertDatabaseCount('accounts_receivable', 0);
    }
}
