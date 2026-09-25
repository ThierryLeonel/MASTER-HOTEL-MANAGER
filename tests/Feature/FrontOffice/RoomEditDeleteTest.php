<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request ("I want the owner to have the access to delete and
 * update room details, that is room numbers, descriptions etc"): new
 * Owner-only 'edit-room-details' permission gating RoomController::update()
 * and ::destroy(). Deleting is only allowed for a room with no history;
 * anything else is deactivated instead.
 */
class RoomEditDeleteTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function owner()
    {
        return $this->userWithRole('owner', ['auth_type' => 'password_only']);
    }

    public function test_owner_can_update_every_room_detail(): void
    {
        $room = Room::factory()->create(['room_number' => '101', 'floor' => '1', 'notes' => null]);
        $newCategory = RoomCategory::factory()->create();

        $this->actingAs($this->owner())->put(route('rooms.update', $room), [
            'room_number' => '101A',
            'room_category_id' => $newCategory->id,
            'floor' => '2',
            'notes' => 'Sea view, king bed, balcony',
            'is_active' => '1',
        ])->assertRedirect()->assertSessionHasNoErrors();

        $room->refresh();
        $this->assertSame('101A', $room->room_number);
        $this->assertSame($newCategory->id, (int) $room->room_category_id);
        $this->assertSame('2', $room->floor);
        $this->assertSame('Sea view, king bed, balcony', $room->notes);
        $this->assertTrue($room->is_active);
        $this->assertDatabaseHas('audit_logs', ['action' => 'room.updated']);
    }

    public function test_owner_can_deactivate_an_unused_room_by_leaving_the_active_box_unticked(): void
    {
        $room = Room::factory()->create();

        $this->actingAs($this->owner())->put(route('rooms.update', $room), [
            'room_number' => $room->room_number,
            'room_category_id' => $room->room_category_id,
        ])->assertRedirect();

        $this->assertFalse($room->fresh()->is_active);
    }

    public function test_a_room_number_already_used_by_another_room_is_rejected(): void
    {
        Room::factory()->create(['room_number' => '202']);
        $room = Room::factory()->create(['room_number' => '203']);

        $this->actingAs($this->owner())->put(route('rooms.update', $room), [
            'room_number' => '202',
            'room_category_id' => $room->room_category_id,
            'is_active' => '1',
        ])->assertSessionHasErrors('room_number');

        $this->assertSame('203', $room->fresh()->room_number);
    }

    public function test_owner_can_delete_a_room_that_was_never_used(): void
    {
        $room = Room::factory()->create(['room_number' => '999']);

        $this->actingAs($this->owner())->delete(route('rooms.destroy', $room))->assertRedirect();

        $this->assertDatabaseMissing('rooms', ['id' => $room->id]);
        $this->assertDatabaseHas('audit_logs', ['action' => 'room.deleted']);
    }

    public function test_a_room_with_stay_history_cannot_be_deleted_only_deactivated(): void
    {
        $room = Room::factory()->create();
        Stay::factory()->create(['room_id' => $room->id, 'status' => 'closed']);

        $this->actingAs($this->owner())->delete(route('rooms.destroy', $room))->assertSessionHasErrors('error');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_a_room_with_any_reservation_history_cannot_be_deleted(): void
    {
        $room = Room::factory()->create();
        Reservation::factory()->create(['room_id' => $room->id, 'room_category_id' => $room->room_category_id, 'status' => 'cancelled']);

        $this->actingAs($this->owner())->delete(route('rooms.destroy', $room))->assertSessionHasErrors('error');

        $this->assertDatabaseHas('rooms', ['id' => $room->id]);
    }

    public function test_category_cannot_change_while_a_guest_is_checked_in(): void
    {
        $room = Room::factory()->create(['status' => 'occupied']);
        Stay::factory()->create(['room_id' => $room->id, 'status' => 'active']);
        $other = RoomCategory::factory()->create();

        $this->actingAs($this->owner())->put(route('rooms.update', $room), [
            'room_number' => $room->room_number,
            'room_category_id' => $other->id,
            'is_active' => '1',
        ])->assertSessionHasErrors('error');

        $this->assertNotSame($other->id, (int) $room->fresh()->room_category_id);
    }

    public function test_description_can_still_be_edited_while_a_guest_is_checked_in(): void
    {
        $room = Room::factory()->create(['status' => 'occupied']);
        Stay::factory()->create(['room_id' => $room->id, 'status' => 'active']);

        $this->actingAs($this->owner())->put(route('rooms.update', $room), [
            'room_number' => $room->room_number,
            'room_category_id' => $room->room_category_id,
            'notes' => 'Minibar restocked weekly',
            'is_active' => '1',
        ])->assertSessionHasNoErrors();

        $this->assertSame('Minibar restocked weekly', $room->fresh()->notes);
    }

    public function test_a_room_assigned_to_an_upcoming_reservation_cannot_be_deactivated(): void
    {
        $room = Room::factory()->create();
        Reservation::factory()->create(['room_id' => $room->id, 'room_category_id' => $room->room_category_id, 'status' => 'confirmed']);

        $this->actingAs($this->owner())->put(route('rooms.update', $room), [
            'room_number' => $room->room_number,
            'room_category_id' => $room->room_category_id,
        ])->assertSessionHasErrors('error');

        $this->assertTrue($room->fresh()->is_active);
    }

    /** @dataProvider rolesWithoutEditRoomDetails */
    public function test_non_owner_roles_cannot_update_or_delete_rooms(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['room_number' => '303']);

        $this->actingAs($user)->put(route('rooms.update', $room), [
            'room_number' => '304',
            'room_category_id' => $room->room_category_id,
            'is_active' => '1',
        ])->assertForbidden();

        $this->actingAs($user)->delete(route('rooms.destroy', $room))->assertForbidden();

        $this->assertSame('303', $room->fresh()->room_number);
    }

    public static function rolesWithoutEditRoomDetails(): array
    {
        // General Manager and Duty Manager both hold 'manage-rooms' (add a
        // room) — neither gets edit/delete, which was requested for the
        // Owner specifically.
        return [['general_manager'], ['duty_manager'], ['accountant'], ['front_desk'], ['cashier']];
    }

    public function test_only_the_owner_sees_the_edit_room_controls_on_the_room_board(): void
    {
        Room::factory()->create();

        $this->actingAs($this->owner())->get(route('rooms.index'))->assertOk()->assertSee('Edit room');

        $gm = $this->userWithRole('general_manager', ['auth_type' => 'password_only']);
        $this->actingAs($gm)->get(route('rooms.index'))->assertOk()->assertDontSee('Edit room');
    }
}
