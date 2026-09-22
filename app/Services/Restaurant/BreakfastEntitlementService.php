<?php

namespace App\Services\Restaurant;

use App\Exceptions\HmsException;
use App\Models\BreakfastEntitlement;
use App\Models\MenuItem;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Finance\AccountingDayService;
use App\Services\Inventory\StockService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/**
 * Feature 3 ("one complimentary breakfast per occupied unit per business
 * date"): a direct, no-charge redemption — deliberately NOT routed
 * through the ordinary POS Order lifecycle (App\Services\Pos\OrderService),
 * since a complimentary item is never paid for, never appears on a
 * guest's folio, and needs none of Order's draft/confirm/close/table/
 * payment machinery. It still deducts real stock through the same
 * StockService FIFO engine every other recipe-backed sale uses, so a free
 * breakfast costs the hotel exactly as much inventory as a paid one
 * would. A menu item with no recipe configured simply redeems with no
 * stock effect, exactly like OrderService::deductRecipeStock() already
 * tolerates for ordinary sales.
 */
class BreakfastEntitlementService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly AccountingDayService $accountingDay,
        private readonly StockService $stock,
    ) {
    }

    /**
     * Locks the Stay row first — this alone already serializes two
     * near-simultaneous redemption attempts for the same stay (the second
     * request blocks until the first commits, then re-reads and sees the
     * entitlement that now exists). The unique (stay_id, business_date)
     * DB constraint on breakfast_entitlements is kept as a second,
     * independent backstop (locking + DB constraint, the same
     * belt-and-braces pattern this correction round applies to
     * FolioTransaction reversals) rather than relied on alone.
     */
    public function redeem(Stay $stay, MenuItem $menuItem, User $actor): BreakfastEntitlement
    {
        return DB::transaction(function () use ($stay, $menuItem, $actor) {
            /** @var Stay $lockedStay */
            $lockedStay = Stay::query()->whereKey($stay->getKey())->lockForUpdate()->firstOrFail();

            if ($lockedStay->status !== 'active') {
                throw new HmsException('This stay is not currently occupying a room — only an active, checked-in stay can redeem a complimentary breakfast.');
            }

            if ($menuItem->outlet !== 'restaurant' || ! $menuItem->is_active) {
                throw new HmsException('Choose an active Restaurant menu item for the breakfast redemption.');
            }

            $businessDate = $this->accountingDay->today()->business_date;

            if (BreakfastEntitlement::where('stay_id', $lockedStay->id)->where('business_date', $businessDate)->exists()) {
                throw new HmsException('This stay already redeemed its complimentary breakfast for '.$businessDate->toDateString().'.');
            }

            foreach ($menuItem->recipeItems as $recipeItem) {
                $this->stock->issueStock(
                    product: $recipeItem->product,
                    quantity: (float) $recipeItem->quantity_per_unit,
                    actor: $actor,
                    sourceModule: 'restaurant_breakfast',
                    type: 'issue',
                    sourceReference: "Stay #{$lockedStay->id} complimentary breakfast",
                    notes: "Complimentary breakfast: {$menuItem->name}",
                );
            }

            try {
                $entitlement = BreakfastEntitlement::create([
                    'stay_id' => $lockedStay->id,
                    'room_id' => $lockedStay->room_id,
                    'business_date' => $businessDate,
                    'served_at' => now(),
                    'served_by' => $actor->id,
                    'menu_item_id' => $menuItem->id,
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[0] ?? null) === '23000') {
                    throw new HmsException('This stay already redeemed its complimentary breakfast for '.$businessDate->toDateString().'.');
                }

                throw $e;
            }

            $this->audit->log('restaurant.breakfast_redeemed', 'restaurant', $entitlement, null, $entitlement->toArray(), actor: $actor);

            return $entitlement;
        });
    }

    /**
     * Never deletes the entitlement row — the requirement explicitly says
     * "deleting a record must not silently restore a used entitlement",
     * and the unique constraint on breakfast_entitlements counts EVERY
     * row for (stay_id, business_date) regardless of whether it was later
     * reversed, so once used, that day's slot stays spent. Reverses the
     * stock deduction by receiving the same products back in a fresh
     * batch (dated now, at the ingredient's current average cost) rather
     * than editing the original StockMovement/StockBatch rows — the same
     * "insert a reversing entry, never edit history" rule this codebase
     * already applies to folio_transactions and payments.
     */
    public function reverse(BreakfastEntitlement $entitlement, string $reason, User $actor): BreakfastEntitlement
    {
        return DB::transaction(function () use ($entitlement, $reason, $actor) {
            /** @var BreakfastEntitlement $locked */
            $locked = BreakfastEntitlement::query()->whereKey($entitlement->getKey())->lockForUpdate()->firstOrFail();

            if ($locked->isReversed()) {
                throw new HmsException('This breakfast entitlement has already been reversed.');
            }

            $menuItem = $locked->menuItem;

            if ($menuItem) {
                foreach ($menuItem->recipeItems as $recipeItem) {
                    $quantity = (float) $recipeItem->quantity_per_unit;
                    $unitCost = $this->stock->averageUnitCost($recipeItem->product);

                    $this->stock->receiveStock(
                        product: $recipeItem->product,
                        quantity: $quantity,
                        unitCost: $unitCost,
                        actor: $actor,
                        sourceModule: 'restaurant_breakfast_reversal',
                        source: $locked,
                        sourceReference: "Reversal of breakfast entitlement #{$locked->id}",
                        notes: $reason,
                    );
                }
            }

            $locked->update([
                'reversed_at' => now(),
                'reversed_by' => $actor->id,
                'reversal_reason' => $reason,
            ]);

            $this->audit->log('restaurant.breakfast_entitlement_reversed', 'restaurant', $locked, null, $locked->fresh()->toArray(), $reason, $actor);

            return $locked->fresh();
        });
    }
}
