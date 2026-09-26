<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Guest;
use App\Models\Reservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Broader request ("...edit, delete and update all categories and every
 * input, except financial inputs"): Guest could previously only be
 * created and viewed. Guest already uses SoftDeletes, so "delete" here is
 * a reversible removal from the active list, never an erasure — every
 * stay/reservation/payment referencing the guest keeps working unchanged.
 */
class GuestEditDeleteTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_front_desk_can_update_a_guests_details(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create(['first_name' => 'Jean', 'phone' => '111']);

        $this->actingAs($frontDesk)->put(route('guests.update', $guest), [
            'first_name' => 'Jean-Paul',
            'last_name' => $guest->last_name,
            'phone' => '222',
        ])->assertRedirect();

        $guest->refresh();
        $this->assertSame('Jean-Paul', $guest->first_name);
        $this->assertSame('222', $guest->phone);
    }

    public function test_removing_a_guest_soft_deletes_and_keeps_their_reservation_history(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $guest = Guest::factory()->create();
        $reservation = Reservation::factory()->create(['guest_id' => $guest->id]);

        $this->actingAs($frontDesk)->delete(route('guests.destroy', $guest))->assertRedirect();

        $this->assertSoftDeleted('guests', ['id' => $guest->id]);
        // The reservation itself is untouched — nothing about it was rewritten.
        $this->assertDatabaseHas('reservations', ['id' => $reservation->id, 'guest_id' => $guest->id]);
    }

    public function test_an_owner_can_restore_a_removed_guest(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $guest = Guest::factory()->create();
        $guest->delete();

        $this->actingAs($owner)->post(route('guests.restore', $guest->id))->assertRedirect();

        $this->assertDatabaseHas('guests', ['id' => $guest->id, 'deleted_at' => null]);
    }

    public function test_a_role_without_manage_guests_cannot_edit_or_delete_a_guest(): void
    {
        $barStaff = $this->userWithRole('bar_staff');
        $guest = Guest::factory()->create();

        $this->actingAs($barStaff)->put(route('guests.update', $guest), [
            'first_name' => 'Hacked', 'last_name' => $guest->last_name,
        ])->assertForbidden();

        $this->actingAs($barStaff)->delete(route('guests.destroy', $guest))->assertForbidden();
        $this->assertNull($guest->fresh()->deleted_at);
    }
}
