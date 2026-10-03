<?php

namespace Tests\Feature\FrontOffice;

use App\Models\EventReservation;
use App\Models\EventSpace;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request, clarified via AskUserQuestion ("Main Hall, Mini HALL,
 * Conference halls, etc you know better" — an admin-editable list, not a
 * fixed one). Mirrors ChargeTypeManagementTest's own shape for the same
 * "deactivate, don't delete if used" admin-list pattern.
 */
class EventSpaceTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_add_a_new_event_space(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->post(route('event-spaces.store'), [
            'name' => 'Garden Terrace',
            'description' => 'Outdoor terrace overlooking the garden.',
            'max_capacity' => 150,
        ])->assertRedirect();

        $space = EventSpace::where('name', 'Garden Terrace')->first();
        $this->assertNotNull($space);
        $this->assertTrue($space->is_active);
        $this->assertSame(150, $space->max_capacity);
    }

    public function test_duty_manager_can_update_and_deactivate_an_event_space(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $space = EventSpace::create(['name' => 'Mini Hall', 'is_active' => true]);

        $this->actingAs($dutyManager)->put(route('event-spaces.update', $space), [
            'name' => 'Mini Hall (Annex)',
            'is_active' => '0',
        ])->assertRedirect();

        $space->refresh();
        $this->assertSame('Mini Hall (Annex)', $space->name);
        $this->assertFalse($space->is_active);
    }

    public function test_an_event_space_with_booking_history_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $space = EventSpace::create(['name' => 'Main Hall', 'is_active' => true]);
        $requester = $this->userWithRole('front_desk');

        $event = EventReservation::create([
            'event_reference' => 'EVT-TEST-0001',
            'event_name' => 'Wedding',
            'event_type' => 'Wedding',
            'start_at' => now()->addDay(),
            'end_at' => now()->addDay()->addHours(4),
            'status' => 'pending',
            'contact_name' => 'Jane Doe',
            'requested_by' => $requester->id,
        ]);
        $event->eventSpaces()->attach($space->id);

        $response = $this->actingAs($owner)->delete(route('event-spaces.destroy', $space));

        $response->assertRedirect();
        $this->assertTrue(EventSpace::where('id', $space->id)->exists(), 'A space with real booking history must not be deleted.');
    }

    public function test_a_role_without_manage_event_spaces_is_forbidden(): void
    {
        $frontDesk = $this->userWithRole('front_desk');

        $response = $this->actingAs($frontDesk)->post(route('event-spaces.store'), ['name' => 'Pool Deck']);

        $response->assertForbidden();
        $this->assertFalse(EventSpace::where('name', 'Pool Deck')->exists());
    }
}
