<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\CashReconciliation;
use App\Models\Payment;
use App\Models\User;
use App\Services\AuditLogger;
use Illuminate\Support\Carbon;

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

        $in = (float) Payment::where('method', 'cash')->where('direction', 'in')
            ->whereBetween('recorded_at', [$start, $end])->sum('amount');
        $out = (float) Payment::where('method', 'cash')->where('direction', 'out')
            ->whereBetween('recorded_at', [$start, $end])->sum('amount');

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
    }
}
