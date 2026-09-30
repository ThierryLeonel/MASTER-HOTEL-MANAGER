<?php

namespace App\Services\FrontOffice;

use App\Exceptions\HmsException;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentLedgerService;
use Illuminate\Support\Facades\DB;

/**
 * The only writer of folio_transactions. Charges/payments/refunds are
 * always inserted, never edited; the folio's denormalized `balance` is
 * updated in the same DB transaction so it can never drift from the
 * ledger it caches. Voiding a transaction inserts a reversing entry
 * rather than deleting history (spec section 12).
 */
class FolioService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly PaymentLedgerService $ledger,
    ) {
    }

    public function postCharge(Folio $folio, string $type, float $amount, string $description, User $actor, string $sourceModule = 'front_office', ?string $reference = null): FolioTransaction
    {
        if (! in_array($type, FolioTransaction::postableChargeTypeCodes(), true)) {
            throw new HmsException("Invalid charge type: {$type}");
        }

        // "Special guest lodging" (added at the user's request — see
        // PHASE9_AUDIT_AND_PLAN.md §9): a stay marked complimentary has
        // its ROOM charge waived specifically — every other charge type
        // still posts normally, so only `room_charge` is blocked here.
        // This guard only ever fires on a MANUAL post (this front desk
        // "post charge" form, or a direct API call) — the one place this
        // codebase posts a room_charge automatically, CheckOutService's
        // optional final-night charge, checks is_complimentary itself
        // and simply skips posting rather than ever reaching here, since
        // checkout must never be blocked by an exception (spec section
        // 10's "an unpaid balance never blocks checkout" applies equally
        // to "an unposted comp charge never blocks checkout" either).
        if ($type === 'room_charge' && $folio->stay?->is_complimentary) {
            throw new HmsException("This stay is marked complimentary — its room charge is waived. (Reason: {$folio->stay->complimentary_reason}) Other charge types still post normally.");
        }

        return $this->post($folio, $type, abs($amount), $description, $actor, $sourceModule, $reference);
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
     * §21/§22): `$financialAccountId` — which real account (Cash, Bank,
     * MTN, Orange…) this payment actually landed in — is now optional but
     * threaded straight through to the central ledger, so a day close can
     * reconcile it against that account instead of lumping every non-cash
     * payment together. Optional (not required) so existing callers that
     * haven't been updated to collect it yet keep working exactly as
     * before — see PaymentLedgerService::record()'s own docblock on why an
     * unattributed payment is tolerated rather than blocked.
     */
    /**
     * Correction round (audited defect #32): $idempotencyKey, when a
     * caller supplies one (a UUID rendered once into the payment form, so
     * a double-click/network retry resubmits the same key), is forwarded
     * straight to PaymentLedgerService::record() — see that method's own
     * docblock for how a repeat submission returns the existing Payment
     * rather than creating a duplicate. A caller that never passes one
     * (unchanged, still the default) behaves exactly as before.
     *
     * Second correction (found via
     * tests/Feature/Concurrency/RealDatabaseConcurrencyTest.php's real,
     * two-process race — the same class of bug fixed in
     * ReservationService::recordDeposit(), and, like that one, invisible
     * to any test running a single connection): the idempotency recheck
     * below used to run BEFORE this folio's row lock was acquired (the
     * lock only happened later, inside post()). Two genuinely concurrent
     * postPayment() calls carrying the SAME key could both pass that
     * check before either lock was held, then both call post() — which
     * DOES serialize them on the folio row, but only after each had
     * already decided, unlocked, to proceed. The loser would still
     * create its own, genuinely duplicate FolioTransaction and
     * double-decrement the real folio balance, even though
     * PaymentLedgerService::record() correctly kept the central ledger
     * to one Payment row. Locking the folio HERE — before the check,
     * not after — closes that: the second call blocks until the first
     * commits, then re-reads a Payment that now exists and returns the
     * first call's transaction instead of creating a second one. (The
     * re-lock inside post() a few lines down is a harmless no-op — a
     * transaction re-locking a row it already holds never blocks
     * itself.)
     */
    public function postPayment(Folio $folio, float $amount, string $method, User $actor, string $sourceModule = 'front_office', ?string $reference = null, ?int $financialAccountId = null, ?string $idempotencyKey = null): FolioTransaction
    {
        return DB::transaction(function () use ($folio, $amount, $method, $actor, $sourceModule, $reference, $financialAccountId, $idempotencyKey) {
            Folio::lockForUpdate()->findOrFail($folio->id);

            // Correction round (audited defect #32): the idempotency check
            // has to happen HERE, under the lock just taken above, before
            // the folio_transaction is created — checking only inside
            // PaymentLedgerService::record() would correctly dedupe the
            // central ledger row on a retry, but this method would still
            // insert a SECOND folio_transaction (and double-increment the
            // folio balance) every time, since post() itself has no
            // idempotency awareness. A retry (sequential OR, now, truly
            // concurrent) with the same key returns the exact transaction
            // the first call created.
            if ($idempotencyKey !== null) {
                $existingPayment = \App\Models\Payment::where('idempotency_key', $idempotencyKey)->first();

                if ($existingPayment && $existingPayment->reference instanceof FolioTransaction) {
                    return $existingPayment->reference;
                }
            }

            $transaction = $this->post($folio, 'payment', -abs($amount), "Payment ({$method})", $actor, $sourceModule, $reference, $method);

            // Spec section 16: every payment, from every module, also
            // lands in the single central ledger — a folio settlement is
            // no exception.
            $this->ledger->record(
                amount: abs($amount),
                method: $method,
                sourceModule: $sourceModule,
                recordedBy: $actor,
                sourceReference: "Folio #{$folio->id}",
                reference: $transaction,
                guestId: $folio->stay?->guest_id,
                financialAccountId: $financialAccountId,
                idempotencyKey: $idempotencyKey,
            );

            return $transaction;
        });
    }

    /**
     * Applies cash that was already recorded in the central payments
     * ledger elsewhere — currently: a reservation deposit taken via
     * ReservationService::recordDeposit() before check-in — as a credit
     * against a folio. Deliberately distinct from postPayment(): that
     * method ALWAYS also calls PaymentLedgerService::record(), which
     * would double-count this money in the `payments` table since it was
     * already ledgered once, at the moment it was actually received.
     * "the advance on reservation are also payments" (user's own words)
     * is honoured by ledgering it once, at deposit time — this method is
     * only about reflecting that same money against the guest's folio.
     */
    public function applyAdvanceCredit(Folio $folio, float $amount, string $description, User $actor, string $sourceModule = 'front_office', ?string $reference = null): FolioTransaction
    {
        return $this->post($folio, 'payment', -abs($amount), $description, $actor, $sourceModule, $reference);
    }

    public function postRefund(Folio $folio, float $amount, string $reason, User $actor, string $sourceModule = 'finance', string $method = 'cash', ?int $financialAccountId = null): FolioTransaction
    {
        // Refunds require approval upstream (RefundService, a Finance-phase
        // component not yet built) before this is called.
        return DB::transaction(function () use ($folio, $amount, $reason, $actor, $sourceModule, $method, $financialAccountId) {
            $transaction = $this->post($folio, 'refund', abs($amount), "Refund: {$reason}", $actor, $sourceModule);

            $this->ledger->record(
                amount: abs($amount),
                method: $method,
                sourceModule: $sourceModule,
                recordedBy: $actor,
                sourceReference: "Folio #{$folio->id}",
                reference: $transaction,
                guestId: $folio->stay?->guest_id,
                direction: 'out',
                notes: $reason,
                financialAccountId: $financialAccountId,
            );

            return $transaction;
        });
    }

    /**
     * Correction round (audited defect #17): distinguishes an actual cash
     * refund (postRefund(), money leaving the hotel) from a guest paying
     * off an existing outstanding balance — including after their folio
     * has already closed at checkout (audited defect #21, "support
     * guest-credit settlement after checkout"). Posts through the same
     * central PaymentLedgerService every other receipt does.
     */
    public function postCreditSettlement(Folio $folio, float $amount, string $method, User $actor, string $sourceModule = 'front_office', ?int $financialAccountId = null): FolioTransaction
    {
        return DB::transaction(function () use ($folio, $amount, $method, $actor, $sourceModule, $financialAccountId) {
            $transaction = $this->post($folio, 'credit_settlement', -abs($amount), "Debt settlement ({$method})", $actor, $sourceModule, null, $method);

            $this->ledger->record(
                amount: abs($amount),
                method: $method,
                sourceModule: $sourceModule,
                recordedBy: $actor,
                sourceReference: "Folio #{$folio->id} debt settlement",
                reference: $transaction,
                guestId: $folio->stay?->guest_id,
                financialAccountId: $financialAccountId,
            );

            return $transaction;
        });
    }

    /**
     * Correction round (audited defect #17): a PAYMENT CORRECTION (the
     * guest's money was recorded against the wrong thing, or for the
     * wrong amount — a data-entry fix, not a cash refund and not a
     * charge cancellation) reverses the original folio transaction AND
     * posts a matching reversing entry to the central Payment ledger,
     * which void()'s reversing 'adjustment' row never touched for
     * payment-type transactions before this — so a voided payment used to
     * leave the money looking like it was still received in
     * PaymentLedgerService's own reports even after being reversed here.
     */
    public function correctPayment(FolioTransaction $original, string $reason, User $actor): FolioTransaction
    {
        if (! in_array($original->type, ['payment', 'credit_settlement'], true)) {
            throw new HmsException('correctPayment() may only be used on a payment or credit-settlement transaction — use void() for a charge.');
        }

        return DB::transaction(function () use ($original, $reason, $actor) {
            $reversal = $this->void($original, $reason, $actor);

            $this->ledger->record(
                amount: abs((float) $original->amount),
                method: $original->payment_method ?? 'cash',
                sourceModule: 'finance_correction',
                recordedBy: $actor,
                sourceReference: "Correction of folio transaction #{$original->id}",
                reference: $reversal,
                guestId: $original->folio->stay?->guest_id,
                direction: 'out',
                notes: "Payment correction: {$reason}",
            );

            return $reversal;
        });
    }

    /**
     * Void a posted transaction by inserting a reversing adjustment —
     * the original row is never edited or deleted.
     *
     * Correction round (audited defect #18, sharing its root cause with
     * defect #7 elsewhere in this codebase): isVoided() used to be
     * checked against the caller-supplied, unlocked $original BEFORE this
     * transaction opened — two concurrent void() calls on the same
     * transaction could both pass that check and both insert a reversing
     * adjustment, reversing the same charge/payment twice. Re-checked here
     * against a freshly lockForUpdate()'d row inside the transaction.
     */
    public function void(FolioTransaction $original, string $reason, User $actor): FolioTransaction
    {
        return DB::transaction(function () use ($original, $reason, $actor) {
            /** @var FolioTransaction $original */
            $original = FolioTransaction::query()->whereKey($original->getKey())->lockForUpdate()->firstOrFail();

            if ($original->isVoided()) {
                throw new HmsException('Transaction already voided.');
            }

            $original->update(['voided_at' => now(), 'void_reason' => $reason]);

            $reversal = $this->post(
                $original->folio,
                'adjustment',
                -$original->amount,
                "Reversal of transaction #{$original->id}: {$reason}",
                $actor,
                'finance',
            );
            $reversal->update(['reverses_transaction_id' => $original->id]);

            $this->audit->log('folio.transaction_voided', 'finance', $original, null, $reversal->toArray(), $reason, $actor);

            return $reversal;
        });
    }

    private function post(Folio $folio, string $type, float $signedAmount, string $description, User $actor, string $sourceModule, ?string $reference = null, ?string $paymentMethod = null): FolioTransaction
    {
        return DB::transaction(function () use ($folio, $type, $signedAmount, $description, $actor, $sourceModule, $reference, $paymentMethod) {
            // Correction round (audited defects #15 and #19): the
            // open-folio check used to run BEFORE this transaction/lock,
            // against the caller-supplied, unlocked $folio — a race
            // against CheckOutService closing this same folio concurrently
            // could let a charge post a moment after (or during) checkout,
            // silently landing on what the guest was just told was a
            // settled, closed bill. Re-checked here, against a freshly
            // locked row. This single choke point is the fix for BOTH the
            // Folio-group's general "recheck folio status after lock"
            // requirement (#19) and the POS-group's specific "coordinate
            // room-charge posting with checkout so charges can't silently
            // post to a closed folio" requirement (#15) — every charge
            // path, POS-originated or otherwise, funnels through post().
            //
            // Correction round (audited defect #21): 'refund' and
            // 'credit_settlement' are deliberately exempt from the
            // closed-folio guard — a refund can be approved, and a guest
            // can settle a remaining debt, after checkout has already
            // closed the folio; every charge type and an ordinary
            // 'payment' still require the folio to still be open.
            $folio = Folio::lockForUpdate()->findOrFail($folio->id);

            if ($folio->status !== 'open' && ! in_array($type, ['refund', 'credit_settlement'], true)) {
                throw new HmsException('Cannot post to a closed folio.');
            }

            $transaction = FolioTransaction::create([
                'folio_id' => $folio->id,
                'type' => $type,
                'amount' => $signedAmount,
                'description' => $description,
                'source_module' => $sourceModule,
                'reference' => $reference,
                'payment_method' => $paymentMethod,
                'user_id' => $actor->id,
                'transaction_date' => now(),
            ]);

            $folio->increment('balance', $signedAmount);

            $this->audit->log("folio.{$type}_posted", 'front_office', $transaction, null, $transaction->toArray(), actor: $actor);

            return $transaction;
        });
    }
}
