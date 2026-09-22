<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class ReservationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    /**
     * Fourth post-9.8 fix round, follow-up ("let the guest not have a
     * dropdown entry... let it have a text box with searchable
     * characteristic"): the create-reservation screen used to build a
     * `<select>` from `Guest::orderBy('first_name')->limit(200)->get()`
     * — every guest, unpaginated, capped at 200 — replaced with a
     * search-as-you-type box backed by the existing `guests.search`
     * endpoint (the same pattern the walk-in check-in screen already
     * used). This proves the page renders correctly with no guest list
     * dumped into it, and that a prefilled guest (arriving via a
     * just-created guest's "Reservation" button) is still shown by name.
     */
    public function test_reservation_create_page_has_no_guest_dropdown_and_shows_a_prefilled_guest(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create(['first_name' => 'Aline', 'last_name' => 'Mbarga']);

        $response = $this->actingAs($frontDesk)->get(route('reservations.create', ['guest_id' => $guest->id]));

        $response->assertOk();
        $response->assertViewHas('prefillGuest', fn ($prefillGuest) => $prefillGuest->id === $guest->id);
        $response->assertViewMissing('guests');
        $response->assertSee('Aline Mbarga');
        $response->assertSee(route('guests.search'));
    }

    public function test_front_desk_can_create_a_pending_reservation(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
        $guest = Guest::factory()->create();
        $category = RoomCategory::factory()->create(['base_rate' => 30000]);

        $response = $this->actingAs($frontDesk)->post(route('reservations.store'), [
            'guest_id' => $guest->id,
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'rate_quoted' => 30000,
        ]);

        $reservation = Reservation::first();
        $response->assertRedirect(route('reservations.show', $reservation));
        $this->assertSame('pending', $reservation->status);
        $this->assertSame($frontDesk->id, $reservation->requested_by);
    }

    /**
     * Fourth post-9.8 fix round ("make sure the front desk have the
     * right to approve reservation too") — front desk now holds
     * `approve-reservation` alongside `manage-reservations`, the same
     * combination Duty Manager already had with no self-approval guard
     * (none exists for reservations anywhere in this system today).
     */
    public function test_front_desk_can_approve_a_pending_reservation(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
        $room = Room::factory()->create();
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($frontDesk)->post(route('reservations.approve', $reservation), [
            'room_id' => $room->id,
        ]);

        $response->assertRedirect();
        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame($room->id, $reservation->room_id);
        $this->assertSame($frontDesk->id, $reservation->approved_by);
    }

    public function test_front_desk_can_reject_a_pending_reservation_with_a_reason(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'pin', 'password' => null, 'pin_hash' => bcrypt('1234')]);
        $reservation = Reservation::factory()->for(Guest::factory())->for(RoomCategory::factory())->create(['status' => 'pending']);

        $this->actingAs($frontDesk)->post(route('reservations.reject', $reservation), [
            'reason' => 'No availability for the requested category',
        ])->assertRedirect();

        $this->assertSame('rejected', $reservation->fresh()->status);
    }

    public function test_a_role_without_approve_reservation_is_forbidden(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $reservation = Reservation::factory()->for(Guest::factory())->for(RoomCategory::factory())->create(['status' => 'pending']);

        $response = $this->actingAs($barStaff)->post(route('reservations.approve', $reservation), []);

        $response->assertForbidden();
        $this->assertSame('pending', $reservation->fresh()->status);
    }

    /**
     * Follow-up round (money-safety fix that came with letting a
     * website deposit claim auto-post to the ledger at approval time):
     * approve() used to check `pending` status before opening its
     * transaction and never re-locked the row inside it — harmless while
     * approval only flipped a status column, but a real double-posting
     * risk now that it can also post a real payment. Fixed to match this
     * project's established lockForUpdate()-inside-transaction pattern.
     * This test proves the guard generically, with no deposit involved
     * at all, since the fix itself isn't website-specific — the
     * deposit-specific "never posts the payment twice" version of this
     * same guard lives in WebsiteBookingTest.
     */
    public function test_approving_an_already_confirmed_reservation_throws_instead_of_silently_succeeding(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->for(Guest::factory())->for(RoomCategory::factory())->create(['status' => 'confirmed']);

        $threw = false;
        try {
            app(\App\Services\FrontOffice\ReservationService::class)->approve($reservation, $dutyManager);
        } catch (\App\Exceptions\HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw);
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_duty_manager_can_approve_a_pending_reservation_and_it_becomes_confirmed(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $room = Room::factory()->create();
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'status' => 'pending',
        ]);

        $response = $this->actingAs($dutyManager)->post(route('reservations.approve', $reservation), [
            'room_id' => $room->id,
        ]);

        $response->assertRedirect();
        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertSame($room->id, $reservation->room_id);
        $this->assertSame($dutyManager->id, $reservation->approved_by);
    }

    public function test_cannot_approve_a_reservation_into_a_room_already_booked_for_overlapping_dates(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $room = Room::factory()->create();

        // Existing confirmed reservation occupying the room for these dates.
        Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->id,
            'status' => 'confirmed',
            'check_in_date' => now()->addDays(2),
            'check_out_date' => now()->addDays(5),
        ]);

        $newReservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'status' => 'pending',
            'check_in_date' => now()->addDays(3),
            'check_out_date' => now()->addDays(4),
        ]);

        $response = $this->actingAs($dutyManager)->post(route('reservations.approve', $newReservation), [
            'room_id' => $room->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame('pending', $newReservation->fresh()->status);
    }

    public function test_duty_manager_can_reject_a_pending_reservation_with_a_reason(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->for(Guest::factory())->for(RoomCategory::factory())->create(['status' => 'pending']);

        $this->actingAs($dutyManager)->post(route('reservations.reject', $reservation), [
            'reason' => 'No availability for requested dates',
        ])->assertRedirect();

        $reservation->refresh();
        $this->assertSame('rejected', $reservation->status);
        $this->assertSame('No availability for requested dates', $reservation->rejection_reason);
    }

    public function test_confirmed_reservation_can_be_marked_no_show_after_check_in_date_passes(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->for(Guest::factory())->for(RoomCategory::factory())->create([
            'status' => 'confirmed',
            'check_in_date' => now()->subDay(),
            'check_out_date' => now()->addDays(2),
        ]);

        $this->actingAs($dutyManager)->post(route('reservations.no-show', $reservation))->assertRedirect();

        $this->assertSame('no_show', $reservation->fresh()->status);
    }

    public function test_cannot_mark_no_show_before_check_in_date(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $reservation = Reservation::factory()->for(Guest::factory())->for(RoomCategory::factory())->create([
            'status' => 'confirmed',
            'check_in_date' => now()->addDays(3),
            'check_out_date' => now()->addDays(5),
        ]);

        $this->actingAs($dutyManager)->post(route('reservations.no-show', $reservation))
            ->assertSessionHasErrors('error');

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }
}
