<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Inventory\GoodsReceiptService;
use Illuminate\Http\Request;

/**
 * Ad-hoc receiving — stock that arrived without a formal purchase
 * order (e.g. a small cash-and-carry top-up), OR a quick receipt against
 * an approved PO from this same general screen rather than having to
 * navigate to that PO's own page first (PurchaseOrderController::receive()
 * still exists for receiving against specific outstanding PO lines one by
 * one with quantity caps enforced per line).
 *
 * "Goods received should be able to ref purchase orders for proper
 * accountability": when a PO is picked here, each submitted line is
 * matched to that PO's own item for the same product (if one exists) so
 * GoodsReceiptService can link purchase_order_item_id and keep the PO's
 * quantity_received bookkeeping correct — exactly as if it had been
 * received from the PO's own page.
 */
class GoodsReceiptController extends Controller
{
    public function __construct(private readonly GoodsReceiptService $service)
    {
    }

    public function index()
    {
        $goodsReceipts = GoodsReceipt::with(['supplier', 'purchaseOrder', 'receivedBy'])->orderByDesc('id')->paginate(20);
        $products = Product::where('is_active', true)->with('unit')->orderBy('name')->get();
        $suppliers = Supplier::where('is_active', true)->orderBy('name')->get();
        $openPurchaseOrders = PurchaseOrder::where('status', 'approved')->with('supplier')->orderByDesc('id')->get();

        return view('inventory.goods-receipts.index', compact('goodsReceipts', 'products', 'suppliers', 'openPurchaseOrders'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'purchase_order_id' => 'nullable|exists:purchase_orders,id',
            'supplier_id' => 'nullable|exists:suppliers,id',
            'notes' => 'nullable|string|max:500',
            'product_id' => 'required|array|min:1',
            'product_id.*' => 'nullable|exists:products,id',
            'quantity_received' => 'required|array|min:1',
            'quantity_received.*' => 'nullable|numeric|min:0.001',
            'unit_cost' => 'required|array|min:1',
            'unit_cost.*' => 'nullable|numeric|min:0',
        ]);

        $purchaseOrder = ! empty($data['purchase_order_id']) ? PurchaseOrder::findOrFail($data['purchase_order_id']) : null;
        $poItemsByProduct = $purchaseOrder ? $purchaseOrder->items->keyBy('product_id') : collect();

        $items = [];
        foreach ($data['product_id'] as $i => $productId) {
            if (empty($productId) || empty($data['quantity_received'][$i])) {
                continue;
            }

            $items[] = [
                'product_id' => $productId,
                'quantity_received' => $data['quantity_received'][$i],
                'unit_cost' => $data['unit_cost'][$i] ?? 0,
                'purchase_order_item_id' => $poItemsByProduct->get($productId)?->id,
            ];
        }

        if (empty($items)) {
            return back()->withErrors(['error' => 'Add at least one line item (select a product and a quantity).']);
        }

        $this->service->receive(
            $items,
            $request->user(),
            purchaseOrder: $purchaseOrder,
            supplierId: $data['supplier_id'] ?? null,
            notes: $data['notes'] ?? null,
        );

        return back()->with('status', $purchaseOrder
            ? "Goods receipt recorded against {$purchaseOrder->po_number} and stock updated."
            : 'Ad-hoc goods receipt recorded and stock updated.');
    }
}
