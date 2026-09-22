<?php

namespace App\Services\Integrity;

use App\Models\AccountsReceivable;
use App\Models\Folio;
use App\Models\PurchaseOrder;
use App\Models\Room;
use App\Models\Stay;
use App\Models\StockBatch;
use App\Models\SyncConflict;

/**
 * Phase 9.6 (PHASE9_AUDIT_AND_PLAN.md §3.7): the Data Integrity Checker
 * the master prompt calls for. Every check here is READ-ONLY — it never
 * writes a "fix" on its own, because guessing at a correction (e.g.
 * silently overwriting a folio's balance to match its ledger) could
 * paper over a real bug instead of surfacing it; a human should decide
 * what a mismatch actually means before anything is changed.
 *
 * Each check queries the live tables directly rather than trusting any
 * denormalized/cached total — consistent with this project's "a report
 * is never a stored, separately-maintained total" principle (see
 * FinancialReportService's docblock) applied to data integrity instead
 * of reporting. Where a model already exposes the exact comparison this
 * needs (Folio::recomputeBalance(), PurchaseOrder::isFullyReceived()),
 * it is reused rather than reimplemented, so this checker can never
 * disagree with the business logic it's checking.
 */
class DataIntegrityCheckerService
{
    /**
     * How many days an unresolved sync conflict is allowed to sit before
     * `noStaleSyncConflicts()` below flags it — see that method's own
     * docblock for why 7.
     */
    private const STALE_CONFLICT_DAYS = 7;

    /**
     * @return array<int, array{key: string, label: string, passed: bool, issue_count: int, issues: array<int, string>}>
     */
    public function run(): array
    {
        return [
            $this->folioBalancesMatchLedger(),
            $this->accountsReceivableTiesToRealFolio(),
            $this->accountsReceivableOutstandingWithinOriginal(),
            $this->staysHaveAFolio(),
            $this->roomOccupancyMatchesActiveStays(),
            $this->noNegativeStockBatches(),
            $this->receivedPurchaseOrdersAreFullyReceived(),
            $this->noStaleSyncConflicts(),
        ];
    }

    public function hasFailures(array $results): bool
    {
        foreach ($results as $result) {
            if (! $result['passed']) {
                return true;
            }
        }

        return false;
    }

    /**
     * The denormalized `folios.balance` column must always equal the sum
     * of EVERY one of its transactions (including voided originals and
     * their reversing 'adjustment' rows — a genuinely voided-then-reversed
     * pair nets to zero once both halves are counted, exactly matching how
     * `balance` itself already accumulates on every post()/void()).
     *
     * Correction round (audited defect #16): this delegates to
     * Folio::recomputeBalance(), which used to filter out voided originals
     * while still counting their always-live reversal — permanently
     * drifting further from `balance` with every void ever performed. Now
     * that recomputeBalance() sums every row unfiltered, this integrity
     * check automatically agrees with the corrected semantics without
     * needing its own separate fix.
     */
    private function folioBalancesMatchLedger(): array
    {
        $issues = [];

        Folio::query()->orderBy('id')->chunk(200, function ($folios) use (&$issues) {
            foreach ($folios as $folio) {
                $computed = round((float) $folio->recomputeBalance(), 2);
                $stored = round((float) $folio->balance, 2);

                if (abs($computed - $stored) > 0.01) {
                    $issues[] = "Folio #{$folio->id}: stored balance {$stored} does not match its transaction ledger total {$computed}.";
                }
            }
        });

        return $this->result('folio_balances', 'Folio balances match their transaction ledger', $issues);
    }

    /**
     * Every AccountsReceivable row must point at a folio that actually
     * exists — a real FK enforces this on MySQL/production, but this
     * check catches it independently (e.g. after a manual DB edit, or on
     * a connection where FK enforcement is off) rather than assuming the
     * constraint is always active.
     */
    private function accountsReceivableTiesToRealFolio(): array
    {
        $issues = [];

        AccountsReceivable::with('folio')->orderBy('id')->get()->each(function (AccountsReceivable $ar) use (&$issues) {
            if (! $ar->folio) {
                $issues[] = "AccountsReceivable #{$ar->id} references folio_id {$ar->folio_id}, which does not exist.";
            }
        });

        return $this->result('ar_ties_to_folio', 'Every Accounts Receivable record ties to a real folio', $issues);
    }

    /**
     * An outstanding balance greater than what was originally owed is
     * only possible through a bug (e.g. double-crediting a partial
     * payment) — never a legitimate business state.
     */
    private function accountsReceivableOutstandingWithinOriginal(): array
    {
        $issues = [];

        AccountsReceivable::whereColumn('amount_outstanding', '>', 'original_amount')
            ->orderBy('id')->get()
            ->each(function (AccountsReceivable $ar) use (&$issues) {
                $issues[] = "AccountsReceivable #{$ar->id}: amount_outstanding ({$ar->amount_outstanding}) exceeds original_amount ({$ar->original_amount}).";
            });

        return $this->result('ar_outstanding_within_original', 'No Accounts Receivable record owes more than it originally did', $issues);
    }

    /**
     * Every stay — active or already closed — should have exactly one
     * folio; CheckInService creates one at check-in time (spec section
     * 12), so a stay with none indicates a broken or interrupted
     * check-in rather than a normal state.
     */
    private function staysHaveAFolio(): array
    {
        $issues = [];

        Stay::doesntHave('folio')->orderBy('id')->get()
            ->each(function (Stay $stay) use (&$issues) {
                $issues[] = "Stay #{$stay->id} (guest_id {$stay->guest_id}, room_id {$stay->room_id}) has no folio.";
            });

        return $this->result('stays_have_folio', 'Every stay has a folio', $issues);
    }

    /**
     * A room's `status` column and the presence of an active stay in
     * that room are two independently-writable facts that should always
     * agree — checked in both directions, since either one could drift
     * without the other noticing.
     */
    private function roomOccupancyMatchesActiveStays(): array
    {
        $issues = [];

        Room::where('status', 'occupied')->whereDoesntHave('currentStay')->orderBy('id')->get()
            ->each(function (Room $room) use (&$issues) {
                $issues[] = "Room {$room->room_number} is marked 'occupied' but has no active stay.";
            });

        Stay::where('status', 'active')->with('room')->orderBy('id')->get()
            ->each(function (Stay $stay) use (&$issues) {
                if ($stay->room && $stay->room->status !== 'occupied') {
                    $issues[] = "Stay #{$stay->id} is active in room {$stay->room->room_number}, but that room's status is '{$stay->room->status}', not 'occupied'.";
                }
            });

        return $this->result('room_occupancy_matches_stays', "Room status agrees with whether it actually has an active stay", $issues);
    }

    /**
     * A FIFO stock engine should never leave a batch with negative
     * remaining quantity — that would mean more was issued from a batch
     * than it ever received, a direct sign of a broken deduction rather
     * than a legitimate business state (unlike a product's aggregate
     * quantity, which is allowed to be zero but never negative at the
     * batch level either).
     */
    private function noNegativeStockBatches(): array
    {
        $issues = [];

        StockBatch::where('quantity_remaining', '<', 0)->orderBy('id')->get()
            ->each(function (StockBatch $batch) use (&$issues) {
                $issues[] = "StockBatch #{$batch->id} (product_id {$batch->product_id}) has negative quantity_remaining: {$batch->quantity_remaining}.";
            });

        return $this->result('no_negative_stock', 'No stock batch has gone negative', $issues);
    }

    /**
     * PurchaseOrder::isFullyReceived() is the exact same check
     * GoodsReceiptService uses to decide when to flip a PO's status to
     * 'received' — reused here rather than reimplemented, so a mismatch
     * always means a genuine bug in that transition, not a formula
     * disagreement between two different implementations of "received".
     */
    private function receivedPurchaseOrdersAreFullyReceived(): array
    {
        $issues = [];

        PurchaseOrder::where('status', 'received')->orderBy('id')->get()
            ->each(function (PurchaseOrder $po) use (&$issues) {
                if (! $po->isFullyReceived()) {
                    $issues[] = "PurchaseOrder #{$po->id} ({$po->po_number}) is marked 'received' but has at least one item not fully received.";
                }
            });

        return $this->result('received_pos_fully_received', "Purchase orders marked 'received' are actually fully received", $issues);
    }

    /**
     * Phase 9.7.6 addition — deliberately not part of the original Phase
     * 9.6 rollout, since `sync_conflicts` didn't exist until Phase 9.7.2,
     * two sub-phases later than this class was first built. A conflict
     * is never auto-resolved (see the `sync_conflicts` migration's own
     * docblock and `App\Services\Sync\SyncConflictResolver`, which is the
     * only thing allowed to clear one) — so an old, still-unresolved
     * conflict is a genuine operational risk this checker should
     * surface: it means two installations' data has been silently
     * diverging on that one record for however long it's sat there. 7
     * days mirrors the "Weekly Owner Summary" cadence already used
     * elsewhere in this project as this codebase's standing definition
     * of "a week has gone by without anyone looking."
     */
    private function noStaleSyncConflicts(): array
    {
        $issues = [];

        SyncConflict::whereNull('resolved_at')
            ->where('detected_at', '<', now()->subDays(self::STALE_CONFLICT_DAYS))
            ->orderBy('detected_at')
            ->get()
            ->each(function (SyncConflict $conflict) use (&$issues) {
                $ageInDays = (int) $conflict->detected_at->diffInDays(now());
                $issues[] = "SyncConflict #{$conflict->id} ({$conflict->syncable_type} {$conflict->syncable_ulid}) has been unresolved for {$ageInDays} day(s), detected {$conflict->detected_at->toDateString()}.";
            });

        return $this->result('no_stale_sync_conflicts', 'No sync conflict has been unresolved for more than '.self::STALE_CONFLICT_DAYS.' days', $issues);
    }

    /**
     * @param  array<int, string>  $issues
     * @return array{key: string, label: string, passed: bool, issue_count: int, issues: array<int, string>}
     */
    private function result(string $key, string $label, array $issues): array
    {
        return [
            'key' => $key,
            'label' => $label,
            'passed' => count($issues) === 0,
            'issue_count' => count($issues),
            'issues' => $issues,
        ];
    }
}
