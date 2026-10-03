<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\EventReservation;
use App\Models\EventSpace;
use App\Models\Room;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentLedgerService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Direct request ("WE ALSO NEED RESERVATION FOR EVENT..."), built to
 * mirror ReservationService on explicit request ("mirroring how room
 * reservations already handle deposits and approval") — same guarded-
 * lifecycle shape (create as pending, lock-and-recheck inside a
 * transaction before every transition, one audit-log entry per
 * transition), same recordDeposit() pattern through the central
 * PaymentLedgerService.
 *
 * Two things are deliberately simpler here than Reservation, disclosed
 * rather than silently copied over: there is no separate
 * approved-then-immediately-confirmed two-step status flip (approve()
 * goes straight from 'pending' to 'confirmed') since nothing in this
 * engagement asked for that intermediate state to be independently
 * meaningful for an event; and there is no deposit-disposition
 * (refund/forfeit/transfer) workflow on cancellation — a cancelled
 * event's deposit simply stays recorded as-is, and any actual refund is
 * handled manually via the existing Finance > Refunds screen if one is
 * owed. Both are easy to add later if this hotel's event volume grows
 * enough to need them; neither was asked for this round.
 */
class EventReservationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentLedgerService $ledger,
    ) {
    }

    /**
     * @param  array{event_name:string, event_type:string, guest_id?:int|null, contact_name?:string|null, contact_phone?:string|null, contact_email?:string|null, expected_guests?:int|null, start_at:string, end_at:string, agreed_amount?:float|null, deposit_required?:float|null, notes?:string|null}  $data
     * @param  array<int,int>  $eventSpaceIds
     * @param  array<int,int>  $roomIds
     */
    public function create(array $data, array $eventSpaceIds, array $roomIds, User $requestedBy): EventReservation
    {
        if (count($eventSpaceIds) < 1 && count($roomIds) < 1) {
            throw new HmsException('Select at least one space or room to reserve for this event.');
        }

        // Enforced again here, not only at the controller layer (which
        // already blocks this before ever calling create() — see
        // EventReservationController::store()): an event organizer is very
        // often not a lodging guest, but SOME way to identify who this
        // booking is for must always be recorded.
        if (empty($data['guest_id']) && empty($data['contact_name'])) {
            throw new HmsException('An event reservation needs either an existing guest or a contact name.');
        }

        $startAt = \Carbon\Carbon::parse($data['start_at']);
        $endAt = \Carbon\Carbon::parse($data['end_at']);

        if (! $endAt->gt($startAt)) {
            throw new HmsException('The event must end after it starts.');
        }

        return DB::transaction(function () use ($data, $eventSpaceIds, $roomIds, $requestedBy, $startAt, $endAt) {
            $this->assertNoConflicts($eventSpaceIds, $roomIds, $startAt, $endAt, excludeEventReservationId: null);

            $event = EventReservation::create([
                'event_reference' => $this->generateReference(),
                'event_name' => $data['event_name'],
                'event_type' => $data['event_type'],
                'guest_id' => $data['guest_id'] ?? null,
                'contact_name' => $data['contact_name'] ?? null,
                'contact_phone' => $data['contact_phone'] ?? null,
                'contact_email' => $data['contact_email'] ?? null,
                'expected_guests' => $data['expected_guests'] ?? null,
                'start_at' => $startAt,
                'end_at' => $endAt,
                'status' => 'pending',
                'agreed_amount' => $data['agreed_amount'] ?? null,
                'deposit_required' => $data['deposit_required'] ?? 0,
                'requested_by' => $requestedBy->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $event->eventSpaces()->sync($eventSpaceIds);
            $event->rooms()->sync($roomIds);

            $this->audit->log('event_reservation.created', 'front_office', $event, null, $event->toArray(), actor: $requestedBy);

            return $event->fresh(['eventSpaces', 'rooms']);
        });
    }

    public function approve(EventReservation $event, User $approver): EventReservation
    {
        return DB::transaction(function () use ($event, $approver) {
            /** @var EventReservation $event */
            $event = EventReservation::lockForUpdate()->findOrFail($event->id);

            if ($event->status !== 'pending') {
                throw new HmsException("Only a pending event reservation can be approved (current status: {$event->status}).");
            }

            // Re-run the exact same conflict check as create(), now
            // inside this lock — closes the same race ReservationService::
            // approve() guards against (two near-simultaneous approvals
            // that would otherwise both pass a pre-lock check).
            $this->assertNoConflicts(
                $event->eventSpaces()->pluck('event_spaces.id')->all(),
                $event->rooms()->pluck('rooms.id')->all(),
                $event->start_at,
                $event->end_at,
                excludeEventReservationId: $event->id,
            );

            $old = $event->toArray();
            $event->update([
                'status' => 'confirmed',
                'approved_by' => $approver->id,
                'approved_at' => now(),
            ]);

            $this->audit->log('event_reservation.approved', 'front_office', $event, $old, $event->toArray(), actor: $approver);

            return $event;
        });
    }

    public function reject(EventReservation $event, User $approver, string $reason): EventReservation
    {
        return DB::transaction(function () use ($event, $approver, $reason) {
            /** @var EventReservation $event */
            $event = EventReservation::lockForUpdate()->findOrFail($event->id);

            if ($event->status !== 'pending') {
                throw new HmsException("Only a pending event reservation can be rejected (current status: {$event->status}).");
            }

            $old = $event->toArray();
            $event->update([
                'status' => 'rejected',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->log('event_reservation.rejected', 'front_office', $event, $old, $event->toArray(), $reason, $approver);

            return $event;
        });
    }

    public function cancel(EventReservation $event, User $actor, string $reason): EventReservation
    {
        return DB::transaction(function () use ($event, $actor, $reason) {
            /** @var EventReservation $event */
            $event = EventReservation::lockForUpdate()->findOrFail($event->id);

            if (! in_array($event->status, ['pending', 'confirmed'], true)) {
                throw new HmsException("Event reservation cannot be cancelled from status: {$event->status}.");
            }

            $old = $event->toArray();
            $event->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);

            $this->audit->log('event_reservation.cancelled', 'front_office', $event, $old, $event->toArray(), $reason, $actor);

            return $event;
        });
    }

    /**
     * Mirrors ReservationService::recordDeposit() closely — same
     * idempotency-key protection against a retried/double-submitted
     * deposit, same single entry point into the central ledger. Posted
     * with `sourceModule: 'event_deposit'`, which PaymentLedgerService
     * auto-attributes to Front Office (the same department a reservation
     * deposit already is) and therefore requires an open Front Office
     * shift, exactly like recording a reservation deposit already does.
     */
    public function recordDeposit(EventReservation $event, float $amount, string $method, User $actor, ?int $financialAccountId = null, ?string $notes = null, ?string $idempotencyKey = null): EventReservation
    {
        if ($amount <= 0) {
            throw new HmsException('Deposit amount must be greater than zero.');
        }

        if ($idempotencyKey !== null && \App\Models\Payment::where('idempotency_key', $idempotencyKey)->exists()) {
            return $event->fresh();
        }

        return DB::transaction(function () use ($event, $amount, $method, $actor, $financialAccountId, $notes, $idempotencyKey) {
            /** @var EventReservation $event */
            $event = EventReservation::lockForUpdate()->findOrFail($event->id);

            if (in_array($event->status, ['cancelled', 'rejected'], true)) {
                throw new HmsException("A deposit cannot be recorded for a {$event->status} event reservation.");
            }

            $old = $event->only(['deposit_paid']);

            $payment = $this->ledger->record(
                amount: abs($amount),
                method: $method,
                sourceModule: 'event_deposit',
                recordedBy: $actor,
                sourceReference: "Event {$event->event_reference}",
                guestId: $event->guest_id,
                notes: $notes ?? "Advance/deposit for event {$event->event_reference} ({$event->event_name})",
                financialAccountId: $financialAccountId,
                idempotencyKey: $idempotencyKey,
            );

            if ($payment->wasRecentlyCreated) {
                $event->update([
                    'deposit_paid' => bcadd((string) $event->deposit_paid, (string) abs($amount), 2),
                ]);

                $this->audit->log('event_reservation.deposit_recorded', 'front_office', $event, $old, $event->only(['deposit_paid']), actor: $actor);
            }

            return $event;
        });
    }

    /**
     * Shared by create() and approve(): no event space or room selected
     * here may already be booked by another still-live (pending/
     * confirmed) event reservation over an overlapping time range, and no
     * selected room may already be unavailable for a genuine guest Stay/
     * Reservation either — reusing Room::isAvailableFor() directly rather
     * than re-implementing that overlap rule a second time.
     */
    private function assertNoConflicts(array $eventSpaceIds, array $roomIds, \DateTimeInterface $startAt, \DateTimeInterface $endAt, ?int $excludeEventReservationId): void
    {
        if ($eventSpaceIds !== []) {
            $conflict = EventSpace::whereIn('id', $eventSpaceIds)
                ->whereHas('eventReservations', function ($q) use ($startAt, $endAt, $excludeEventReservationId) {
                    $q->whereIn('status', ['pending', 'confirmed'])
                        ->when($excludeEventReservationId, fn ($q2) => $q2->where('event_reservations.id', '!=', $excludeEventReservationId))
                        ->where('start_at', '<', $endAt)
                        ->where('end_at', '>', $startAt);
                })
                ->first();

            if ($conflict) {
                throw new HmsException("{$conflict->name} is already booked by another event during this time.");
            }
        }

        foreach ($roomIds as $roomId) {
            /** @var Room $room */
            $room = Room::findOrFail($roomId);

            if (! $room->isAvailableFor($startAt, $endAt)) {
                throw new HmsException("Room {$room->room_number} is not available for the requested dates — it already has a guest stay/reservation or another event booking over this period.");
            }

            $eventConflict = EventReservation::whereIn('status', ['pending', 'confirmed'])
                ->when($excludeEventReservationId, fn ($q) => $q->where('id', '!=', $excludeEventReservationId))
                ->whereHas('rooms', fn ($q) => $q->where('rooms.id', $roomId))
                ->where('start_at', '<', $endAt)
                ->where('end_at', '>', $startAt)
                ->exists();

            if ($eventConflict) {
                throw new HmsException("Room {$room->room_number} is already booked by another event during this time.");
            }
        }
    }

    private function generateReference(): string
    {
        return 'EVT-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
