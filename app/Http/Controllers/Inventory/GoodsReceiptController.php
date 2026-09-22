<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Product;
use App\Models\Supplier;
use App\Services\Inventory\GoodsReceiptService;
use Illuminate\Http\Request;

/**
 * Ad-hoc receiving — stock that arrived without a formal purchase
 * order (e.g. a small cash-and-carry top-up). PO-linked receiving goes
 * through PurchaseOrderController::receive() instead, since that path
 * also has to update the PO's own outstanding-quantity bookkeeping.
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

        return view('inventory.goods-receipts.index', compact('goodsReceipts', 'products', 'suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'supplier_id' => 'nullable|exists:suppliers,id',
            'notes' => 'nullable|string|max:500',
            'product_id' => 'required|array|min:1',
            'product_id.*' => 'nullable|exists:products,id',
            'quantity_received' => 'required|array|min:1',
            'quantity_received.*' => 'nullable|numeric|min:0.001',
            'unit_cost' => 'required|array|min:1',
            'unit_cost.*' => 'nullable|numeric|min:0',
        ]);

        $items = [];
        foreach ($data['product_id'] as $i => $productId) {
            if (empty($productId) || empty($data['quantity_received'][$i])) {
                continue;
            }

            $items[] = [
                'product_id' => $productId,
                'quantity_received' => $data['quantity_received'][$i],
                'unit_cost' => $data['unit_cost'][$i] ?? 0,
            ];
        }

        if (empty($items)) {
            return back()->withErrors(['error' => 'Add at least one line item (select a product and a quantity).']);
        }

        $this->service->receive($items, $request->user(), supplierId: $data['supplier_id'] ?? null, notes: $data['notes'] ?? null);

        return back()->with('status', 'Ad-hoc goods receipt recorded and stock updated.');
    }
}
