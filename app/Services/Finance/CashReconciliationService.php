<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\CashReconciliation;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Compares the drawer's physical cash count against what the central
 * payment ledger says cash movement for the day actually was.
 * `expected_amount` is always computed live from `payments` — never
 * hand entered — so a reconciliation is a genuine check, not a form
 * that could be filled in to agree with itself.
 */
class CashReconciliationService
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function expectedCashForToday(): float
    {
        return $this->expectedCashFor(Carbon::today());
    }

    public function expectedCashFor(Carbon $date): float
    {
        $start = $date->copy()->startOfDay();
        $end = $date->copy()->endOfDay();

        // Direct request ("the owner is suppose to be able to edit all
        // transaction[s]"): a voided payment must not count toward this
        // whole-hotel cash figure either.
        $in = (float) Payment::where('method', 'cash')->where('direction', 'in')
            ->whereNull('voided_at')->whereBetween('recorded_at', [$start, $end])->sum('amount');
        $out = (float) Payment::where('method', 'cash')->where('direction', 'out')
            ->whereNull('voided_at')->whereBetween('recorded_at', [$start, $end])->sum('amount');

        return round($in - $out, 2);
    }

    public function record(float $countedAmount, User $actor, ?string $notes = null): CashReconciliation
    {
        $today = Carbon::today();

        if (CashReconciliation::where('business_date', $today->toDateString())->exists()) {
            throw new HmsException("Today's cash reconciliation has already been recorded.");
        }

        $expected = $this->expectedCashForToday();
        $variance = round($countedAmount - $expected, 2);

        // Correction round (code-review pass, Nov 2026): the "already
        // recorded" check above is an application-level guard only — two
        // concurrent submissions could both pass it before either inserts.
        // A unique index on business_date (see this round's migration)
        // makes the database the real source of truth for "once per day";
        // wrapping in a transaction and catching the resulting constraint
        // violation turns that race, if it ever happens, into the exact
        // same friendly error the normal path already throws above,
        // instead of an unhandled 500.
        try {
            return DB::transaction(function () use ($today, $expected, $countedAmount, $variance, $actor, $notes) {
                $reconciliation = CashReconciliation::create([
                    'business_date' => $today->toDateString(),
                    'expected_amount' => $expected,
                    'counted_amount' => $countedAmount,
                    'variance' => $variance,
                    'recorded_by' => $actor->id,
                    'notes' => $notes,
                ]);

                $this->audit->log('finance.cash_reconciliation_recorded', 'finance', $reconciliation, null, $reconciliation->toArray(), actor: $actor);

                return $reconciliation;
            });
        } catch (QueryException $e) {
            if ((int) $e->getCode() === 23000) {
                throw new HmsException("Today's cash reconciliation has already been recorded.");
            }

            throw $e;
        }
    }
}
