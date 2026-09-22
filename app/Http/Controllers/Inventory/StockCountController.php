<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Services\Inventory\StockCountService;
use Illuminate\Http\Request;

class StockCountController extends Controller
{
    public function __construct(private readonly StockCountService $service)
    {
    }

    public function index()
    {
        $stockCounts = StockCount::with('startedBy')->orderByDesc('id')->paginate(20);
        $products = Product::where('is_active', true)->orderBy('name')->get();

        return view('inventory.stock-counts.index', compact('stockCounts', 'products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|array|min:1',
            'product_id.*' => 'required|exists:products,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $count = $this->service->start($data['product_id'], $request->user(), $data['notes'] ?? null);

        return redirect()->route('inventory.stock-counts.show', $count)->with('status', 'Stock count started.');
    }

    public function show(StockCount $stockCount)
    {
        $stockCount->load(['items.product.unit', 'startedBy', 'completedBy']);

        return view('inventory.stock-counts.show', compact('stockCount'));
    }

    public function recordItem(Request $request, StockCount $stockCount, StockCountItem $item)
    {
        $data = $request->validate(['counted_quantity' => 'required|numeric|min:0']);

        $this->service->recordCount($item, (float) $data['counted_quantity'], $request->user());

        return back()->with('status', 'Count recorded for '.$item->product->name.'.');
    }

    public function complete(Request $request, StockCount $stockCount)
    {
        $this->service->complete($stockCount, $request->user());

        return redirect()->route('inventory.stock-counts.show', $stockCount)->with('status', 'Stock count completed — variances posted to the ledger.');
    }
}
