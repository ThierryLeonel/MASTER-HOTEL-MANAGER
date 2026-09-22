<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\Supplier;
use App\Services\Inventory\GoodsReceiptService;
use App\Services\Inventory\PurchaseOrderService;
use Illuminate\Http\Request;

class PurchaseOrderController extends Controller
{
    public function __construct(
        private readonly PurchaseOrderService $service,
        private readonly GoodsReceiptService $receiptService,
    ) {
    }

    public function index(Request $request)
    {
        $purchaseOrders = PurchaseOrder::with('supplier')
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return view('inventory.purchase-orders.index', compact('purchaseOrders'));
    }

    public function create()
    {
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $products = Product::where('is_active', true)->with('unit')->orderBy('name')->get();

        return view('inventory.purchase-orders.create', compact('suppliers', 'products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'required|exists:suppliers,id',
            'notes' => 'nullable|string|max:500',
            // The create form ships a fixed number of blank rows — a row
            // left untouched has no product selected, so every per-row
            // field is nullable here and blank rows are filtered out
            // below rather than rejected by validation.
            'product_id' => 'required|array|min:1',
            'product_id.*' => 'nullable|exists:products,id',
            'quantity_ordered' => 'required|array|min:1',
            'quantity_ordered.*' => 'nullable|numeric|min:0.001',
            'unit_cost' => 'required|array|min:1',
            'unit_cost.*' => 'nullable|numeric|min:0',
        ]);

        $items = [];
        foreach ($data['product_id'] as $i => $productId) {
            if (empty($productId) || empty($data['quantity_ordered'][$i])) {
                continue;
            }

            $items[] = [
                'product_id' => $productId,
                'quantity_ordered' => $data['quantity_ordered'][$i],
                'unit_cost' => $data['unit_cost'][$i] ?? 0,
            ];
        }

        if (empty($items)) {
            return back()->withErrors(['error' => 'Add at least one line item (select a product and a quantity).'])->withInput();
        }

        $po = $this->service->create($data['supplier_id'], $items, $request->user(), $data['notes'] ?? null);

        return redirect()->route('inventory.purchase-orders.show', $po)->with('status', 'Purchase order created as a draft.');
    }

    public function show(PurchaseOrder $purchaseOrder)
    {
        $purchaseOrder->load(['supplier', 'items.product.unit', 'orderedBy', 'approvedBy', 'goodsReceipts.items.product']);

        return view('inventory.purchase-orders.show', compact('purchaseOrder'));
    }

    public function submit(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->service->submit($purchaseOrder, $request->user());

        return back()->with('status', 'Purchase order submitted for approval.');
    }

    public function approve(Request $request, PurchaseOrder $purchaseOrder)
    {
        $this->service->approve($purchaseOrder, $request->user());

        return back()->with('status', 'Purchase order approved.');
    }

    public function reject(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($purchaseOrder, $request->user(), $data['reason']);

        return back()->with('status', 'Purchase order rejected.');
    }

    public function cancel(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->cancel($purchaseOrder, $request->user(), $data['reason']);

        return back()->with('status', 'Purchase order cancelled.');
    }

    public function receive(Request $request, PurchaseOrder $purchaseOrder)
    {
        $data = $request->validate([
            'purchase_order_item_id' => 'required|array|min:1',
            'purchase_order_item_id.*' => 'required|exists:purchase_order_items,id',
            'quantity_received' => 'required|array|min:1',
            'quantity_received.*' => 'required|numeric|min:0',
        ]);

        $items = [];
        foreach ($data['purchase_order_item_id'] as $i => $poItemId) {
            $quantity = (float) $data['quantity_received'][$i];

            if ($quantity <= 0) {
                continue;
            }

            $poItem = PurchaseOrderItem::findOrFail($poItemId);

            // A submitted purchase_order_item_id must belong to the
            // route-bound purchase order — otherwise a user with access to
            // one PO could receive (and thus move stock and mark received
            // quantities against) a line item from a completely different
            // PO just by guessing/tampering with its id. Reject the whole
            // submission before any receipt/stock/quantity side effect.
            if ((int) $poItem->purchase_order_id !== (int) $purchaseOrder->id) {
                return back()->withErrors(['error' => 'One or more selected line items do not belong to this purchase order.']);
            }

            $items[] = [
                'product_id' => $poItem->product_id,
                'purchase_order_item_id' => $poItem->id,
                'quantity_received' => $quantity,
                'unit_cost' => (float) $poItem->unit_cost,
            ];
        }

        if (empty($items)) {
            return back()->withErrors(['error' => 'Enter a quantity for at least one line to receive.']);
        }

        $this->receiptService->receive($items, $request->user(), purchaseOrder: $purchaseOrder);

        return redirect()->route('inventory.purchase-orders.show', $purchaseOrder)->with('status', 'Goods receipt recorded and stock updated.');
    }
}
