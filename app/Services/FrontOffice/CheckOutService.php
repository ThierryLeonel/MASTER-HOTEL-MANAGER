<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\AccountsReceivable;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;

/**
 * Checkout closes the folio and stay, frees the room back to 'available',
 * and — critically per spec section 10 — an unpaid balance never blocks
 * checkout. Any positive balance remaining when the folio closes becomes
 * an Accounts Receivable record instead.
 *
 * (Removed at the user's request: this used to also flip the room's
 * separate `housekeeping_status` to 'dirty' — that whole clean/dirty
 * cleanliness label on rooms no longer exists; the Housekeeping task
 * list itself is unaffected.)
 *
 * Global hospitality-standard change (this class previously posted an
 * OPTIONAL, manually-checked "final night" room charge at checkout — that
 * only ever made sense because the folio started at zero and nothing had
 * been charged yet). Now that CheckInService posts the FULL anticipated
 * stay cost upfront at check-in, checkout must never repeat that charge —
 * it only ever posts the DELTA for a genuine extension (nights actually
 * stayed beyond `nights_charged`). An early departure is deliberately
 * never auto-credited back here (standard non-refundable nightly-rate
 * policy); returning money for an early departure is a manual Finance ->
 * Refunds decision, not something checkout does silently.
 */
class CheckOutService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FolioService $folioService,
    ) {
    }

    public function checkOut(Stay $stay, User $actor): Stay
    {
        return DB::transaction(function () use ($stay, $actor) {
            // Correction round (audited defect #23): checkout used to
            // check `$stay->status` on the caller-supplied, unlocked
            // instance BEFORE opening this transaction, then never
            // re-checked it after acquiring the lock below — a genuine
            // idempotency gap. Two concurrent (or naively retried)
            // checkout calls on the same Stay could both pass that first
            // check, both proceed, and both create an AccountsReceivable
            // row for the identical debt. The status is now re-checked
            // here, against the freshly `lockForUpdate()`'d row, so a
            // second call always finds `status = 'closed'` and fails
            // cleanly instead of duplicating the receivable.
            $stay = Stay::lockForUpdate()->findOrFail($stay->id);

            if ($stay->status !== 'active') {
                throw new HmsException("Only an active stay can be checked out (current status: {$stay->status}).");
            }

            $folio = $stay->folio()->lockForUpdate()->firstOrFail();

            $actualCheckOutAt = now();
            $actualNights = $stay->nightsUntil($actualCheckOutAt);
            $extraNights = max(0, $actualNights - $stay->nights_charged);

            // A complimentary stay's room charge is waived — silently
            // skip posting any extension charge here rather than throwing
            // (checkout must never be blocked, spec section 10); the
            // manual "post charge" form is where FolioService::
            // postCharge() actually surfaces this as an error to a human
            // who tries to charge a comp stay for its room on purpose.
            if ($extraNights > 0 && ! $stay->is_complimentary) {
                $this->folioService->postCharge(
                    $folio,
                    'room_charge',
                    round((float) $stay->agreed_rate * $extraNights, 2),
                    "Room charge — {$extraNights} additional night(s) beyond the original {$stay->nights_charged}-night stay",
                    $actor,
                );
                $folio->refresh();
                $stay->nights_charged += $extraNights;
            }

            $stay->update([
                'status' => 'closed',
                'actual_check_out_at' => $actualCheckOutAt,
                'checked_out_by' => $actor->id,
                'nights_charged' => $stay->nights_charged,
            ]);

            $folio->update(['status' => 'closed', 'closed_at' => now(), 'closed_by' => $actor->id]);

            // Correction round (audited defect #10): checkout used to
            // unconditionally set the room back to 'available', even when
            // it still carried an unresolved, approved/in_progress
            // takes_room_out_of_service ticket — silently releasing a room
            // maintenance had already flagged as unsafe/unusable back into
            // the booking pool. Room::hasBlockingMaintenance() (already
            // the authoritative source for this elsewhere — see
            // MaintenanceService and Room::isAvailableFor()) is checked
            // here too, under the same row lock used throughout this
            // method's transaction, so a room is only ever freed to
            // 'available' when nothing is actually blocking it.
            $room = Room::lockForUpdate()->findOrFail($stay->room_id);
            $room->update(['status' => $room->hasBlockingMaintenance() ? 'maintenance' : 'available']);

            // Correction round (audited defect #24): checkout used to only
            // ever update the Stay row, leaving a reservation-based stay's
            // parent Reservation permanently stuck at 'checked_in' forever
            // — its lifecycle was never actually completed. Walk-ins have
            // no reservation_id at all, so this is a no-op for them.
            if ($stay->reservation_id) {
                $reservation = Reservation::lockForUpdate()->find($stay->reservation_id);

                if ($reservation && $reservation->status === 'checked_in') {
                    $reservation->update(['status' => 'checked_out']);
                }
            }

            $arRecord = null;
            if (bccomp((string) $folio->balance, '0.00', 2) > 0) {
                $arRecord = AccountsReceivable::create([
                    'folio_id' => $folio->id,
                    'guest_id' => $stay->guest_id,
                    // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md
                    // §22): when this stay was billed to a company, the
                    // debt rolls up under that company too, so it shows
                    // on the company's running balance across every stay
                    // it was billed for — not just this one guest's.
                    'company_id' => $stay->company_id,
                    'original_amount' => $folio->balance,
                    'amount_outstanding' => $folio->balance,
                    'status' => 'open',
                    'due_date' => now()->addDays(30),
                    'created_by' => $actor->id,
                ]);

                $this->audit->log('accounts_receivable.created', 'finance', $arRecord, null, $arRecord->toArray(), actor: $actor);
            }

            $this->audit->log('checkout.completed', 'front_office', $stay, null, $stay->toArray(), actor: $actor);

            return $stay->fresh(['folio']);
        });
    }
}
