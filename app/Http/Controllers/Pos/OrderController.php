<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\PosTable;
use App\Models\Stay;
use App\Services\Finance\ShiftGateService;
use App\Services\Pos\OrderService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Bar, Restaurant, and Front Desk (added post-9.7.4 for receptionist
 * petty sales — juice, water, snacks, biscuits, etc.) share one
 * controller/service — they differ only by the `outlet` route parameter,
 * which also selects the permission checked (`operate-bar-pos` /
 * `operate-restaurant-pos` / `operate-front_desk-pos`), so e.g. a Bar
 * Staff account can never touch a Restaurant or Front Desk order through
 * this controller even though the code path is shared. Front Desk sales
 * simply never set `pos_table_id` (already nullable) since a reception
 * counter has no seating to track.
 *
 * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8, fixed):
 * `authorizeOutlet()` used to check only the permission implied by the
 * URL's `{outlet}` segment, never whether the route-bound `$order` (an
 * implicit, unscoped binding — `Order::findOrFail($id)`, not scoped to
 * `outlet`) actually belongs to that outlet. A Bar Staff account (holding
 * only `operate-bar-pos`) could substitute a Restaurant or Front Desk
 * order's numeric ID into a `/pos/bar/orders/{id}/...` URL and the
 * permission check alone would pass, letting it view, add/remove items
 * on, confirm, close (posting to the central ledger under the WRONG
 * outlet's `source_module`), or cancel an order it has no business
 * touching — directly contradicting this docblock's own claim above. Now
 * every action that receives a bound `$order` also confirms
 * `$order->outlet === $outlet`, not just the permission.
 */
class OrderController extends Controller
{
    public function __construct(
        private readonly OrderService $service,
        private readonly ShiftGateService $shiftGate,
    ) {
    }

    private function authorizeOutlet(Request $request, string $outlet, ?Order $order = null): void
    {
        abort_unless($request->user()->can("operate-{$outlet}-pos"), 403);

        if ($order && $order->outlet !== $outlet) {
            abort(404);
        }
    }

    public function index(Request $request, string $outlet)
    {
        $this->authorizeOutlet($request, $outlet);

        $tables = PosTable::where('outlet', $outlet)->where('is_active', true)->orderBy('label')->get();
        // Post-9.8 fix round ("a search bar to search saved orders" — the
        // "saved orders" being exactly this open/draft-or-confirmed list,
        // which already existed; only the search box is new): matches on
        // order number or the table it's seated at.
        $openOrders = Order::where('outlet', $outlet)
            ->whereIn('status', ['draft', 'confirmed'])
            ->when($request->filled('search'), function ($q) use ($request) {
                $search = $request->string('search');
                $q->where(function ($q) use ($search) {
                    $q->where('order_number', 'like', "%{$search}%")
                        ->orWhereHas('table', fn ($q) => $q->where('label', 'like', "%{$search}%"));
                });
            })
            ->with('table')
            ->orderByDesc('id')
            ->get();

        // Feature 4 (500 XAF room-service delivery charge): only a
        // currently occupied stay can be a delivery destination — see
        // OrderService::open()'s own check, mirrored here so the picklist
        // never even offers an invalid one.
        $activeStays = Stay::with(['guest', 'room'])->where('status', 'active')->orderBy('room_id')->get();

        return view('pos.orders.index', compact('outlet', 'tables', 'openOrders', 'activeStays'));
    }

    public function store(Request $request, string $outlet)
    {
        $this->authorizeOutlet($request, $outlet);
        $this->shiftGate->assertOpenForOutlet($outlet);

        // Scoped to this outlet at the validation layer too — a table id
        // belonging to a different outlet fails validation cleanly here,
        // in addition to the OrderService::open() check that guards a
        // direct service call.
        $data = $request->validate([
            'pos_table_id' => ['nullable', Rule::exists('pos_tables', 'id')->where('outlet', $outlet)],
            // Feature 4 (500 XAF room-service delivery charge).
            'is_room_delivery' => 'nullable|boolean',
            'delivery_destination_stay_id' => 'required_if:is_room_delivery,1|nullable|exists:stays,id',
        ]);

        $order = $this->service->open(
            $outlet,
            $request->user(),
            $data['pos_table_id'] ?? null,
            null,
            (bool) ($data['is_room_delivery'] ?? false),
            isset($data['delivery_destination_stay_id']) ? (int) $data['delivery_destination_stay_id'] : null,
        );

        return redirect()->route('pos.orders.show', [$outlet, $order]);
    }

    /**
     * Feature 4: management override — the fee stays on record, but is
     * excluded from what the guest is actually charged.
     */
    public function waiveDeliveryFee(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);

        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->waiveDeliveryFee($order, $request->user(), $data['reason']);

        return back()->with('status', 'Delivery fee waived.');
    }

    /**
     * Feature 4: fixes a genuine mistake about whether/where an order was
     * delivered, once the delivery mode is locked (after confirm()) — see
     * OrderService::correctDeliveryMode()'s own docblock.
     */
    public function correctDeliveryMode(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);

        $data = $request->validate([
            'is_room_delivery' => 'nullable|boolean',
            'delivery_destination_stay_id' => 'required_if:is_room_delivery,1|nullable|exists:stays,id',
            'reason' => 'required|string|max:500',
        ]);

        $this->service->correctDeliveryMode(
            $order,
            (bool) ($data['is_room_delivery'] ?? false),
            isset($data['delivery_destination_stay_id']) ? (int) $data['delivery_destination_stay_id'] : null,
            $data['reason'],
            $request->user(),
        );

        return back()->with('status', 'Delivery mode corrected.');
    }

    public function show(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);

        $order->load(['items' => fn ($q) => $q->orderBy('id'), 'table', 'openedBy']);
        $menuItems = MenuItem::where('outlet', $outlet)->where('is_active', true)->with('category')->orderBy('name')->get();
        $activeStays = Stay::where('status', 'active')->with('guest', 'room')->get();
        // Fourth post-9.8 fix round (day-close rebuild): the "Settle &
        // close" form now picks a real financial account instead of a
        // bare method — see close()'s docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('pos.orders.show', compact('outlet', 'order', 'menuItems', 'activeStays', 'financialAccounts'));
    }

    /**
     * Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): printable "Restaurant
     * Receipt" / "Bar Receipt" — one view serving both named documents,
     * since they're the exact same order data differing only by which
     * outlet placed it (the receipt itself says so via $outlet). Same
     * in-controller authorizeOutlet() check as every other action here,
     * not a route-level permission string — see this class's docblock.
     */
    public function printReceipt(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);

        $order->load(['items' => fn ($q) => $q->orderBy('id'), 'table', 'openedBy', 'closedBy']);

        return view('pos.orders.receipt-print', compact('outlet', 'order'));
    }

    public function addItem(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);
        $this->shiftGate->assertOpenForOutlet($outlet);

        $data = $request->validate([
            // Scoped to this outlet (and active) at the validation layer
            // too — a menu item belonging to a different outlet, or an
            // inactive one, fails validation cleanly here, in addition to
            // the OrderService::addItem() check that guards a direct
            // service call.
            'menu_item_id' => ['required', Rule::exists('menu_items', 'id')->where('outlet', $outlet)->where('is_active', true)],
            'quantity' => 'required|integer|min:1|max:50',
            'notes' => 'nullable|string|max:255',
            'is_complimentary' => 'nullable|boolean',
            'comp_reason' => 'required_if:is_complimentary,1|nullable|string|max:255',
        ]);

        $menuItem = MenuItem::findOrFail($data['menu_item_id']);
        $this->service->addItem(
            $order,
            $menuItem,
            (int) $data['quantity'],
            $request->user(),
            $data['notes'] ?? null,
            (bool) ($data['is_complimentary'] ?? false),
            $data['comp_reason'] ?? null,
        );

        // Post-9.8 fix round (click-to-cart POS redesign): the new
        // pos/orders/show.blade.php adds items via fetch() so a click on
        // a menu tile updates the cart instantly without a full page
        // reload — this JSON branch is what that JS talks to. The
        // original full-page-submit form (used when JS is unavailable)
        // still works unchanged via the redirect below.
        if ($request->wantsJson()) {
            return response()->json(['order' => $this->cartPayload($order->fresh(['items']))]);
        }

        return back()->with('status', 'Item added.');
    }

    public function removeItem(Request $request, string $outlet, Order $order, OrderItem $item)
    {
        $this->authorizeOutlet($request, $outlet, $order);
        abort_unless($item->order_id === $order->id, 404); // item must actually belong to the bound order, not just the outlet

        $data = $request->validate(['reason' => 'required|string|max:255']);

        $this->service->removeItem($item, $request->user(), $data['reason']);

        if ($request->wantsJson()) {
            return response()->json(['order' => $this->cartPayload($order->fresh(['items']))]);
        }

        return back()->with('status', 'Item removed.');
    }

    /**
     * A small, deliberately hand-shaped JSON view of the order for the
     * click-to-cart JS to redraw the cart from — not the model's raw
     * toArray(), so a later column rename/addition on `orders`/
     * `order_items` doesn't silently change this API's shape.
     */
    private function cartPayload(Order $order): array
    {
        return [
            'id' => $order->id,
            'order_number' => $order->order_number,
            'status' => $order->status,
            'subtotal' => $order->subtotal,
            'total_amount' => $order->total_amount,
            'items' => $order->items->map(fn (OrderItem $item) => [
                'id' => $item->id,
                'item_name' => $item->item_name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'line_total' => $item->line_total,
                'is_complimentary' => $item->is_complimentary,
                'voided' => $item->isVoided(),
            ])->values(),
        ];
    }

    public function confirm(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);
        $this->shiftGate->assertOpenForOutlet($outlet);

        $this->service->confirm($order, $request->user());

        return back()->with('status', 'Order confirmed and sent.');
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `payment_method` select, same as every other
     * income entry point in this round — see FolioController::payment()'s
     * docblock. Still nullable together with `stay_id`: an order deferred
     * to a folio needs neither, and a wholly complimentary order (total
     * exactly 0) needs neither either — OrderService::close() itself
     * throws a clear error if payment is actually required and neither was
     * provided.
     */
    public function close(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);
        $this->shiftGate->assertOpenForOutlet($outlet);

        $data = $request->validate([
            'financial_account_id' => 'nullable|exists:financial_accounts,id',
            'stay_id' => 'nullable|exists:stays,id',
        ]);

        // Correction round (audited defect #31): see PaymentController::
        // store()'s matching comment — never trust the submitted id alone.
        $account = ! empty($data['financial_account_id'])
            ? FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id'])
            : null;

        $this->service->close($order, $request->user(), $account?->paymentMethod(), $data['stay_id'] ?? null, $account?->id);

        return redirect()->route('pos.index', $outlet)->with('status', 'Order closed and settled.');
    }

    public function cancel(Request $request, string $outlet, Order $order)
    {
        $this->authorizeOutlet($request, $outlet, $order);

        $data = $request->validate(['reason' => 'required|string|max:255']);

        $this->service->cancel($order, $request->user(), $data['reason']);

        return redirect()->route('pos.index', $outlet)->with('status', 'Order cancelled.');
    }
}
