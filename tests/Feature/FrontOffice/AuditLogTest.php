<?php

namespace Tests\Feature\FrontOffice;

use App\Models\AuditLog;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_reservation_approval_writes_an_audit_log_entry(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $room = Room::factory()->create();
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'status' => 'pending',
        ]);

        $this->actingAs($dutyManager)->post(route('reservations.approve', $reservation), ['room_id' => $room->id]);

        $entry = AuditLog::where('action', 'reservation.approved')->first();

        $this->assertNotNull($entry);
        $this->assertSame($dutyManager->id, $entry->user_id);
        $this->assertSame('duty_manager', $entry->role_name);
        $this->assertSame(Reservation::class, $entry->record_type);
        $this->assertSame($reservation->id, $entry->record_id);
    }

    public function test_checkin_writes_an_audit_log_entry(): void
    {
        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21):
        // check-in now requires an open Front Office shift.
        $this->openShiftFor('FO');

        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create(['status' => 'available']);
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->id,
            'status' => 'confirmed',
        ]);

        $this->actingAs($frontDesk)->post(route('reservations.checkin', $reservation));

        $this->assertDatabaseHas('audit_logs', [
            'action' => 'checkin.completed',
            'user_id' => $frontDesk->id,
            'module' => 'front_office',
        ]);
    }

    public function test_audit_log_captures_client_ip_address(): void
    {
        $dutyManager = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);
        $room = Room::factory()->create();
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $room->room_category_id,
            'status' => 'pending',
        ]);

        $this->actingAs($dutyManager)
            ->from('/')
            ->post(route('reservations.approve', $reservation), ['room_id' => $room->id]);

        $entry = AuditLog::where('action', 'reservation.approved')->first();
        $this->assertNotEmpty($entry->ip_address);
    }
}
