<?php

namespace Tests\Feature\FrontOffice;

use App\Models\DiscountApproval;
use App\Models\Guest;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request ("i do not see the multibooking in the check in or
 * reservation"): multi-room booking already existed for advance
 * Reservations (ReservationService::createGroup(),
 * tests/Feature/FrontOffice/ReservationTest.php's own multi-room tests)
 * but was never built for Walk-in Check-in — confirmed by reading
 * CheckInController/CheckInService/walk-in.blade.php in full before this
 * round: the walk-in form only ever supported exactly one room. This
 * covers the new walk-in counterpart: CheckInService::checkInWalkInGroup(),
 * CheckInController::walkInMultiForm()/walkInMultiStore(), and
 * Stay::isGrouped()/groupSiblings().
 */
class WalkInGroupCheckInTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->openShiftFor('FO');
    }

    public function test_multi_room_walk_in_checks_in_every_room_together_sharing_one_group_reference(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $roomA = Room::factory()->create(['status' => 'available']);
        $roomB = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.multi.store'), [
            'guest_id' => $guest->id,
            'expected_check_out_date' => now()->addDays(3)->toDateString(),
            'rooms' => [
                ['room_id' => $roomA->id, 'agreed_rate' => 10000],
                ['room_id' => $roomB->id, 'agreed_rate' => 10000],
            ],
        ]);

        $this->assertDatabaseCount('stays', 2);
        $stays = Stay::all();

        $groupReferences = $stays->pluck('group_reference')->unique();
        $this->assertCount(1, $groupReferences);
        $this->assertNotNull($groupReferences->first());
        $this->assertStringStartsWith('WGRP-', $groupReferences->first());

        foreach ($stays as $stay) {
            $this->assertSame('active', $stay->status);
            $this->assertSame($guest->id, $stay->guest_id);
            $this->assertNotNull($stay->folio);
            $this->assertTrue($stay->isGrouped());
        }

        $this->assertSame('occupied', $roomA->fresh()->status);
        $this->assertSame('occupied', $roomB->fresh()->status);

        $response->assertRedirect(route('stays.show', $stays->first()));
        $response->assertSessionHas('status', fn ($status) => str_contains($status, '2 rooms checked in together'));
    }

    public function test_multi_room_walk_in_creates_a_new_guest_inline_and_shares_it_across_every_room(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $roomA = Room::factory()->create(['status' => 'available']);
        $roomB = Room::factory()->create(['status' => 'available']);

        $this->actingAs($frontDesk)->post(route('checkin.walk-in.multi.store'), [
            'new_first_name' => 'Amara',
            'new_last_name' => 'Nkeng',
            'new_phone' => '677000111',
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_id' => $roomA->id, 'agreed_rate' => 10000],
                ['room_id' => $roomB->id, 'agreed_rate' => 10000],
            ],
        ]);

        $guest = Guest::where('phone', '677000111')->firstOrFail();
        $this->assertSame(2, Stay::where('guest_id', $guest->id)->count());
    }

    public function test_multi_room_walk_in_is_all_or_nothing_when_one_room_is_already_occupied(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $availableRoom = Room::factory()->create(['status' => 'available']);
        $occupiedRoom = Room::factory()->create(['status' => 'occupied']);
        // hasActiveStay() (checked inside lockAndValidateRoom(), not by
        // request validation) is what actually blocks this — a real
        // active Stay, not just the room's own mutable status column.
        Stay::factory()->create(['room_id' => $occupiedRoom->id, 'status' => 'active']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.multi.store'), [
            'guest_id' => $guest->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_id' => $availableRoom->id, 'agreed_rate' => 10000],
                ['room_id' => $occupiedRoom->id, 'agreed_rate' => 10000],
            ],
        ]);

        $response->assertSessionHasErrors('error');
        // Nothing from this submission was created — the transaction
        // rolled back the entire group, not just the failing room.
        $this->assertDatabaseCount('stays', 1); // only the pre-existing occupied-room stay from setup
        $this->assertSame('available', $availableRoom->fresh()->status);
    }

    public function test_multi_room_walk_in_rejects_the_same_room_selected_twice_in_one_submission(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.multi.store'), [
            'guest_id' => $guest->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_id' => $room->id, 'agreed_rate' => 10000],
                ['room_id' => $room->id, 'agreed_rate' => 9000],
            ],
        ]);

        $response->assertSessionHasErrors();
        $this->assertDatabaseCount('stays', 0);
    }

    public function test_multi_room_walk_in_routes_a_discount_to_its_own_approver_for_only_the_discounted_room(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $standardRoom = Room::factory()->create(['status' => 'available']); // base_rate 10000
        $discountedRoom = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.multi.store'), [
            'guest_id' => $guest->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_id' => $standardRoom->id, 'agreed_rate' => 10000],
                [
                    'room_id' => $discountedRoom->id,
                    'agreed_rate' => 6000,
                    'discount_approver_id' => $dutyManager->id,
                    'discount_reason' => 'Family rate, negotiated at the desk.',
                ],
            ],
        ]);

        $response->assertSessionHas('status', fn ($status) => str_contains($status, '1 of them have a discounted rate pending approval'));

        $this->assertDatabaseCount('discount_approvals', 1);
        $approval = DiscountApproval::first();
        $this->assertSame('pending', $approval->status);
        $this->assertSame($dutyManager->id, $approval->requested_approver_id);

        $discountedStay = Stay::where('room_id', $discountedRoom->id)->firstOrFail();
        $this->assertSame($discountedStay->id, $approval->approvable_id);
        $this->assertSame(Stay::class, $approval->approvable_type);

        // The full-rate room's stay carries no approval at all.
        $standardStay = Stay::where('room_id', $standardRoom->id)->firstOrFail();
        $this->assertSame(0, $standardStay->discountApprovals()->count());
    }

    public function test_multi_room_walk_in_blocks_a_discounted_room_with_no_approver_when_the_actor_is_not_eligible(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $roomA = Room::factory()->create(['status' => 'available']);
        $roomB = Room::factory()->create(['status' => 'available']);

        $response = $this->actingAs($frontDesk)->post(route('checkin.walk-in.multi.store'), [
            'guest_id' => $guest->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_id' => $roomA->id, 'agreed_rate' => 10000],
                ['room_id' => $roomB->id, 'agreed_rate' => 5000], // below standard, no approver named
            ],
        ]);

        $response->assertSessionHasErrors('rooms.1.discount_approver_id');
        $this->assertDatabaseCount('stays', 0);
    }

    public function test_stays_show_page_displays_every_sibling_room_for_a_multi_room_walk_in(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $roomA = Room::factory()->create(['status' => 'available', 'room_number' => '101']);
        $roomB = Room::factory()->create(['status' => 'available', 'room_number' => '102']);

        $this->actingAs($frontDesk)->post(route('checkin.walk-in.multi.store'), [
            'guest_id' => $guest->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'rooms' => [
                ['room_id' => $roomA->id, 'agreed_rate' => 10000],
                ['room_id' => $roomB->id, 'agreed_rate' => 10000],
            ],
        ]);

        $stayA = Stay::where('room_id', $roomA->id)->firstOrFail();

        $this->actingAs($frontDesk)->get(route('stays.show', $stayA))
            ->assertOk()
            ->assertSee('Part of a multi-room check-in')
            ->assertSee('102');
    }

    /**
     * Regression: an ordinary single-room walk-in (the pre-existing path,
     * completely unchanged by this feature) must keep writing
     * group_reference = null and never show any grouped-stay UI.
     */
    public function test_an_ordinary_single_room_walk_in_is_never_grouped(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $room = Room::factory()->create(['status' => 'available']);

        $this->actingAs($frontDesk)->post(route('checkin.walk-in.store'), [
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'expected_check_out_date' => now()->addDays(2)->toDateString(),
            'agreed_rate' => 10000,
        ]);

        $stay = Stay::firstOrFail();
        $this->assertNull($stay->group_reference);
        $this->assertFalse($stay->isGrouped());

        $this->actingAs($frontDesk)->get(route('stays.show', $stay))
            ->assertOk()
            ->assertDontSee('Part of a multi-room check-in');
    }
}
