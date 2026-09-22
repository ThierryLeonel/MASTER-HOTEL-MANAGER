<?php

namespace App\Services\Pos;

use App\Exceptions\HmsException;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosTable;
use App\Models\Stay;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\FrontOffice\FolioService;
use App\Services\Inventory\StockService;
use App\Services\PaymentLedgerService;
use App\Services\SettingsService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * POS order lifecycle (spec section 13):
 *
 *   draft -> confirmed -> closed
 *         -> cancelled (from draft or confirmed)
 *
 * While draft, items can be freely added, edited, or removed. Once
 * confirmed, existing lines are locked — only NEW lines may still be
 * appended ("add-only") — right up until the order closes. Only a
 * checked-in lodging guest (an active Stay) may defer the bill onto
 * their room folio; every other close requires an immediate payment
 * method and posts to the central payment ledger.
 *
 * Phase 3 addition: closing an order also deducts real inventory for
 * any line whose menu item has a recipe (see `recipe_items` /
 * StockService) — deliberately at close, not confirm, so a cancelled
 * order never has to reverse a stock deduction that never happened.
 * A menu item with no recipe configured is simply skipped, so Phase 2
 * outlets that never set up recipes are unaffected.
 */
class OrderService
{
    /**
     * Feature 4: "a 500 XAF room-service delivery charge" — a fixed
     * amount, not configurable per order, exactly as specified. Kept as a
     * class constant (like ShiftService's shift-number prefix pattern)
     * rather than a Setting, since the requirement names one specific
     * figure, not a hotel-configurable policy.
     */
    public const ROOM_DELIVERY_FEE = 500.0;

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly FolioService $folioService,
        private readonly PaymentLedgerService $ledger,
        private readonly SettingsService $settings,
        private readonly StockService $stock,
    ) {
    }

    /**
     * Feature 4 ("a 500 XAF room-service delivery charge"): $isRoomDelivery
     * + $deliveryDestinationStayId mark this order, from the moment it's
     * opened, as food/drink being delivered to a guest's room rather than
     * served at a table/counter — the fee is fixed at self::
     * ROOM_DELIVERY_FEE, applied here (not left for the view to guess),
     * and folded into the order's total by recalculateTotals() exactly
     * like any other line, so it settles through whatever payment path
     * (cash or folio) the rest of the order already uses. Deliberately
     * NOT an OrderItem — see the creating migration's own docblock for
     * why that's what makes "never deduct inventory for the delivery fee
     * itself" true by construction.
     */
    public function open(string $outlet, User $actor, ?int $tableId = null, ?string $notes = null, bool $isRoomDelivery = false, ?int $deliveryDestinationStayId = null): Order
    {
        return DB::transaction(function () use ($outlet, $actor, $tableId, $notes, $isRoomDelivery, $deliveryDestinationStayId) {
            if ($tableId) {
                /** @var PosTable $table */
                $table = PosTable::lockForUpdate()->findOrFail($tableId);

                // Outlet-ownership invariant: a table can only be selected
                // for an order opened at its own outlet — enforced here
                // too (not just in request validation) so a direct service
                // call cannot seat a Bar order at a Restaurant table.
                if ($table->outlet !== $outlet) {
                    throw new HmsException("Table {$table->label} does not belong to the {$outlet} outlet.");
                }

                if ($table->status === 'occupied') {
                    throw new HmsException("Table {$table->label} already has an open order.");
                }

                $table->update(['status' => 'occupied']);
            }

            $deliveryFee = 0.0;

            if ($isRoomDelivery) {
                if (! $deliveryDestinationStayId) {
                    throw new HmsException('A room delivery order must name the guest stay it is being delivered to.');
                }

                /** @var Stay $destinationStay */
                $destinationStay = Stay::findOrFail($deliveryDestinationStayId);

                if ($destinationStay->status !== 'active') {
                    throw new HmsException('Room delivery can only be marked for a currently occupied (checked-in) stay.');
                }

                $deliveryFee = self::ROOM_DELIVERY_FEE;
            }

            $order = Order::create([
                'order_number' => $this->generateNumber($outlet),
                'outlet' => $outlet,
                'pos_table_id' => $tableId,
                'status' => 'draft',
                'opened_by' => $actor->id,
                'notes' => $notes,
                'is_room_delivery' => $isRoomDelivery,
                'delivery_destination_stay_id' => $isRoomDelivery ? $deliveryDestinationStayId : null,
                'delivery_fee_amount' => $deliveryFee,
            ]);

            $this->audit->log('pos.order_opened', "pos_{$outlet}", $order, null, $order->toArray(), actor: $actor);

            return $order;
        });
    }

    /**
     * $isComplimentary items keep their real `unit_price` (so a report
     * can later total up "what this would have cost") but their
     * `line_total` is forced to 0 — recalculateTotals() below just sums
     * every line's `line_total`, so a comp line naturally contributes
     * nothing to the order's subtotal/tax/total without that method
     * needing to know anything about complimentary items at all. A
     * reason is required for a comp, the same "must explain yourself"
     * rule removeItem() already enforces for voiding a line — comping a
     * drink is deliberately not gated behind any permission beyond the
     * `operate-{outlet}-pos` already required to touch this order at
     * all, since (unlike marking a whole STAY complimentary — a bigger
     * revenue decision, gated by `mark-stay-complimentary`) this is a
     * routine point-of-sale judgment call the person serving the order
     * is trusted to make, same as voiding a line already is.
     */
    /**
     * Correction round (audited defect #13, "lock/revalidate parent order
     * for every mutation"): isEditable() used to be checked against the
     * caller-supplied, unlocked $order, BEFORE this transaction even
     * opened — a race against close()/cancel() (both of which DO lock the
     * order) could let an item be added to an order that had just been
     * closed or cancelled a moment earlier, corrupting its totals after
     * settlement. The order is now locked and re-checked inside the
     * transaction, matching close()'s own already-correct pattern.
     */
    public function addItem(Order $order, MenuItem $menuItem, int $quantity, User $actor, ?string $notes = null, bool $isComplimentary = false, ?string $compReason = null): OrderItem
    {
        // Outlet-ownership invariant: a menu item can only be added to an
        // order at its own outlet, and only while active — enforced here
        // too (not just in request validation) so a direct service call
        // cannot add a Restaurant item to a Bar order, or a discontinued
        // item to any order.
        if ($menuItem->outlet !== $order->outlet || ! $menuItem->is_active) {
            throw new HmsException('This menu item is not available for this order\'s outlet.');
        }

        if ($quantity < 1) {
            throw new HmsException('Quantity must be at least 1.');
        }

        if ($isComplimentary && ! $compReason) {
            throw new HmsException('A reason is required to mark an item complimentary.');
        }

        return DB::transaction(function () use ($order, $menuItem, $quantity, $actor, $notes, $isComplimentary, $compReason) {
            /** @var Order $order */
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $order->isEditable()) {
                throw new HmsException("Cannot add items to a {$order->status} order.");
            }

            $item = OrderItem::create([
                'order_id' => $order->id,
                'menu_item_id' => $menuItem->id,
                'item_name' => $menuItem->name,
                'unit_price' => $menuItem->price,
                'quantity' => $quantity,
                'line_total' => $isComplimentary ? '0.00' : bcmul((string) $menuItem->price, (string) $quantity, 2),
                'notes' => $notes,
                'added_by' => $actor->id,
                'is_complimentary' => $isComplimentary,
                'comp_reason' => $isComplimentary ? $compReason : null,
            ]);

            $this->recalculateTotals($order);

            $this->audit->log(
                $isComplimentary ? 'pos.item_comped' : 'pos.item_added',
                "pos_{$order->outlet}",
                $item,
                null,
                $item->toArray(),
                $isComplimentary ? $compReason : null,
                $actor,
            );

            return $item;
        });
    }

    /**
     * Only permitted while the order is still draft — once confirmed,
     * existing lines are frozen (spec section 13's "add-only" rule).
     */
    /**
     * Correction round (audited defect #13, same reasoning as addItem()
     * above): the draft-only check is now against a locked, re-fetched
     * Order row inside the transaction, not the caller-supplied instance.
     */
    public function removeItem(OrderItem $item, User $actor, string $reason): OrderItem
    {
        return DB::transaction(function () use ($item, $actor, $reason) {
            /** @var Order $order */
            $order = Order::query()->whereKey($item->order_id)->lockForUpdate()->firstOrFail();

            if (! $order->isDraft()) {
                throw new HmsException('Existing items can only be removed while the order is still a draft — confirmed orders are add-only.');
            }

            $item->update(['voided_at' => now(), 'void_reason' => $reason]);

            $this->recalculateTotals($order);

            $this->audit->log('pos.item_removed', "pos_{$order->outlet}", $item, null, $item->toArray(), $reason, $actor);

            return $item;
        });
    }

    /**
     * Correction round (audited defect #13): confirm() had no
     * DB::transaction at all — a race against cancel() (which does lock)
     * could confirm an order a moment after it was cancelled. Now locked
     * and re-checked like every other lifecycle transition in this class.
     */
    public function confirm(Order $order, User $actor): Order
    {
        return DB::transaction(function () use ($order, $actor) {
            /** @var Order $order */
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $order->isDraft()) {
                throw new HmsException("Only a draft order can be confirmed (current status: {$order->status}).");
            }

            if ($order->items()->whereNull('voided_at')->doesntExist()) {
                throw new HmsException('Cannot confirm an order with no items.');
            }

            // Feature 4: once confirmed, the delivery mode/fee is locked —
            // see the creating migration's docblock on
            // `delivery_mode_locked_at`. A genuine data-entry mistake
            // discovered after this point goes through
            // correctDeliveryMode() instead, which leaves an audited
            // order_delivery_corrections row rather than silently editing
            // the order.
            $order->update(['status' => 'confirmed', 'confirmed_at' => now(), 'delivery_mode_locked_at' => now()]);

            $this->audit->log('pos.order_confirmed', "pos_{$order->outlet}", $order, null, $order->toArray(), actor: $actor);

            return $order;
        });
    }

    /**
     * Feature 4: a management override for a delivery fee that shouldn't
     * apply (e.g. the guest picked the order up themselves after all) —
     * distinct from correctDeliveryMode() below, which fixes a genuine
     * data-entry mistake about whether/where an order was delivered at
     * all. Waiving leaves `delivery_fee_amount`/`is_room_delivery`
     * untouched (the order WAS still a room delivery) and simply excludes
     * the fee from the total via recalculateTotals(), the same way a
     * complimentary OrderItem's line_total is excluded while its
     * unit_price stays on record.
     */
    public function waiveDeliveryFee(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isEditable()) {
                throw new HmsException("Cannot waive the delivery fee on a {$locked->status} order.");
            }

            if (! $locked->is_room_delivery) {
                throw new HmsException('This order was never marked as a room delivery — there is no delivery fee to waive.');
            }

            if ($locked->delivery_fee_waived) {
                throw new HmsException('The delivery fee on this order has already been waived.');
            }

            $locked->update([
                'delivery_fee_waived' => true,
                'delivery_fee_waived_by' => $actor->id,
                'delivery_fee_waived_at' => now(),
                'delivery_fee_waive_reason' => $reason,
            ]);

            $this->recalculateTotals($locked);

            $this->audit->log('pos.delivery_fee_waived', "pos_{$locked->outlet}", $locked, null, $locked->fresh()->toArray(), $reason, $actor);

            return $locked->fresh();
        });
    }

    /**
     * Feature 4: fixes a genuine mistake about whether (or where) an
     * order was actually delivered — e.g. staff forgot to check the box
     * when opening the order, or picked the wrong room. Only allowed
     * while the order is still draft/confirmed (isEditable()) — a closed
     * order has already settled (paid, or charged to a folio) against its
     * current total, so changing the fee at that point would silently
     * desync the order from money that has already moved; a mistake
     * discovered after close needs a real folio/payment correction
     * instead (FolioService::correctPayment()/void()), not this method. A
     * cancelled order settled nothing either, so there is nothing here to
     * correct. Always leaves an order_delivery_corrections row recording
     * the before/after state, never edits history silently.
     */
    public function correctDeliveryMode(Order $order, bool $newIsRoomDelivery, ?int $newDeliveryDestinationStayId, string $reason, User $actor): Order
    {
        return DB::transaction(function () use ($order, $newIsRoomDelivery, $newDeliveryDestinationStayId, $reason, $actor) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isEditable()) {
                throw new HmsException("Cannot correct the delivery mode of a {$locked->status} order.");
            }

            if ($newIsRoomDelivery && ! $newDeliveryDestinationStayId) {
                throw new HmsException('A room delivery order must name the guest stay it is being delivered to.');
            }

            if ($newIsRoomDelivery) {
                /** @var Stay $destinationStay */
                $destinationStay = Stay::findOrFail($newDeliveryDestinationStayId);

                if ($destinationStay->status !== 'active') {
                    throw new HmsException('Room delivery can only be marked for a currently occupied (checked-in) stay.');
                }
            }

            $newFee = $newIsRoomDelivery ? self::ROOM_DELIVERY_FEE : 0.0;

            \App\Models\OrderDeliveryCorrection::create([
                'order_id' => $locked->id,
                'actor_id' => $actor->id,
                'previous_is_room_delivery' => $locked->is_room_delivery,
                'previous_delivery_fee_amount' => $locked->delivery_fee_amount,
                'new_is_room_delivery' => $newIsRoomDelivery,
                'new_delivery_fee_amount' => $newFee,
                'reason' => $reason,
            ]);

            $locked->update([
                'is_room_delivery' => $newIsRoomDelivery,
                'delivery_destination_stay_id' => $newIsRoomDelivery ? $newDeliveryDestinationStayId : null,
                'delivery_fee_amount' => $newFee,
                // A correction supersedes any earlier waiver on the old
                // fee — the new fee (if any) is charged in full unless
                // waived again, explicitly, as its own separate decision.
                'delivery_fee_waived' => false,
                'delivery_fee_waived_by' => null,
                'delivery_fee_waived_at' => null,
                'delivery_fee_waive_reason' => null,
            ]);

            $this->recalculateTotals($locked);

            $this->audit->log('pos.delivery_mode_corrected', "pos_{$locked->outlet}", $locked, null, $locked->fresh()->toArray(), $reason, $actor);

            return $locked->fresh();
        });
    }

    /**
     * Correction round (audited defect #14, "prevent post-settlement...
     * incorrect table release... total mismatches"): cancel() used to
     * check status against the unlocked $order before opening its
     * transaction — a genuine race against close() (audited defect #2 in
     * the original POS audit): a cancel() call racing a close() call on
     * the same order could both pass their pre-checks, and cancel() could
     * then overwrite a just-settled ('closed') order's status back to
     * 'cancelled' — while the payment/folio charge/stock deduction close()
     * already committed stays in place, leaving the order's own status
     * (and the table it frees) permanently inconsistent with the money
     * that actually moved. Locked and re-checked here closes that race —
     * the second call of the two always sees the true, just-committed
     * status and fails cleanly instead of corrupting it.
     */
    public function cancel(Order $order, User $actor, string $reason): Order
    {
        return DB::transaction(function () use ($order, $actor, $reason) {
            /** @var Order $order */
            $order = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! in_array($order->status, ['draft', 'confirmed'], true)) {
                throw new HmsException("Cannot cancel an order that is already {$order->status}.");
            }

            $order->update(['status' => 'cancelled', 'cancellation_reason' => $reason]);
            $this->freeTable($order);

            $this->audit->log('pos.order_cancelled', "pos_{$order->outlet}", $order, null, $order->toArray(), $reason, $actor);

            return $order;
        });
    }

    /**
     * Close and settle the order. Pass `$stayId` to defer the charge onto
     * a checked-in guest's room folio (only valid for an active Stay);
     * otherwise pass `$paymentMethod` to record an immediate payment into
     * the central ledger.
     *
     * Lifecycle fix: this used to accept any "editable" order — draft OR
     * confirmed — so a draft order (one that was never sent to the
     * kitchen/bar and never had its item list frozen, per this class's
     * own docblock) could be settled, charge a folio, record a payment,
     * and deduct stock, skipping the confirm step the documented
     * lifecycle requires. Only a `confirmed` order may close now.
     *
     * Concurrency fix: a duplicate close request (a double form submit, a
     * retried click, two staff closing the same order at once) could
     * previously both pass the status/no-items checks before either had
     * committed, and both post a payment/folio charge and deduct stock —
     * real duplicate money and stock movements. The order's status is now
     * re-checked INSIDE the transaction, against a row locked with
     * lockForUpdate(), so a second, racing request blocks until the first
     * commits, then sees the now-'closed' status and fails cleanly
     * instead of settling twice. (StockService::issueStock() already
     * locks its own stock batch rows for the same reason — this adds the
     * missing lock at the order level.)
     */
    public function close(Order $order, User $actor, ?string $paymentMethod = null, ?int $stayId = null, ?int $financialAccountId = null): Order
    {
        return DB::transaction(function () use ($order, $actor, $paymentMethod, $stayId, $financialAccountId) {
            /** @var Order $locked */
            $locked = Order::query()->whereKey($order->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isConfirmed()) {
                throw new HmsException("Only a confirmed order can be closed (current status: {$locked->status}).");
            }

            if ($locked->items()->whereNull('voided_at')->doesntExist()) {
                throw new HmsException('Cannot close an order with no items — cancel it instead.');
            }

            $this->recalculateTotals($locked);
            $locked->refresh();

            // An order that is ENTIRELY complimentary items totals 0 —
            // there is genuinely nothing to pay or defer, so (unlike a
            // normal order) it may close with neither a payment method
            // nor a stay to defer to, rather than forcing staff to pick
            // a payment method for money that was never charged.
            $isFullyComplimentary = bccomp((string) $locked->total_amount, '0.00', 2) === 0;

            if (! $isFullyComplimentary && ! $stayId && ! $paymentMethod) {
                throw new HmsException('Provide a payment method, or defer to a checked-in guest\'s folio.');
            }

            // Deduct real inventory before any payment/folio posting, so
            // a stock shortfall blocks the close cleanly rather than
            // leaving a guest charged (or a folio posted) for an order
            // that then fails. Comped items still consume real stock —
            // "complimentary" waives the price, not the cost.
            $this->deductRecipeStock($locked, $actor);

            if ($stayId) {
                /** @var Stay $stay */
                $stay = Stay::findOrFail($stayId);

                if ($stay->status !== 'active') {
                    throw new HmsException('Only a checked-in guest (an active stay) may defer this order to their room folio.');
                }

                $folio = $stay->folio()->firstOrFail();
                // Generalized (was a bar/restaurant-only ternary) so a
                // third+ outlet's deferred charges post under their own
                // type automatically — see FolioTransaction::CHARGE_TYPES,
                // which every outlet's "{outlet}_charge" must already be a
                // member of before this can succeed (postCharge() throws
                // HmsException otherwise).
                $chargeType = "{$locked->outlet}_charge";

                $this->folioService->postCharge(
                    $folio,
                    $chargeType,
                    (float) $locked->total_amount,
                    "{$locked->outlet} order {$locked->order_number}",
                    $actor,
                    "pos_{$locked->outlet}",
                    $locked->order_number,
                );

                $locked->update(['stay_id' => $stay->id, 'payment_method' => null]);
            } elseif ($paymentMethod) {
                // Recorded even for a $0 total if staff explicitly chose
                // a payment method anyway (harmless, and keeps the order
                // consistent with any other order paid the normal way).
                $this->ledger->record(
                    amount: (float) $locked->total_amount,
                    method: $paymentMethod,
                    sourceModule: "pos_{$locked->outlet}",
                    recordedBy: $actor,
                    sourceReference: $locked->order_number,
                    reference: $locked,
                    financialAccountId: $financialAccountId,
                );

                $locked->update(['payment_method' => $paymentMethod]);
            }
            // else: $isFullyComplimentary with no payment method and no
            // stay to defer to — nothing to record; payment_method stays
            // null, exactly like a deferred-to-folio order.

            $locked->update(['status' => 'closed', 'closed_by' => $actor->id, 'closed_at' => now()]);
            $this->freeTable($locked);

            $this->audit->log('pos.order_closed', "pos_{$locked->outlet}", $locked, null, $locked->fresh()->toArray(), actor: $actor);

            return $locked->fresh();
        });
    }

    /**
     * Post-9.8 fix round ("all VAT on food and drinks and even services
     * should be removed, it should not even appear on receipts"): this
     * used to also compute `tax_amount` from SettingsService::
     * taxRatePercent() and add it into `total_amount`. `tax_amount` is
     * now always written as 0 — deliberately still written (not dropped
     * from the update, and not left off the `orders` table) rather than
     * removed from the schema, since `tax_rate_percent` itself is being
     * kept as a dormant Settings field per the go-live decision, so a
     * later hotel that wants tax back on can just stop zeroing this
     * column rather than needing a schema change.
     */
    private function recalculateTotals(Order $order): void
    {
        $subtotal = (float) $order->items()->whereNull('voided_at')->sum('line_total');

        // Feature 4: the delivery fee is never an OrderItem (see open()'s
        // docblock), so it has to be folded in here explicitly, the one
        // place every other total-affecting change already flows through.
        // A waived fee still has `delivery_fee_amount` on record (for
        // "what this would have cost" reporting, same as a complimentary
        // OrderItem keeps its real unit_price) but contributes 0 to the
        // charged total.
        $deliveryFee = ($order->is_room_delivery && ! $order->delivery_fee_waived)
            ? (float) $order->delivery_fee_amount
            : 0.0;

        $order->update([
            'subtotal' => $subtotal,
            'tax_amount' => 0,
            'total_amount' => round($subtotal + $deliveryFee, 2),
        ]);
    }

    /**
     * For every non-voided line whose menu item has a recipe, issue the
     * required quantity of each ingredient product via the FIFO engine.
     * Deliberately not caught here: StockService::issueStock() throwing
     * HmsException on insufficient stock propagates straight out of
     * close(), rolling back the whole DB transaction (including the
     * totals recalculation above) — nothing is charged and the order
     * stays open for the staff to resolve (e.g. void the line, or
     * receive more stock) and try closing again.
     */
    private function deductRecipeStock(Order $order, User $actor): void
    {
        $items = $order->items()->whereNull('voided_at')->with('menuItem.recipeItems.product')->get();

        foreach ($items as $item) {
            $menuItem = $item->menuItem;

            if (! $menuItem) {
                continue;
            }

            foreach ($menuItem->recipeItems as $recipeItem) {
                $this->stock->issueStock(
                    product: $recipeItem->product,
                    quantity: (float) $recipeItem->quantity_per_unit * (float) $item->quantity,
                    actor: $actor,
                    sourceModule: "pos_{$order->outlet}",
                    type: 'issue',
                    sourceReference: $order->order_number,
                    notes: "{$item->quantity}x {$item->item_name}",
                );
            }
        }
    }

    private function freeTable(Order $order): void
    {
        if ($order->pos_table_id) {
            PosTable::where('id', $order->pos_table_id)->update(['status' => 'available']);
        }
    }

    private function generateNumber(string $outlet): string
    {
        return strtoupper(substr($outlet, 0, 3)).'-'.now()->format('Ymd').'-'.strtoupper(Str::random(5));
    }
}
