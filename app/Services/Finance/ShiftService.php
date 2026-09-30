<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\Department;
use App\Models\Payment;
use App\Models\Shift;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Shift-based department cash reconciliation (PHASE9_AUDIT_AND_PLAN.md
 * §19) — see the shifts table migration's docblock for the full
 * background on why this exists alongside, not instead of, the
 * whole-hotel daily CashReconciliation/AccountingDay lock.
 *
 * Original scope decision (superseded for five departments — see below):
 * opening/closing a shift did NOT gate whether payments can be recorded
 * (unlike AccountingDayService::assertOpen(), which really does block
 * postings once the day is locked). A shift here was purely a reporting/
 * handover overlay — it tells you what a given work period's cash
 * should have been and what was actually handed in.
 *
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): the owner
 * explicitly asked for the opposite — Front Office, Bar, Restaurant, Bar
 * Outside, and Bucareaux staff now CANNOT record a transaction at all
 * without an open shift for their department. See
 * App\Services\Finance\ShiftGateService for the enforcement (kept in its
 * own small class rather than added here, since ShiftService itself
 * stays purely about the open()/close() lifecycle) and exactly which
 * actions are/aren't gated. Every OTHER department that holds
 * `manage-own-shift` (Cashier -> Finance, Duty Manager -> Management,
 * etc.) keeps the original, purely-informational behavior — nothing about
 * opening/closing a shift changes for them.
 */
class ShiftService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    /**
     * "When a shift is been open we should declare the opening balance, so
     * that the system can properly calculate the expected closing and to
     * know the variance at the end": `$openingCash` is the till's starting
     * float, counted by whoever is opening the shift — see
     * expectedCashFor() for how it folds into the expected-closing figure.
     */
    public function open(Department $department, User $actor, float $openingCash, ?string $notes = null): Shift
    {
        return DB::transaction(function () use ($department, $actor, $openingCash, $notes) {
            if ($openingCash < 0) {
                throw new HmsException('Opening cash cannot be negative.');
            }

            $existing = Shift::where('department_id', $department->id)->where('status', 'open')->lockForUpdate()->first();

            if ($existing) {
                throw new HmsException("{$department->name} already has an open shift (opened by {$existing->openedBy->name} at {$existing->opened_at->format('d M H:i')}). Close it before opening a new one.");
            }

            $shift = Shift::create([
                'shift_number' => 'SH-'.now()->format('Ymd').'-'.strtoupper(Str::random(5)),
                'department_id' => $department->id,
                'opened_by' => $actor->id,
                'opened_at' => now(),
                'opening_cash' => $openingCash,
                'status' => 'open',
                'notes' => $notes,
            ]);

            $this->audit->log('finance.shift_opened', 'finance', $shift, null, $shift->toArray(), actor: $actor);

            return $shift;
        });
    }

    /**
     * Live preview of what a still-open shift's expected cash is, so
     * staff can see it before committing to a declared amount at close.
     *
     * Correction round (audited defect #30): filtered by `shift_id`
     * (stamped explicitly on every payment at creation —
     * PaymentLedgerService::record()) rather than a `recorded_at`
     * timestamp window. The old `whereBetween` approach could misattribute
     * a payment recorded within a few seconds of a shift boundary (a
     * department's shift closing and a new one opening back-to-back), and
     * depended on `closed_at`/`now()` agreeing with whatever moment the
     * payment itself was actually recorded at. `shift_id` is a fact
     * decided once, at the moment the payment was created, never
     * re-derived.
     *
     * "Let the expected balance per the shift be visibly changing as the
     * day and transactions are moving": starts from the declared
     * `opening_cash` float (0 for a shift opened before that column
     * existed — see the migration's docblock) and adds/subtracts every
     * cash payment posted against this shift so far, so calling this
     * mid-shift always reflects transactions up to that exact moment —
     * see ShiftController::expected() for the polled JSON endpoint that
     * exposes this live.
     */
    public function expectedCashFor(Shift $shift): float
    {
        // Direct request ("the owner is suppose to be able to edit all
        // transaction[s]"): a voided payment must not count toward the
        // expected cash figure staff reconcile their drawer against.
        $in = (float) Payment::where('method', 'cash')->where('direction', 'in')
            ->where('shift_id', $shift->id)->whereNull('voided_at')->sum('amount');
        $out = (float) Payment::where('method', 'cash')->where('direction', 'out')
            ->where('shift_id', $shift->id)->whereNull('voided_at')->sum('amount');

        return round((float) ($shift->opening_cash ?? 0) + $in - $out, 2);
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild, PHASE9_AUDIT_AND_PLAN.md
     * §21/§23): "capture and reconcile the payments... per day per
     * shift" — a shift used to only ever show its CASH figure
     * (expectedCashFor() above, still what staff physically count and
     * declare at close — you can't "count" a mobile money balance the
     * same way a cash drawer is counted). This is the missing other
     * half: every payment this department took during the shift,
     * broken down by the real financial account it landed in (Cash,
     * Bank, MTN, Orange…), so a shift handover shows the FULL picture —
     * not just cash — even though only cash gets a physical declared
     * count. A payment recorded before this round's financial_account_id
     * wiring (or through a not-yet-wired entry point) has no account
     * attached; those are grouped under "Unattributed" rather than
     * silently dropped, so nothing this shift actually took goes
     * unaccounted for on the handover.
     *
     * @return \Illuminate\Support\Collection<int, array{label: string, net: float}>
     */
    public function expectedByAccountFor(Shift $shift): \Illuminate\Support\Collection
    {
        // Correction round (audited defect #30): same shift_id-based
        // filtering as expectedCashFor() above, for the same reason. Also
        // excludes voided payments, same reasoning as expectedCashFor().
        return Payment::where('shift_id', $shift->id)
            ->whereNull('voided_at')
            ->with('financialAccount')
            ->get()
            ->groupBy(fn (Payment $payment) => $payment->financial_account_id ?? 'unattributed')
            ->map(function ($payments, $key) {
                $in = (float) $payments->where('direction', 'in')->sum('amount');
                $out = (float) $payments->where('direction', 'out')->sum('amount');

                return [
                    'label' => $key === 'unattributed'
                        ? 'Unattributed (no account recorded)'
                        : ($payments->first()->financialAccount->name ?? 'Deleted account'),
                    'net' => round($in - $out, 2),
                ];
            })
            ->sortBy('label')
            ->values();
    }

    public function close(Shift $shift, float $declaredCash, User $actor, ?string $notes = null): Shift
    {
        return DB::transaction(function () use ($shift, $declaredCash, $actor, $notes) {
            /** @var Shift $locked */
            $locked = Shift::query()->whereKey($shift->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isOpen()) {
                throw new HmsException("This shift is already closed (current status: {$locked->status}).");
            }

            $locked->closed_at = now();
            $expected = $this->expectedCashFor($locked);
            $variance = round($declaredCash - $expected, 2);

            // Direct request ("before the closure any shift if there is
            // any variance, the person in shift must explain with a
            // note"): enforced here, not only in the form — the real gate
            // has to live where every close() call actually goes through,
            // the same reasoning every other business-rule check in this
            // class already follows. A note attached to a shift that
            // balanced exactly is still optional; only an actual
            // discrepancy between what was expected and what was declared
            // requires staff to say why.
            if ($variance !== 0.0 && trim((string) $notes) === '') {
                throw new HmsException(sprintf(
                    'There is a variance of %s XAF between the expected (%s) and declared (%s) cash — please explain it in the notes before this shift can close.',
                    number_format($variance, 0),
                    number_format($expected, 0),
                    number_format($declaredCash, 0),
                ));
            }

            $locked->update([
                'closed_by' => $actor->id,
                'closed_at' => $locked->closed_at,
                'status' => 'closed',
                'expected_cash' => $expected,
                'declared_cash' => $declaredCash,
                'variance' => $variance,
                'notes' => trim(($locked->notes ? $locked->notes.' | ' : '').($notes ?? '')) ?: $locked->notes,
            ]);

            $this->audit->log('finance.shift_closed', 'finance', $locked, null, $locked->fresh()->toArray(), actor: $actor);

            return $locked->fresh();
        });
    }
}
