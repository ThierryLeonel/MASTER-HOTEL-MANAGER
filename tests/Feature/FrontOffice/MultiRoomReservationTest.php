<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request ("i want to be able to book multi reservations and
 * multi bookings instead of entering 1 by 1"): one guest/party booking
 * several rooms for the same stay dates in a single submission — see
 * ReservationService::createGroup()'s docblock for the full reasoning.
 * Every existing single-reservation test in ReservationTest.php is
 * untouched by this feature; these tests cover only the new group path.
 */
class MultiRoomReservationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_front_desk_can_book_several_rooms_for_one_guest_in_one_submission(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create();
        $standard = RoomCategory::factory()->create(['name' => 'Standard', 'base_rate' => 20000]);
        $deluxe = RoomCategory::factory()->create(['name' => 'Deluxe', 'base_rate' => 35000]);

        $response = $this->actingAs($frontDesk)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'notes' => 'Family of five, three rooms',
            'rooms' => [
                ['room_category_id' => $standard->id, 'rate_quoted' => 20000, 'adults' => 2],
                ['room_category_id' => $standard->id, 'rate_quoted' => 20000, 'adults' => 2],
                ['room_category_id' => $deluxe->id, 'rate_quoted' => 35000, 'adults' => 1],
            ],
        ]);

        $this->assertSame(3, Reservation::count());
        $reservations = Reservation::orderBy('id')->get();

        // All three share one group_reference, and it's a real,
        // non-empty value distinct from any individual reservation_number.
        $groupReference = $reservations->first()->group_reference;
        $this->assertNotNull($groupReference);
        $this->assertTrue($reservations->every(fn ($r) => $r->group_reference === $groupReference));
        $this->assertSame(3, $reservations->pluck('reservation_number')->unique()->count(), 'Each room still gets its own unique reservation number.');

        // Every room is independently pending, same as a single booking.
        $this->assertTrue($reservations->every(fn ($r) => $r->status === 'pending'));
        $this->assertTrue($reservations->every(fn ($r) => $r->guest_id === $guest->id));
        $this->assertTrue($reservations->every(fn ($r) => $r->requested_by === $frontDesk->id));
        $this->assertTrue($reservations->every(fn ($r) => $r->notes === 'Family of five, three rooms'));

        $response->assertRedirect(route('reservations.show', $reservations->first()));
    }

    /**
     * The dates and guest are shared, but each room keeps its own
     * category, rate, and occupancy — a family taking two different room
     * types must not have one room's details bleed into another's.
     */
    public function test_each_room_in_a_group_keeps_its_own_category_rate_and_occupancy(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create();
        $standard = RoomCategory::factory()->create(['base_rate' => 20000]);
        $deluxe = RoomCategory::factory()->create(['base_rate' => 35000]);

        $this->actingAs($frontDesk)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_category_id' => $standard->id, 'rate_quoted' => 18000, 'adults' => 2, 'children' => 1],
                ['room_category_id' => $deluxe->id, 'rate_quoted' => 40000, 'adults' => 1, 'children' => 0],
            ],
        ]);

        $first = Reservation::where('room_category_id', $standard->id)->first();
        $second = Reservation::where('room_category_id', $deluxe->id)->first();

        $this->assertSame('18000.00', $first->rate_quoted);
        $this->assertSame(2, $first->adults);
        $this->assertSame(1, $first->children);
        $this->assertSame('40000.00', $second->rate_quoted);
        $this->assertSame(1, $second->adults);
        $this->assertSame(0, $second->children);
    }

    /**
     * All-or-nothing: if one room's data is invalid, none of the rooms in
     * that submission are created — never a partial party half-booked.
     */
    public function test_an_invalid_room_in_the_batch_prevents_the_whole_group_from_being_created(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create();
        $standard = RoomCategory::factory()->create(['base_rate' => 20000]);

        $response = $this->actingAs($frontDesk)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_category_id' => $standard->id, 'rate_quoted' => 20000],
                ['room_category_id' => 999999, 'rate_quoted' => 20000],
            ],
        ]);

        $response->assertSessionHasErrors('rooms.1.room_category_id');
        $this->assertSame(0, Reservation::count(), 'Nothing should be created when any room in the batch fails validation.');
    }

    public function test_at_least_one_room_is_required(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create();

        $response = $this->actingAs($frontDesk)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [],
        ]);

        $response->assertSessionHasErrors('rooms');
        $this->assertSame(0, Reservation::count());
    }

    /**
     * A single-room booking made through the regular, unchanged
     * reservations.store route must keep behaving exactly as it always
     * has — group_reference is simply null, never set for it.
     */
    public function test_an_ordinary_single_reservation_has_no_group_reference(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create();
        $category = RoomCategory::factory()->create(['base_rate' => 30000]);

        $this->actingAs($frontDesk)->post(route('reservations.store'), [
            'guest_id' => $guest->id,
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'rate_quoted' => 30000,
        ]);

        $reservation = Reservation::first();
        $this->assertNull($reservation->group_reference);
        $this->assertFalse($reservation->isGrouped());
    }

    /**
     * Viewing any one room in a group shows a link to every other room
     * booked in the same submission, so staff can navigate the whole
     * party from any one of its rooms — direct request ("book multi
     * reservations... instead of entering 1 by 1").
     */
    public function test_viewing_one_room_in_a_group_lists_its_siblings(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create();
        $category = RoomCategory::factory()->create(['base_rate' => 20000]);

        $this->actingAs($frontDesk)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_category_id' => $category->id, 'rate_quoted' => 20000],
                ['room_category_id' => $category->id, 'rate_quoted' => 20000],
            ],
        ]);

        $reservations = Reservation::orderBy('id')->get();
        $response = $this->actingAs($frontDesk)->get(route('reservations.show', $reservations->first()));

        $response->assertOk();
        $response->assertSee('Part of a multi-room booking');
        $response->assertSee($reservations->last()->reservation_number);
    }

    public function test_a_role_without_manage_reservations_cannot_use_the_multi_room_form(): void
    {
        $guest = Guest::factory()->create();
        $barStaff = $this->pinUser('bar_staff');

        $this->actingAs($barStaff)->get(route('reservations.create-multi'))->assertForbidden();
        $this->actingAs($barStaff)->post(route('reservations.store-multi'), [
            'guest_id' => $guest->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [['room_category_id' => 1, 'rate_quoted' => 1000]],
        ])->assertForbidden();
    }
}
