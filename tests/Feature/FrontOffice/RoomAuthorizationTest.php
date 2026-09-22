<?php

namespace Tests\Feature\FrontOffice;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.0 security fix: rooms.index previously had no permission gate at
 * all (routes/web.php), despite RoomController::index() eager-loading
 * currentStay.guest — meaning any authenticated role could see live room
 * occupancy and current guest names. This is now gated on the 'view-rooms'
 * permission (see RolesAndPermissionsSeeder), which is deliberately kept
 * separate from 'manage-rooms' so front-desk-type roles can view the room
 * board without also being able to add/edit rooms.
 */
class RoomAuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    /** @dataProvider rolesThatCanViewRooms */
    public function test_roles_with_view_rooms_permission_can_open_the_room_board(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $response = $this->actingAs($user)->get(route('rooms.index'));

        $response->assertOk();
    }

    public static function rolesThatCanViewRooms(): array
    {
        return [
            ['owner'],
            ['general_manager'],
            ['duty_manager'],
            ['front_desk'],
        ];
    }

    /** @dataProvider rolesThatCannotViewRooms */
    public function test_roles_without_view_rooms_permission_are_forbidden(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $response = $this->actingAs($user)->get(route('rooms.index'));

        $response->assertForbidden();
    }

    public static function rolesThatCannotViewRooms(): array
    {
        return [
            ['accountant'],
            ['cashier'],
            ['bar_staff'],
            ['restaurant_staff'],
        ];
    }

    public function test_a_role_without_manage_rooms_still_cannot_create_a_room_even_if_it_can_view_the_board(): void
    {
        // front_desk has view-rooms but not manage-rooms — confirms the two
        // permissions were correctly kept independent of one another.
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);

        $response = $this->actingAs($frontDesk)->post(route('rooms.store'), [
            'room_number' => '999',
            'room_category_id' => \App\Models\RoomCategory::factory()->create()->id,
        ]);

        $response->assertForbidden();
    }

    /**
     * Post-9.8 fix round ("let the receptionist have the access to
     * update room status"): front_desk holds the new 'update-room-status'
     * permission (kept separate from 'manage-rooms', same reasoning as
     * 'view-rooms') and can flip a non-occupied room's status directly
     * from the room board.
     */
    public function test_front_desk_can_update_a_rooms_status(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = \App\Models\Room::factory()->create(['status' => 'available']);

        $this->actingAs($frontDesk)->post(route('rooms.update-status', $room), [
            'status' => 'maintenance',
        ])->assertRedirect();

        $this->assertSame('maintenance', $room->fresh()->status);
    }

    public function test_a_role_without_update_room_status_cannot_change_a_rooms_status(): void
    {
        // bar_staff has neither view-rooms nor update-room-status.
        $barStaff = $this->pinUser('bar_staff');
        $room = \App\Models\Room::factory()->create(['status' => 'available']);

        $this->actingAs($barStaff)->post(route('rooms.update-status', $room), [
            'status' => 'maintenance',
        ])->assertForbidden();

        $this->assertSame('available', $room->fresh()->status);
    }

    public function test_an_occupied_room_cannot_have_its_status_changed_directly(): void
    {
        // Occupied must only ever change via an actual check-in/check-out
        // (which also manages the Stay/Folio) — never a bare status edit.
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = \App\Models\Room::factory()->create(['status' => 'occupied']);

        $this->actingAs($frontDesk)->post(route('rooms.update-status', $room), [
            'status' => 'available',
        ])->assertSessionHasErrors('error');

        $this->assertSame('occupied', $room->fresh()->status);
    }
}
