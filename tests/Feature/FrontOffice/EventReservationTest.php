<?php

namespace Tests\Feature\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\EventReservation;
use App\Models\EventSpace;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Services\FrontOffice\EventReservationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request ("WE ALSO NEED RESERVATION FOR EVENT: WHAT KIND OF
 * EVENT, WHICH SPACES DO THEY WANT TO RESERVE, ROOM, WHICH ROOMS
 * (SELECT), MAIN HALL, MINI HALL FOR EXAMPLE, POOL OR THE WHOLE OUTER
 * ENVIROMENT (SPACE) ETC"), scoped via AskUserQuestion. Mirrors
 * ReservationTest/ReservationDepositTest's own shape, since
 * EventReservationService was explicitly built "mirroring how room
 * reservations already handle deposits and approval".
 */
class EventReservationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_front_desk_can_create_a_pending_event_reservation_with_a_space_and_a_room(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $space = EventSpace::create(['name' => 'Main Hall', 'is_active' => true]);
        $room = Room::factory()->create();

        $response = $this->actingAs($frontDesk)->post(route('event-reservations.store'), [
            'event_name' => "Aline's Wedding",
            'event_type' => 'Wedding',
            'contact_name' => 'Aline Mbarga',
            'contact_phone' => '677000000',
            'expected_guests' => 120,
            'start_at' => now()->addDays(5)->setTime(10, 0)->format('Y-m-d\TH:i'),
            'end_at' => now()->addDays(5)->setTime(18, 0)->format('Y-m-d\TH:i'),
            'deposit_required' => 50000,
            'event_space_ids' => [$space->id],
            'room_ids' => [$room->id],
        ]);

        $event = EventReservation::first();
        $response->assertRedirect(route('event-reservations.show', $event));
        $this->assertSame('pending', $event->status);
        $this->assertSame($frontDesk->id, $event->requested_by);
        $this->assertTrue($event->eventSpaces->contains($space));
        $this->assertTrue($event->rooms->contains($room));
        $this->assertNotEmpty($event->event_reference);
    }

    public function test_an_event_reservation_requires_at_least_one_space_or_room(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);

        $threw = false;
        try {
            app(EventReservationService::class)->create([
                'event_name' => 'Birthday',
                'event_type' => 'Birthday',
                'contact_name' => 'John Doe',
                'start_at' => now()->addDay()->toDateTimeString(),
                'end_at' => now()->addDay()->addHours(3)->toDateTimeString(),
            ], [], [], $frontDesk);
        } catch (HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Creating an event reservation with no space or room selected should be refused.');
        $this->assertSame(0, EventReservation::count());
    }

    public function test_the_create_form_refuses_an_event_with_neither_a_guest_nor_a_contact_name(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $space = EventSpace::create(['name' => 'Main Hall', 'is_active' => true]);

        $response = $this->actingAs($frontDesk)->post(route('event-reservations.store'), [
            'event_name' => 'Conference',
            'event_type' => 'Conference',
            'start_at' => now()->addDays(2)->format('Y-m-d\TH:i'),
            'end_at' => now()->addDays(2)->addHours(4)->format('Y-m-d\TH:i'),
            'event_space_ids' => [$space->id],
        ]);

        $response->assertSessionHasErrors('contact_name');
        $this->assertSame(0, EventReservation::count());
    }

    public function test_front_desk_can_approve_a_pending_event_reservation(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $event = $this->createPendingEvent($frontDesk);

        $this->actingAs($frontDesk)->post(route('event-reservations.approve', $event))->assertRedirect();

        $event->refresh();
        $this->assertSame('confirmed', $event->status);
        $this->assertSame($frontDesk->id, $event->approved_by);
        $this->assertNotNull($event->approved_at);
    }

    public function test_front_desk_can_reject_a_pending_event_reservation_with_a_reason(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $event = $this->createPendingEvent($frontDesk);

        $this->actingAs($frontDesk)->post(route('event-reservations.reject', $event), [
            'reason' => 'Double-booked with another client',
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame('rejected', $event->status);
        $this->assertSame('Double-booked with another client', $event->rejection_reason);
    }

    public function test_front_desk_can_cancel_a_confirmed_event_reservation(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $event = $this->createPendingEvent($frontDesk);
        app(EventReservationService::class)->approve($event, $frontDesk);

        $this->actingAs($frontDesk)->post(route('event-reservations.cancel', $event), [
            'reason' => 'Client postponed',
        ])->assertRedirect();

        $this->assertSame('cancelled', $event->fresh()->status);
    }

    public function test_a_role_without_manage_event_reservations_is_forbidden_from_creating_one(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $space = EventSpace::create(['name' => 'Main Hall', 'is_active' => true]);

        $response = $this->actingAs($barStaff)->post(route('event-reservations.store'), [
            'event_name' => 'Party', 'event_type' => 'Party', 'contact_name' => 'Someone',
            'start_at' => now()->addDay()->format('Y-m-d\TH:i'),
            'end_at' => now()->addDay()->addHours(2)->format('Y-m-d\TH:i'),
            'event_space_ids' => [$space->id],
        ]);

        $response->assertForbidden();
        $this->assertSame(0, EventReservation::count());
    }

    public function test_a_role_without_approve_event_reservation_is_forbidden(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $event = $this->createPendingEvent($frontDesk);

        $response = $this->actingAs($barStaff)->post(route('event-reservations.approve', $event));

        $response->assertForbidden();
        $this->assertSame('pending', $event->fresh()->status);
    }

    public function test_an_event_space_already_booked_over_an_overlapping_time_cannot_be_double_booked(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $space = EventSpace::create(['name' => 'Main Hall', 'is_active' => true]);

        app(EventReservationService::class)->create([
            'event_name' => 'First Event',
            'event_type' => 'Conference',
            'contact_name' => 'First Client',
            'start_at' => now()->addDays(3)->setTime(9, 0)->toDateTimeString(),
            'end_at' => now()->addDays(3)->setTime(17, 0)->toDateTimeString(),
        ], [$space->id], [], $frontDesk);

        $threw = false;
        try {
            app(EventReservationService::class)->create([
                'event_name' => 'Second Event',
                'event_type' => 'Wedding',
                'contact_name' => 'Second Client',
                'start_at' => now()->addDays(3)->setTime(12, 0)->toDateTimeString(),
                'end_at' => now()->addDays(3)->setTime(20, 0)->toDateTimeString(),
            ], [$space->id], [], $frontDesk);
        } catch (HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Overlapping the same event space with another live event reservation must be refused.');
        $this->assertSame(1, EventReservation::count());
    }

    /**
     * Proves EventReservationService reuses Room::isAvailableFor() directly
     * rather than re-implementing room-conflict logic — a room already
     * booked by a genuine guest Reservation cannot also be booked for an
     * event over an overlapping date range.
     */
    public function test_a_room_already_booked_by_a_guest_reservation_cannot_be_booked_for_an_event(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $room = Room::factory()->create();

        Reservation::factory()->create([
            'room_category_id' => $room->room_category_id,
            'room_id' => $room->id,
            'status' => 'confirmed',
            'check_in_date' => now()->addDays(2)->toDateString(),
            'check_out_date' => now()->addDays(4)->toDateString(),
        ]);

        $threw = false;
        try {
            app(EventReservationService::class)->create([
                'event_name' => 'Event',
                'event_type' => 'Meeting',
                'contact_name' => 'Client',
                'start_at' => now()->addDays(3)->toDateTimeString(),
                'end_at' => now()->addDays(3)->addHours(3)->toDateTimeString(),
            ], [], [$room->id], $frontDesk);
        } catch (HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'A room already booked by a guest reservation over an overlapping period must be refused for an event too.');
        $this->assertSame(0, EventReservation::count());
    }

    public function test_recording_an_event_deposit_requires_an_open_front_office_shift(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $event = $this->createPendingEvent($frontDesk);

        $threw = false;
        try {
            app(EventReservationService::class)->recordDeposit($event, 50000, 'cash', $frontDesk);
        } catch (HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'An event deposit should be refused when Front Office has no open shift, exactly like a reservation deposit.');
        $this->assertSame(0, Payment::count());
    }

    public function test_recording_an_event_deposit_attributes_to_front_office_and_its_open_shift(): void
    {
        $shift = $this->openShiftFor('FO');
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $event = $this->createPendingEvent($frontDesk);

        $this->actingAs($frontDesk)->post(route('event-reservations.deposit', $event), [
            'amount' => 50000,
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $event->refresh();
        $this->assertSame('50000.00', $event->deposit_paid);

        $payment = Payment::first();
        $this->assertNotNull($payment);
        $this->assertSame('event_deposit', $payment->source_module);
        $this->assertSame($shift->department_id, $payment->department_id);
        $this->assertSame($shift->id, $payment->shift_id);
    }

    private function createPendingEvent($actor): EventReservation
    {
        $space = EventSpace::create(['name' => 'Main Hall '.uniqid(), 'is_active' => true]);
        $guest = Guest::factory()->create();

        return app(EventReservationService::class)->create([
            'event_name' => 'Test Event',
            'event_type' => 'Conference',
            'guest_id' => $guest->id,
            'start_at' => now()->addDays(3)->toDateTimeString(),
            'end_at' => now()->addDays(3)->addHours(4)->toDateTimeString(),
            'deposit_required' => 50000,
        ], [$space->id], [], $actor);
    }
}
