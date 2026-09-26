<?php

namespace Tests\Feature\Admin;

use App\Models\RoomCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.2 (PHASE9_AUDIT_AND_PLAN.md §3.3): RoomCategory (rates, names,
 * occupancy) existed since Phase 1 but was only ever seeded — Reservations
 * and the Room board read it, nothing ever wrote to it outside a seeder.
 * Gated on 'manage-settings' (Owner/GM only), not 'manage-rooms', since
 * changing a nightly rate is a pricing decision distinct from Duty
 * Manager/Front Desk adding a physical room.
 */
class RoomCategoryAdminTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_create_a_room_category(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $response = $this->actingAs($owner)->post(route('admin.room-categories.store'), [
            'name' => 'Presidential Suite',
            'max_occupancy' => 4,
            'base_rate' => 150000,
            'description' => 'Top floor, ocean view',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('room_categories', ['name' => 'Presidential Suite', 'base_rate' => 150000, 'is_active' => true]);
    }

    public function test_owner_can_update_a_room_categorys_rate(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create(['base_rate' => 25000]);

        $response = $this->actingAs($owner)->put(route('admin.room-categories.update', $category), [
            'name' => $category->name,
            'max_occupancy' => $category->max_occupancy,
            'base_rate' => 32000,
            'description' => $category->description,
        ]);

        $response->assertRedirect();
        $this->assertSame('32000.00', $category->fresh()->base_rate);
    }

    public function test_deactivating_a_room_category_does_not_touch_existing_rooms(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create();
        $room = \App\Models\Room::factory()->create(['room_category_id' => $category->id]);

        $this->actingAs($owner)->post(route('admin.room-categories.deactivate', $category))->assertRedirect();

        $this->assertFalse($category->fresh()->is_active);
        $this->assertSame($category->id, $room->fresh()->room_category_id);
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs").
     */
    public function test_owner_can_delete_a_room_category_that_was_never_used(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create();

        $this->actingAs($owner)->delete(route('admin.room-categories.destroy', $category))->assertRedirect();

        $this->assertDatabaseMissing('room_categories', ['id' => $category->id]);
    }

    public function test_a_room_category_with_a_room_assigned_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = RoomCategory::factory()->create();
        \App\Models\Room::factory()->create(['room_category_id' => $category->id]);

        $this->actingAs($owner)->delete(route('admin.room-categories.destroy', $category))->assertSessionHasErrors('error');

        $this->assertDatabaseHas('room_categories', ['id' => $category->id]);
    }

    /** @dataProvider rolesWithoutManageSettings */
    public function test_roles_without_manage_settings_permission_are_forbidden(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);

        $this->actingAs($user)->get(route('admin.room-categories.index'))->assertForbidden();
    }

    public function test_duty_manager_who_can_manage_rooms_but_not_settings_still_cannot_change_rates(): void
    {
        // Explicitly confirms the 'manage-settings' vs 'manage-rooms' split:
        // duty_manager holds manage-rooms but not manage-settings.
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);

        $this->actingAs($dutyManager)->get(route('admin.room-categories.index'))->assertForbidden();
    }

    public static function rolesWithoutManageSettings(): array
    {
        return [['duty_manager'], ['accountant'], ['cashier'], ['front_desk'], ['bar_staff'], ['restaurant_staff']];
    }
}
