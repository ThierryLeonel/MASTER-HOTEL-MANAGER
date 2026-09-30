<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\FinancialAccount;
use App\Models\Guest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentLedgerService;
use Database\Seeders\WebsiteBookingSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Reservation lifecycle (spec section 10):
 *   pending -> approved -> confirmed -> checked_in -> checked_out
 *           -> rejected
 *   pending/approved/confirmed -> cancelled
 *   confirmed -> no_show
 *
 * Approval is a hard server-side gate (spec section 22) — creating a
 * reservation never bypasses the pending state regardless of who submits
 * it; only a user holding the `approve-reservation` permission may move
 * it to approved/rejected (enforced in the controller/policy, not here,
 * but this service still records who performed the transition).
 */
class ReservationService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentLedgerService $ledger,
    ) {
    }

    public function create(array $data, User $requestedBy): Reservation
    {
        return DB::transaction(function () use ($data, $requestedBy) {
            $reservation = Reservation::create([
                'reservation_number' => $this->generateNumber(),
                // Direct request ("book multi reservations and multi
                // bookings instead of entering 1 by 1"): an ordinary
                // single-reservation caller never sets this key, so
                // `$data['group_reference'] ?? null` keeps every existing
                // call to create() completely unaffected — only
                // createGroup() below ever passes one. See that method
                // and this column's migration for the full reasoning.
                'group_reference' => $data['group_reference'] ?? null,
                'guest_id' => $data['guest_id'],
                'room_category_id' => $data['room_category_id'],
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'adults' => $data['adults'] ?? 1,
                'children' => $data['children'] ?? 0,
                'status' => 'pending',
                'rate_quoted' => $data['rate_quoted'],
                'deposit_required' => $data['deposit_required'] ?? 0,
                'deposit_paid' => $data['deposit_paid'] ?? 0,
                'requested_by' => $requestedBy->id,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->log('reservation.created', 'front_office', $reservation, null, $reservation->toArray(), actor: $requestedBy);

            return $reservation;
        });
    }

    /**
     * Direct request ("i want to be able to book multi reservations and
     * multi bookings instead of entering 1 by 1"): one guest/party
     * booking several rooms for the same stay dates in a single
     * submission — e.g. a family taking three rooms for the same three
     * nights. Deliberately reuses create() once per room rather than
     * duplicating its logic, so every existing rule (pending-by-default,
     * audit logging, validation) applies identically to each room; the
     * only new behavior is the shared `group_reference` stamped on all of
     * them and the all-or-nothing transaction wrapping the whole batch —
     * if any one room's data were somehow invalid, none of the rooms in
     * this submission are created, rather than leaving a partial party
     * booked. Each room can have its own category and rate (a family
     * might take one Deluxe and two Standard rooms), but every room
     * shares the same guest, dates, and notes — a genuinely different
     * guest or date range is a separate booking, not part of this party.
     *
     * @param  array{guest_id:int, check_in_date:string, check_out_date:string, notes?:string}  $shared
     * @param  array<int, array{room_category_id:int, rate_quoted:float, adults?:int, children?:int, deposit_required?:float}>  $rooms
     * @return \Illuminate\Support\Collection<int, Reservation>
     */
    public function createGroup(array $shared, array $rooms, User $requestedBy): \Illuminate\Support\Collection
    {
        if (count($rooms) < 1) {
            throw new HmsException('At least one room is required to create a booking.');
        }

        return DB::transaction(function () use ($shared, $rooms, $requestedBy) {
            $groupReference = $this->generateGroupReference();

            return collect($rooms)->map(function (array $room) use ($shared, $requestedBy, $groupReference) {
                return $this->create(array_merge($shared, $room, ['group_reference' => $groupReference]), $requestedBy);
            });
        });
    }

    /**
     * Fourth post-9.8 fix round ("update the system such that it can
     * catch reservations from the website too"). Called from the public,
     * unauthenticated booking page — deliberately kept separate from
     * create() rather than overloading it, since the caller here is a
     * guest, not a logged-in staff member: there is no `$requestedBy`
     * User, no known negotiated rate (the room category's own
     * `base_rate` is quoted automatically — staff can decline/adjust at
     * approval time if it's wrong), and the guest is matched by phone to
     * an existing record or created inline, exactly like the walk-in
     * check-in flow already does (CheckInController::walkInStore()).
     * Always lands as `status = 'pending'` — the exact same approval gate
     * as every other reservation, per spec section 22, is completely
     * unaffected; the website is just a new front door onto the same
     * queue a phone or walk-in request already goes through.
     *
     * @param  array{first_name:string, last_name?:string, phone:string, email?:string, room_category_id:int, check_in_date:string, check_out_date:string, adults?:int, children?:int, notes?:string, deposit_proof_path?:string, deposit_claimed_amount?:float, deposit_reference?:string, deposit_claimed_method?:string}  $data
     */
    public function createFromWebsite(array $data): Reservation
    {
        /** @var RoomCategory $category */
        $category = RoomCategory::where('is_active', true)->findOrFail($data['room_category_id']);

        return DB::transaction(function () use ($data, $category) {
            // Production incident, found live (a guest-facing 404 on
            // every website booking submission): this used to be
            // ->firstOrFail(), which throws ModelNotFoundException — and
            // Laravel's own default exception handler renders THAT as a
            // plain HTTP 404, with no hint anywhere that the real cause
            // was a missing system user, not a missing route. Root cause
            // on the live install that hit this: only
            // `db:seed --class=RolesAndPermissionsSeeder` had ever been
            // run there, not the full `db:seed`, so `WebsiteBookingSeeder`
            // (added in a later phase than the original seed) never ran
            // and this account was never created. That's exactly the
            // class of drift a `firstOrFail()` here can never recover
            // from on its own. Self-heals instead, using the identical
            // values WebsiteBookingSeeder::run() creates — so a website
            // submission never 404s for this reason again, regardless of
            // seeding history, and a subsequent real run of the seeder
            // (or a second concurrent request racing this one) is a
            // harmless no-op against the same firstOrCreate lookup.
            $systemUser = User::firstOrCreate(
                ['email' => WebsiteBookingSeeder::EMAIL],
                [
                    'name' => 'Website Booking',
                    'username' => 'website-booking',
                    'auth_type' => 'password_only',
                    'password' => Hash::make(Str::random(64)),
                    'department_id' => null,
                    'is_active' => false,
                    'email_verified_at' => now(),
                ]
            );

            $guest = Guest::where('phone', $data['phone'])->first();
            if (! $guest) {
                $guest = Guest::create([
                    'first_name' => $data['first_name'],
                    'last_name' => $data['last_name'] ?? '',
                    'phone' => $data['phone'],
                    'email' => $data['email'] ?? null,
                    'created_by' => null,
                ]);
            }

            $reservation = Reservation::create([
                'reservation_number' => $this->generateNumber(),
                'guest_id' => $guest->id,
                'room_category_id' => $category->id,
                'check_in_date' => $data['check_in_date'],
                'check_out_date' => $data['check_out_date'],
                'adults' => $data['adults'] ?? 1,
                'children' => $data['children'] ?? 0,
                'status' => 'pending',
                'source' => 'website',
                'rate_quoted' => $category->base_rate,
                'requested_by' => $systemUser->id,
                'deposit_proof_path' => $data['deposit_proof_path'] ?? null,
                'deposit_claimed_amount' => $data['deposit_claimed_amount'] ?? null,
                'deposit_reference' => $data['deposit_reference'] ?? null,
                'deposit_claimed_method' => $data['deposit_claimed_method'] ?? null,
                'notes' => $data['notes'] ?? null,
            ]);

            $this->audit->log('reservation.created_from_website', 'front_office', $reservation, null, $reservation->toArray(), actor: $systemUser);

            return $reservation;
        });
    }

    /**
     * Fourth post-9.8 fix round, follow-up ("let the amount sent be
     * posted to the ledger only when the reservation is approved"): a
     * guest-claimed deposit submitted through the website booking form
     * (§20.4) is now posted to the central ledger automatically, right
     * here, the moment staff approve the reservation — approval *is* the
     * verification step (staff have reviewed the uploaded screenshot and
     * reference before clicking Approve), so this is the one and only
     * place a website-claimed deposit is ever allowed to become a real
     * `payments` row. It only fires when both a claimed amount *and* a
     * claimed method are present — `PaymentLedgerService::record()`
     * requires a real method string, and this project never guesses one
     * (the exact same reasoning §18.3 already established for not
     * guessing MTN vs Orange for `mobile_money`); a reservation with an
     * amount but somehow no method (shouldn't happen — the public form
     * requires both together — but defensive rather than assumed) is
     * left untouched, exactly as before this change: informational only,
     * for a staff member to record manually via the existing
     * `recordDeposit()` action once they've sorted out what actually
     * happened. Posting goes through the same central
     * `PaymentLedgerService::record()` every other payment in this
     * system uses — never a second, competing way to record money — with
     * `sourceModule: 'reservation_deposit'`, the same value
     * `recordDeposit()` itself already uses, so this shows up identically
     * in every report that already knows how to read a reservation
     * deposit. One real behavioral consequence worth stating plainly:
     * because `reservation_deposit` auto-derives the Front Office
     * department (PaymentLedgerService::SOURCE_MODULE_DEPARTMENT_CODES),
     * approving a website reservation that carries a claimed deposit now
     * requires an open Front Office shift, exactly as recording a
     * deposit manually already did (§18.1) — it simply happens at
     * approval time instead of a separate later step. A reservation with
     * no claimed deposit is completely unaffected and needs no open
     * shift to approve, same as always.
     *
     * Concurrency: this method used to check `pending` status *before*
     * opening its transaction and never re-locked the row inside it —
     * this is now checked against a freshly `lockForUpdate()`'d row,
     * inside the transaction, so two near-simultaneous approve() calls on
     * the same reservation can never both pass.
     *
     * Correction round (audited defect #3): approval used to also
     * silently post a website-claimed deposit straight to the central
     * ledger the moment staff clicked Approve — a real double-recording
     * risk, since a staff member who had ALSO manually recorded that same
     * physical deposit via recordDeposit() (for example before realising
     * approval would do it again) ended up with the guest's money counted
     * twice. Approving a reservation no longer touches the ledger at all;
     * verifying and posting a website-claimed deposit is now its own
     * explicit, one-time action — see verifyWebsiteDeposit() below.
     *
     * Correction round (audited defect #5): assigning/reassigning a
     * specific room at approval used to accept *any* room id the client
     * sent with zero server-side validation that it even belonged to the
     * category the guest booked (and is being rated against) — a client
     * could silently move a Standard-rate reservation onto a Suite, or
     * vice-versa, with nothing to stop it. A same-category room is always
     * fine; a genuinely different category now requires the approver to
     * explicitly confirm the override ($confirmCategoryOverride) — never
     * inferred just because a different room id happened to be sent —
     * and the mismatch is recorded on the audit trail. Adjusting
     * rate_quoted for an upgrade/downgrade is a separate, explicit
     * decision (see DiscountApprovalService) — this method never silently
     * changes the price to match the new room.
     */
    public function approve(Reservation $reservation, User $approver, ?int $roomId = null, bool $confirmCategoryOverride = false): Reservation
    {
        return DB::transaction(function () use ($reservation, $approver, $roomId, $confirmCategoryOverride) {
            /** @var Reservation $reservation */
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== 'pending') {
                throw new HmsException("Only a pending reservation can be approved (current status: {$reservation->status}).");
            }

            if ($roomId) {
                /** @var Room $room */
                $room = Room::lockForUpdate()->findOrFail($roomId);

                if (! $room->isAvailableFor($reservation->check_in_date, $reservation->check_out_date, $reservation->id)) {
                    throw new HmsException("Room {$room->room_number} is not available for the requested dates.");
                }

                if ((int) $room->room_category_id !== (int) $reservation->room_category_id && ! $confirmCategoryOverride) {
                    throw new HmsException("Room {$room->room_number} belongs to a different room category than this reservation was booked and rated for. Confirm the category override explicitly to reassign it anyway.");
                }

                $reservation->room_id = $room->id;
            }

            $old = $reservation->toArray();
            $reservation->status = 'approved';
            $reservation->approved_by = $approver->id;
            $reservation->approved_at = now();
            $reservation->save();

            $reservation->refresh();
            $reservation->status = 'confirmed';
            $reservation->save();

            $this->audit->log('reservation.approved', 'front_office', $reservation, $old, $reservation->toArray(), actor: $approver);

            return $reservation;
        });
    }

    /**
     * Correction round (audited defect #3, feature companion to
     * approve() above): the ONLY place a website guest's claimed deposit
     * (deposit_claimed_amount/deposit_claimed_method, submitted through
     * the public booking form) is ever allowed to become a real `payments`
     * ledger row. Deliberately separate from both approve() (which no
     * longer touches the ledger) and recordDeposit() (which is for
     * ordinary manual/additional instalments) — this is specifically the
     * one-time act of a staff member reviewing the uploaded evidence
     * (deposit_proof_path/deposit_reference) and confirming the claimed
     * amount really arrived. Guarded by deposit_claim_verified_at, checked
     * and set inside the same row lock, so it can only ever run once per
     * reservation — a second attempt (retry, double click, or a staff
     * member re-opening the same screen) always finds the claim already
     * verified and fails cleanly instead of posting the money twice.
     *
     * Audited defect #31: a website mobile-money deposit still needs a
     * real, active FinancialAccount behind it — exactly like every other
     * receipt in this system — so the caller must supply one; it is never
     * guessed from the claimed method string.
     */
    public function verifyWebsiteDeposit(Reservation $reservation, User $verifier, int $financialAccountId, ?string $notes = null): Reservation
    {
        return DB::transaction(function () use ($reservation, $verifier, $financialAccountId, $notes) {
            /** @var Reservation $reservation */
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if (in_array($reservation->status, ['cancelled', 'rejected'], true)) {
                throw new HmsException("Cannot verify a deposit for a {$reservation->status} reservation.");
            }

            if ($reservation->deposit_claim_verified_at !== null) {
                throw new HmsException('This website-claimed deposit has already been verified and recorded.');
            }

            $claimedAmount = (float) $reservation->deposit_claimed_amount;
            if ($claimedAmount <= 0 || ! $reservation->deposit_claimed_method) {
                throw new HmsException('This reservation has no claimed website deposit to verify.');
            }

            /** @var FinancialAccount $account */
            $account = FinancialAccount::where('is_active', true)->findOrFail($financialAccountId);

            $old = $reservation->only(['deposit_paid', 'deposit_claim_verified_at']);

            $this->ledger->record(
                amount: abs($claimedAmount),
                method: $account->paymentMethod(),
                sourceModule: 'reservation_deposit',
                recordedBy: $verifier,
                sourceReference: "Reservation {$reservation->reservation_number}",
                guestId: $reservation->guest_id,
                notes: trim(($notes ?: 'Guest-claimed deposit from website booking, verified by staff')
                    .($reservation->deposit_reference ? " (ref: {$reservation->deposit_reference})" : '')),
                financialAccountId: $account->id,
            );

            $reservation->update([
                'deposit_paid' => bcadd((string) $reservation->deposit_paid, (string) abs($claimedAmount), 2),
                'deposit_claim_verified_at' => now(),
                'deposit_claim_verified_by' => $verifier->id,
            ]);

            $this->audit->log('reservation.deposit_claim_verified', 'front_office', $reservation, $old, $reservation->only(['deposit_paid', 'deposit_claim_verified_at']), actor: $verifier);

            return $reservation->fresh();
        });
    }

    /**
     * Correction round (audited defect #7): reject/cancel/markNoShow used
     * to check status against the caller-supplied, unlocked instance with
     * no DB::transaction at all — two concurrent actions on the same
     * reservation (e.g. a duplicate form submission, or a reject racing an
     * approve) could both pass their pre-check and leave the row in an
     * inconsistent state. All three now lock the row and re-check status
     * inside a transaction, matching every other lifecycle transition in
     * this class.
     */
    public function reject(Reservation $reservation, User $approver, string $reason): Reservation
    {
        return DB::transaction(function () use ($reservation, $approver, $reason) {
            /** @var Reservation $reservation */
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== 'pending') {
                throw new HmsException("Only a pending reservation can be rejected (current status: {$reservation->status}).");
            }

            $old = $reservation->toArray();
            $reservation->update([
                'status' => 'rejected',
                'approved_by' => $approver->id,
                'approved_at' => now(),
                'rejection_reason' => $reason,
            ]);

            $this->audit->log('reservation.rejected', 'front_office', $reservation, $old, $reservation->toArray(), $reason, $approver);

            return $reservation;
        });
    }

    public function cancel(Reservation $reservation, User $actor, string $reason): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor, $reason) {
            /** @var Reservation $reservation */
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if (! in_array($reservation->status, ['pending', 'approved', 'confirmed'], true)) {
                throw new HmsException("Reservation cannot be cancelled from status: {$reservation->status}.");
            }

            $old = $reservation->toArray();
            $reservation->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
            $this->audit->log('reservation.cancelled', 'front_office', $reservation, $old, $reservation->toArray(), $reason, $actor);

            return $reservation;
        });
    }

    public function markNoShow(Reservation $reservation, User $actor): Reservation
    {
        return DB::transaction(function () use ($reservation, $actor) {
            /** @var Reservation $reservation */
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if ($reservation->status !== 'confirmed') {
                throw new HmsException("Only a confirmed reservation can be marked no-show (current status: {$reservation->status}).");
            }

            if (today()->lt($reservation->check_in_date)) {
                throw new HmsException('Cannot mark no-show before the check-in date has arrived.');
            }

            $old = $reservation->toArray();
            $reservation->update(['status' => 'no_show']);
            $this->audit->log('reservation.no_show', 'front_office', $reservation, $old, $reservation->toArray(), actor: $actor);

            return $reservation;
        });
    }

    /**
     * Correction round (audited defect #8): a cancelled/rejected
     * reservation's deposit used to just sit on deposit_paid forever with
     * no recorded outcome — no refund, no forfeiture, no transfer, and no
     * audit trail of which one actually happened. This is the one-time,
     * audited decision point for that money, guarded by deposit_disposition
     * (checked and set inside the same row lock, so it can only run once):
     *   - 'refunded': actually pays the money back out through the central
     *     ledger (direction 'out') from a real, active FinancialAccount —
     *     never invented or guessed.
     *   - 'forfeited': the hotel keeps the deposit (its own already-posted
     *     'in' payment stands as-is); no further ledger movement.
     *   - 'transferred': moves the credit onto a different, still-live
     *     reservation's deposit_paid — a bookkeeping move between two
     *     reservations' balances, not a new cash receipt, so it does NOT
     *     post a second 'in' payment (that would double the money that
     *     already came in once).
     */
    public function disposeDeposit(
        Reservation $reservation,
        string $disposition,
        User $actor,
        ?string $notes = null,
        ?int $financialAccountId = null,
        ?Reservation $targetReservation = null,
    ): Reservation {
        if (! in_array($disposition, ['refunded', 'forfeited', 'transferred'], true)) {
            throw new HmsException("Invalid deposit disposition: {$disposition}.");
        }

        return DB::transaction(function () use ($reservation, $disposition, $actor, $notes, $financialAccountId, $targetReservation) {
            /** @var Reservation $reservation */
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if (! in_array($reservation->status, ['cancelled', 'rejected'], true)) {
                throw new HmsException('A deposit disposition can only be recorded for a cancelled or rejected reservation.');
            }

            if (bccomp((string) $reservation->deposit_paid, '0.00', 2) <= 0) {
                throw new HmsException('This reservation has no recorded deposit to dispose of.');
            }

            if ($reservation->deposit_disposition !== null) {
                throw new HmsException("This deposit's disposition has already been recorded ({$reservation->deposit_disposition}).");
            }

            $amount = (float) $reservation->deposit_paid;
            $old = $reservation->only(['deposit_disposition']);
            $targetReservationId = null;

            if ($disposition === 'refunded') {
                if (! $financialAccountId) {
                    throw new HmsException('A financial account is required to refund a deposit.');
                }

                /** @var FinancialAccount $account */
                $account = FinancialAccount::where('is_active', true)->findOrFail($financialAccountId);

                $this->ledger->record(
                    amount: $amount,
                    method: $account->paymentMethod(),
                    sourceModule: 'reservation_deposit_refund',
                    recordedBy: $actor,
                    sourceReference: "Reservation {$reservation->reservation_number}",
                    guestId: $reservation->guest_id,
                    direction: 'out',
                    notes: $notes ?: "Deposit refund for cancelled/rejected reservation {$reservation->reservation_number}",
                    financialAccountId: $account->id,
                );
            }

            if ($disposition === 'transferred') {
                if (! $targetReservation) {
                    throw new HmsException('A target reservation is required to transfer a deposit.');
                }

                /** @var Reservation $target */
                $target = Reservation::lockForUpdate()->findOrFail($targetReservation->id);

                if (! in_array($target->status, ['pending', 'approved', 'confirmed'], true)) {
                    throw new HmsException("Cannot transfer a deposit onto a reservation with status: {$target->status}.");
                }

                $targetOld = $target->only(['deposit_paid']);
                $target->update([
                    'deposit_paid' => bcadd((string) $target->deposit_paid, (string) $amount, 2),
                ]);

                $this->audit->log(
                    'reservation.deposit_transferred_in',
                    'front_office',
                    $target,
                    $targetOld,
                    $target->only(['deposit_paid']),
                    "Transferred from reservation {$reservation->reservation_number}",
                    $actor,
                );

                $targetReservationId = $target->id;
            }

            $reservation->update([
                'deposit_disposition' => $disposition,
                'deposit_disposition_by' => $actor->id,
                'deposit_disposition_at' => now(),
                'deposit_disposition_notes' => $notes,
                'deposit_disposition_financial_account_id' => $disposition === 'refunded' ? $financialAccountId : null,
                'deposit_disposition_target_reservation_id' => $targetReservationId,
            ]);

            $this->audit->log(
                'reservation.deposit_disposed',
                'front_office',
                $reservation,
                $old,
                $reservation->only(['deposit_disposition', 'deposit_disposition_notes']),
                $notes,
                $actor,
            );

            return $reservation->fresh();
        });
    }

    /**
     * "the client can also make advance payment for the lodging... the
     * advance on reservation are also payments" (user's own words) — a
     * reservation deposit is real cash received now, so it goes through
     * the same central ledger as every other payment
     * (PaymentLedgerService::record()), not just a cosmetic number on the
     * reservation. `deposit_paid` here is a running total of everything
     * recorded this way for this reservation (a guest may pay a deposit
     * in more than one instalment); it's applied as a folio credit at
     * check-in by CheckInService via FolioService::applyAdvanceCredit(),
     * which does NOT re-record it in the ledger a second time.
     */
    /**
     * Correction round (audited defect #32): $idempotencyKey, when
     * supplied (a UUID rendered once into the deposit form), protects a
     * retried/double-submitted deposit — checked here, before
     * deposit_paid is incremented, since checking only inside
     * PaymentLedgerService::record() would dedupe the central ledger row
     * but this method would still double-increment deposit_paid on every
     * retry. A genuinely separate instalment simply uses its own distinct
     * key (a fresh page load), so this never blocks a real second
     * deposit.
     *
     * Second correction (found via
     * tests/Feature/Concurrency/RealDatabaseConcurrencyTest.php's real,
     * two-process race — exactly the class of bug a sequential test
     * cannot see): the check above runs BEFORE this method's own
     * transaction/lock even opens, so two genuinely concurrent requests
     * carrying the SAME key can both pass it and both enter this
     * transaction. From there, `Reservation::lockForUpdate()` correctly
     * SERIALIZES them (the second blocks until the first commits), but
     * that alone isn't enough: the second process, once unblocked, used
     * to call the ledger unconditionally and then unconditionally add
     * $amount to deposit_paid again — even though
     * `PaymentLedgerService::record()` had, by then, correctly recognised
     * the key as a duplicate and hadn't created a second Payment row. The
     * ledger stayed correct (one Payment); `deposit_paid` did not (it was
     * bumped twice for one real deposit). `Payment::wasRecentlyCreated`
     * — true only for the row this exact call just inserted, false for
     * one handed back because the key already existed — is what the
     * increment below is now conditioned on, so a lock-serialized
     * duplicate is a genuine no-op here, not just at the ledger layer.
     */
    public function recordDeposit(Reservation $reservation, float $amount, string $method, User $actor, ?string $notes = null, ?int $financialAccountId = null, ?string $idempotencyKey = null): Reservation
    {
        if ($amount <= 0) {
            throw new HmsException('Deposit amount must be greater than zero.');
        }

        if ($idempotencyKey !== null && \App\Models\Payment::where('idempotency_key', $idempotencyKey)->exists()) {
            return $reservation->fresh();
        }

        return DB::transaction(function () use ($reservation, $amount, $method, $actor, $notes, $financialAccountId, $idempotencyKey) {
            // Correction round (audited defect #2): the status check used
            // to run BEFORE this transaction, against the caller-supplied,
            // unlocked $reservation — a reservation could be cancelled or
            // moved to checked_in by a concurrent request in the gap
            // between that check and this lock, and a deposit would still
            // be recorded (and posted to the ledger) against it. The
            // eligible-status check is now re-run here, against the freshly
            // lockForUpdate()'d row, so it always reflects the true current
            // state at the moment the money is actually posted.
            /** @var Reservation $reservation */
            $reservation = Reservation::lockForUpdate()->findOrFail($reservation->id);

            if (! in_array($reservation->status, ['pending', 'approved', 'confirmed'], true)) {
                throw new HmsException("A deposit can only be recorded while a reservation is pending, approved, or confirmed (current status: {$reservation->status}).");
            }

            $old = $reservation->only(['deposit_paid']);

            $payment = $this->ledger->record(
                amount: abs($amount),
                method: $method,
                sourceModule: 'reservation_deposit',
                recordedBy: $actor,
                sourceReference: "Reservation {$reservation->reservation_number}",
                guestId: $reservation->guest_id,
                notes: $notes ?? "Advance/deposit for reservation {$reservation->reservation_number}",
                financialAccountId: $financialAccountId,
                idempotencyKey: $idempotencyKey,
            );

            // Only a Payment this call genuinely just inserted represents
            // real new money — one handed back because $idempotencyKey
            // already existed (a lock-serialized duplicate submission,
            // see this method's docblock) must not increment deposit_paid
            // a second time, even though it's harmless to have called the
            // ledger again.
            if ($payment->wasRecentlyCreated) {
                $reservation->update([
                    'deposit_paid' => bcadd((string) $reservation->deposit_paid, (string) abs($amount), 2),
                ]);

                $this->audit->log('reservation.deposit_recorded', 'front_office', $reservation, $old, $reservation->only(['deposit_paid']), actor: $actor);
            }

            return $reservation;
        });
    }

    private function generateNumber(): string
    {
        return 'RSV-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }

    /**
     * A short, human-showable tag linking every room in one multi-room
     * booking — deliberately distinct from a reservation_number (which
     * stays unique per room) so staff can recognise "these came from the
     * same submission" at a glance (e.g. on the Reservations list).
     */
    private function generateGroupReference(): string
    {
        return 'GRP-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
