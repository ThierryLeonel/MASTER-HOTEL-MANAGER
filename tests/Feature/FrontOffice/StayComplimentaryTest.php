<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Folio;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * "Special guest lodging" — added at the user's explicit request,
 * clarified to mean: a stay's ROOM charge can be waived while every
 * other charge (bar/restaurant/front_desk/other_service) still posts
 * and gets paid normally. Full design decision in
 * PHASE9_AUDIT_AND_PLAN.md §9.
 */
class StayComplimentaryTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Front
        // Office now refuses a folio charge or a check-in without an open
        // shift; markComplimentary/checkout themselves stay ungated (see
        // ShiftGateService's docblock), so this is harmless for the tests
        // here that don't touch a charge/check-in route.
        $this->openShiftFor('FO');
    }

    private function activeStayWithFolio(): Stay
    {
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create(['status' => 'active', 'agreed_rate' => 30000]);
        Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 0]);

        return $stay;
    }

    public function test_duty_manager_can_mark_an_active_stay_complimentary(): void
    {
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();

        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), [
            'reason' => 'Familiarization stay for a travel agent partner',
        ])->assertRedirect();

        $stay->refresh();
        $this->assertTrue($stay->is_complimentary);
        $this->assertSame('Familiarization stay for a travel agent partner', $stay->complimentary_reason);
        $this->assertSame($manager->id, $stay->marked_complimentary_by);
        $this->assertNotNull($stay->marked_complimentary_at);
        $this->assertDatabaseHas('audit_logs', ['action' => 'stay.marked_complimentary', 'user_id' => $manager->id]);
    }

    public function test_front_desk_cannot_mark_a_stay_complimentary(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();

        $this->actingAs($frontDesk)->post(route('stays.mark-complimentary', $stay), [
            'reason' => 'Trying anyway',
        ])->assertForbidden();

        $this->assertFalse($stay->fresh()->is_complimentary);
    }

    public function test_marking_complimentary_without_a_reason_is_rejected(): void
    {
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();

        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), [])
            ->assertSessionHasErrors('reason');

        $this->assertFalse($stay->fresh()->is_complimentary);
    }

    public function test_a_closed_stay_cannot_be_marked_complimentary(): void
    {
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();
        $stay->update(['status' => 'closed']);

        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), [
            'reason' => 'Too late',
        ])->assertSessionHasErrors('error');

        $this->assertFalse($stay->fresh()->is_complimentary);
    }

    public function test_a_manual_room_charge_is_refused_against_a_complimentary_stays_folio(): void
    {
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();
        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), ['reason' => 'VIP']);

        $this->actingAs($manager)->post(route('folios.charge', $stay->folio), [
            'type' => 'room_charge',
            'amount' => 30000,
            'description' => 'Night 2',
        ])->assertSessionHasErrors('error');

        $this->assertSame('0.00', $stay->folio->fresh()->balance);
    }

    public function test_other_charge_types_still_post_normally_against_a_complimentary_stays_folio(): void
    {
        // The core of the user's clarification: "the complimentary is
        // free but the [other charges are] not" — only room_charge is
        // blocked; everything else is completely unaffected.
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();
        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), ['reason' => 'VIP']);

        $this->actingAs($manager)->post(route('folios.charge', $stay->folio), [
            'type' => 'bar_charge',
            'amount' => 5000,
            'description' => 'Drinks',
        ])->assertRedirect();

        $this->assertSame('5000.00', $stay->folio->fresh()->balance);
    }

    public function test_checkout_never_charges_a_complimentary_stay_even_for_a_genuine_extension(): void
    {
        // Global hospitality-standard change: checkout charging is now
        // automatic (no more manual "post final night" checkbox) — a
        // complimentary stay must still never be charged for its room,
        // however many nights checkout's automatic calculation would
        // otherwise attribute to it.
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();
        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), ['reason' => 'VIP']);

        // checkout-guest is a different permission; grant it too so this
        // test isolates the complimentary behavior, not authorization.
        $manager->givePermissionTo('checkout-guest');

        $this->actingAs($manager)->post(route('stays.checkout', $stay))
            ->assertRedirect();

        $stay->refresh();
        $this->assertSame('closed', $stay->status);
        $this->assertSame('0.00', $stay->folio->fresh()->balance);
        $this->assertDatabaseMissing('folio_transactions', ['folio_id' => $stay->folio->id, 'type' => 'room_charge']);
    }

    public function test_checkout_automatically_charges_a_normal_stay_that_was_never_charged_at_check_in(): void
    {
        // Regression guard: the complimentary skip must not affect a
        // stay that was never marked complimentary. This stay was
        // created directly via the factory (bypassing CheckInService),
        // so nights_charged is still 0 — checkout's automatic
        // extension calculation treats the whole stay as unbilled and
        // charges it in full, just as CheckInService would have at
        // check-in.
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();
        $manager->givePermissionTo('checkout-guest');

        $this->actingAs($manager)->post(route('stays.checkout', $stay))
            ->assertRedirect();

        $this->assertSame('30000.00', $stay->folio->fresh()->balance);
    }

    /**
     * Global hospitality-standard change: CheckInService now posts the
     * full stay cost upfront at check-in, so by the time a stay is
     * marked complimentary it may already carry a real room_charge on
     * its folio. Marking it complimentary must actually waive that
     * charge (reversed via FolioService::void(), never edited/deleted —
     * spec section 12), not just prevent future ones.
     */
    public function test_marking_complimentary_after_check_in_reverses_the_already_posted_room_charge(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 30000,
        ]);

        $stay = Stay::first();
        $this->assertSame('60000.00', $stay->folio->fresh()->balance);
        $this->assertSame(2, $stay->nights_charged);

        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), ['reason' => 'VIP'])
            ->assertRedirect();

        $stay->refresh();
        $this->assertSame('0.00', $stay->folio->fresh()->balance);
        $this->assertSame(0, $stay->nights_charged);
        $this->assertDatabaseHas('folio_transactions', ['folio_id' => $stay->folio->id, 'type' => 'room_charge', 'amount' => '60000.00']);
        $this->assertDatabaseHas('folio_transactions', ['folio_id' => $stay->folio->id, 'type' => 'adjustment', 'amount' => '-60000.00']);
    }

    /**
     * Correction round (audited defect #22): unmarkComplimentary() used to
     * only flip the flag back to false and leave nights_charged at 0 —
     * the folio balance silently showed the room as fully paid/free until
     * checkout's own extra-nights math eventually caught up. It now
     * immediately re-posts a real room_charge for the nights owed so far,
     * so this test's expected balance after unmarking changed from 0 to
     * one night's rate — reflecting that correctness fix, not a
     * regression.
     */
    public function test_removing_complimentary_status_allows_room_charges_again(): void
    {
        $manager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithFolio();
        $this->actingAs($manager)->post(route('stays.mark-complimentary', $stay), ['reason' => 'VIP']);

        $this->actingAs($manager)->post(route('stays.unmark-complimentary', $stay))->assertRedirect();
        $this->assertFalse($stay->fresh()->is_complimentary);
        $this->assertNull($stay->fresh()->complimentary_reason);

        // The stay-so-far (1 night, per activeStayWithFolio()'s check-in
        // date) is immediately re-billed the moment complimentary status
        // is removed — no longer silently deferred to checkout.
        $this->assertSame('30000.00', $stay->folio->fresh()->balance);
        $this->assertSame(1, $stay->fresh()->nights_charged);

        $this->actingAs($manager)->post(route('folios.charge', $stay->folio), [
            'type' => 'room_charge',
            'amount' => 30000,
            'description' => 'Night 2',
        ])->assertRedirect();

        $this->assertSame('60000.00', $stay->folio->fresh()->balance);
    }
}
