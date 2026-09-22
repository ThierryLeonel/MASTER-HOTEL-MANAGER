<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\Folio;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\ShiftGateService;
use App\Services\FrontOffice\DiscountApprovalService;
use Illuminate\Support\Facades\DB;

/**
 * Check-in creates a Stay + an open Folio, and flips the room to
 * 'occupied'. Guards against double-booking / checking into an occupied
 * room with a row lock on the room inside the transaction (spec section
 * 11) — two concurrent check-in requests for the same room cannot both
 * succeed.
 *
 * Global hospitality-standard change (user request: "let the total cost
 * be in the folio... if someone is checking for example 4 days and the
 * room cost 20000 per night, let the total cost be in the folio"): the
 * FULL anticipated lodging cost for the whole reserved/expected duration
 * (agreed_rate x nights) is posted to the folio immediately at check-in,
 * as a real balance-affecting charge — not just shown as informational
 * "X/night" text. How many nights that charge covered is recorded on
 * the Stay (`nights_charged`) so CheckOutService can later charge only a
 * genuine extension, never the same nights twice. Any reservation
 * deposit already recorded (ReservationService::recordDeposit(), a real
 * payment in the central ledger) is applied as a folio credit right
 * after, so the folio's balance reflects the guest's true outstanding
 * amount from the moment they check in.
 */
class CheckInService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FolioService $folioService,
        private readonly ShiftGateService $shiftGate,
        private readonly DiscountApprovalService $discountApproval,
    ) {
    }

    public function checkInFromReservation(Reservation $reservation, User $actor, ?int $roomId = null, ?int $companyId = null): Stay
    {
        // "No transaction without an open shift" (PHASE9_AUDIT_AND_PLAN.md
        // §21) — check-in posts the initial lodging charge below via
        // FolioService::postCharge(), which never touches
        // PaymentLedgerService, so this has to be asserted explicitly
        // here. Deliberately NOT applied to checkout (CheckOutService) —
        // see ShiftGateService's docblock.
        $this->shiftGate->assertOpenForCode('FO');

        return DB::transaction(function () use ($reservation, $actor, $roomId, $companyId) {
            // Correction round (audited defect #1): the `confirmed` status
            // used to be checked ONLY against the caller-supplied, unlocked
            // $reservation, before this transaction even opened, and the
            // Reservation row was never locked here at all — only the Room
            // was. Two concurrent check-in requests for the same
            // reservation (a genuine double-submit, or two staff members
            // both clicking "Check in") — even naming two DIFFERENT rooms
            // — could both pass that check, both create a Stay for the
            // same reservation, and both call applyAdvanceCredit() with
            // the reservation's full deposit_paid, double-applying it to
            // two separate folios. Locking and re-checking the Reservation
            // row here, inside the transaction, closes that: the second
            // call always finds status already `checked_in` and fails
            // cleanly. (stays.reservation_id also now has a DB-level
            // unique constraint — migration 2025_02_01_000100 — as a second,
            // independent line of defence.)
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== 'confirmed') {
                throw new HmsException("Only a confirmed reservation can be checked in (current status: {$reservation->status}).");
            }

            $roomId ??= $reservation->room_id;

            if (! $roomId) {
                throw new HmsException('A room must be assigned before check-in.');
            }

            $room = $this->lockAndValidateRoom($roomId);

            // Feature 6 / audited defect #6 ("prevent zero-rate/
            // unauthorized-discount bypass"): rate_quoted was previously
            // validated only as min:0 — any staff member could quote (and
            // now check in at) any rate, including zero. Checked against
            // the reservation's own originally-booked category, before
            // the Stay is even created, so an unauthorized discount fails
            // fast without allocating a Stay/Folio/room charge at all.
            $this->discountApproval->authorizeRateForCheckIn(
                $reservation,
                $reservation->roomCategory,
                $roomId,
                $reservation->check_in_date,
                $reservation->check_out_date,
                (float) $reservation->rate_quoted,
                $actor,
            );

            $stay = Stay::create([
                'reservation_id' => $reservation->id,
                'guest_id' => $reservation->guest_id,
                'company_id' => $companyId,
                'room_id' => $room->id,
                'check_in_at' => now(),
                'expected_check_out_date' => $reservation->check_out_date,
                'agreed_rate' => $reservation->rate_quoted,
                'status' => 'active',
                'checked_in_by' => $actor->id,
            ]);

            $folio = $this->openFolio($stay);
            $this->postInitialLodgingCharge($stay, $folio, $actor);

            // "the advance on reservation are also payments... [it] should
            // be included in the folio" — the deposit was already ledgered
            // once, at the moment it was actually received
            // (ReservationService::recordDeposit()); applying it here must
            // not record it in the payments ledger a second time, hence
            // applyAdvanceCredit() rather than postPayment().
            if ((float) $reservation->deposit_paid > 0) {
                $this->folioService->applyAdvanceCredit(
                    $folio,
                    (float) $reservation->deposit_paid,
                    "Advance payment applied — Reservation {$reservation->reservation_number}",
                    $actor,
                );
            }

            $room->update(['status' => 'occupied']);

            $reservation->update(['status' => 'checked_in', 'room_id' => $room->id]);

            $this->audit->log('checkin.completed', 'front_office', $stay, null, $stay->toArray(), actor: $actor);

            return $stay;
        });
    }

    public function checkInWalkIn(
        Guest $guest,
        int $roomId,
        \DateTimeInterface $expectedCheckOut,
        float $agreedRate,
        User $actor,
        ?int $companyId = null,
        ?int $discountApproverId = null,
        ?string $discountReason = null,
    ): Stay {
        $this->shiftGate->assertOpenForCode('FO');

        return DB::transaction(function () use ($guest, $roomId, $expectedCheckOut, $agreedRate, $actor, $companyId, $discountApproverId, $discountReason) {
            $room = $this->lockAndValidateRoom($roomId);

            $stay = Stay::create([
                'reservation_id' => null,
                'guest_id' => $guest->id,
                'company_id' => $companyId,
                'room_id' => $room->id,
                'check_in_at' => now(),
                'expected_check_out_date' => $expectedCheckOut,
                'agreed_rate' => $agreedRate,
                'status' => 'active',
                'checked_in_by' => $actor->id,
            ]);

            // Feature 6 / audited defect #6: same gate as the
            // reservation-based path above, keyed off the room's actual
            // category since a walk-in has no separately-quoted category
            // of its own. Bound to the Stay itself (approvable_type =
            // Stay) — a walk-in has no Reservation row to attach it to at
            // all. Deliberately runs AFTER Stay::create() (there is
            // nothing else to bind the approval to before it exists) but
            // still safely inside this same DB transaction: if it throws
            // (including "the named approver isn't actually eligible"),
            // the whole transaction — including the Stay row just created
            // above — rolls back, so no half-checked-in walk-in is ever
            // left behind.
            //
            // $discountApproverId is the walk-in-only exception described
            // on DiscountApprovalService's class docblock: when the
            // receptionist isn't personally eligible to approve the
            // discount and named a specific eligible approver instead,
            // check-in proceeds now with a PENDING approval attached
            // rather than being refused — CheckInController is the only
            // caller that ever supplies this.
            $this->discountApproval->authorizeRateForCheckIn(
                $stay,
                $room->category,
                $room->id,
                $stay->check_in_at,
                $expectedCheckOut,
                (float) $agreedRate,
                $actor,
                $discountReason,
                $discountApproverId,
            );

            $folio = $this->openFolio($stay);
            $this->postInitialLodgingCharge($stay, $folio, $actor);

            $room->update(['status' => 'occupied']);

            $this->audit->log('checkin.walk_in', 'front_office', $stay, null, $stay->toArray(), actor: $actor);

            return $stay;
        });
    }

    private function lockAndValidateRoom(int $roomId): Room
    {
        /** @var Room $room */
        $room = Room::lockForUpdate()->findOrFail($roomId);

        // Correction round (audited defect #1, sharing its root cause with
        // #4/#9/#11): this used to trust the room's mutable `status`
        // column alone, which — per Room::hasActiveStay()'s own docblock —
        // is not a reliable "is someone actually in this room" signal (a
        // walk-in occupancy, or a stale column, was invisible to it).
        // hasActiveStay() checks the real Stay row instead, and
        // hasBlockingMaintenance() is the same authoritative source
        // MaintenanceService/CheckOutService already use elsewhere.
        if ($room->hasActiveStay()) {
            throw new HmsException("Room {$room->room_number} is already occupied — cannot check in.");
        }

        if ($room->hasBlockingMaintenance()) {
            throw new HmsException("Room {$room->room_number} has an unresolved maintenance ticket taking it out of service — cannot check in.");
        }

        if (! $room->is_active || $room->status === 'out_of_service') {
            throw new HmsException("Room {$room->room_number} is out of service and cannot be checked into.");
        }

        return $room;
    }

    private function openFolio(Stay $stay): Folio
    {
        return Folio::create([
            'stay_id' => $stay->id,
            'status' => 'open',
            'balance' => 0,
        ]);
    }

    /**
     * Posts the full anticipated lodging cost (agreed_rate x expected
     * nights) as a single room_charge at check-in, and records how many
     * nights it covered — see this class's docblock and
     * CheckOutService::checkOut() for how that number is later used to
     * charge only genuine extensions.
     */
    private function postInitialLodgingCharge(Stay $stay, Folio $folio, User $actor): void
    {
        $nights = $stay->expectedNights();
        $total = round((float) $stay->agreed_rate * $nights, 2);

        $this->folioService->postCharge(
            $folio,
            'room_charge',
            $total,
            "Room charge — {$nights} night(s) @ ".number_format((float) $stay->agreed_rate, 0).'/night',
            $actor,
        );

        $stay->update(['nights_charged' => $nights]);
    }
}
