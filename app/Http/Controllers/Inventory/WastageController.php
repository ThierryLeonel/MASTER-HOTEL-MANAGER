<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Product;
use App\Models\StockWastage;
use App\Services\Inventory\WastageService;
use Illuminate\Http\Request;

class WastageController extends Controller
{
    public function __construct(private readonly WastageService $service)
    {
    }

    public function index(Request $request)
    {
        $wastages = StockWastage::with(['product', 'department', 'recordedBy'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);
        $products = Product::where('is_active', true)->with('unit')->orderBy('name')->get();
        $departments = Department::where('is_active', true)->orderBy('name')->get();

        return view('inventory.wastage.index', compact('wastages', 'products', 'departments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'department_id' => 'nullable|exists:departments,id',
            'quantity' => 'required|numeric|min:0.001',
            'reason' => 'required|string|max:255',
        ]);

        $this->service->record($data['product_id'], (float) $data['quantity'], $data['reason'], $request->user(), $data['department_id'] ?? null);

        return back()->with('status', 'Wastage recorded and awaiting approval.');
    }

    public function approve(Request $request, StockWastage $wastage)
    {
        $this->service->approve($wastage, $request->user());

        return back()->with('status', 'Wastage approved and stock deducted.');
    }

    public function reject(Request $request, StockWastage $wastage)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($wastage, $request->user(), $data['reason']);

        return back()->with('status', 'Wastage rejected — stock unaffected.');
    }
}
