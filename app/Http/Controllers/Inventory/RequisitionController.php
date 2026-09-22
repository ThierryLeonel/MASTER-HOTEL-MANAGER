<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Department;
use App\Models\Product;
use App\Models\StockRequisition;
use App\Services\Inventory\RequisitionService;
use Illuminate\Http\Request;

class RequisitionController extends Controller
{
    public function __construct(private readonly RequisitionService $service)
    {
    }

    public function index(Request $request)
    {
        $requisitions = StockRequisition::with(['department', 'requestedBy'])
            ->when($request->status, fn ($q, $status) => $q->where('status', $status))
            ->orderByDesc('id')
            ->paginate(20);

        return view('inventory.requisitions.index', compact('requisitions'));
    }

    public function create()
    {
        $departments = Department::where('is_active', true)->orderBy('name')->get();
        $products = Product::where('is_active', true)->with('unit')->orderBy('name')->get();

        return view('inventory.requisitions.create', compact('departments', 'products'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'department_id' => 'required|exists:departments,id',
            'notes' => 'nullable|string|max:500',
            'product_id' => 'required|array|min:1',
            'product_id.*' => 'nullable|exists:products,id',
            'quantity_requested' => 'required|array|min:1',
            'quantity_requested.*' => 'nullable|numeric|min:0.001',
        ]);

        $items = [];
        foreach ($data['product_id'] as $i => $productId) {
            if (empty($productId) || empty($data['quantity_requested'][$i])) {
                continue;
            }

            $items[] = ['product_id' => $productId, 'quantity_requested' => $data['quantity_requested'][$i]];
        }

        if (empty($items)) {
            return back()->withErrors(['error' => 'Add at least one line item (select a product and a quantity).'])->withInput();
        }

        $requisition = $this->service->create($data['department_id'], $items, $request->user(), $data['notes'] ?? null);

        return redirect()->route('inventory.requisitions.show', $requisition)->with('status', 'Requisition submitted for approval.');
    }

    public function show(StockRequisition $requisition)
    {
        $requisition->load(['department', 'requestedBy', 'approvedBy', 'items.product.unit']);

        return view('inventory.requisitions.show', compact('requisition'));
    }

    public function approve(Request $request, StockRequisition $requisition)
    {
        $this->service->approve($requisition, $request->user());

        return back()->with('status', 'Requisition approved and stock issued.');
    }

    public function reject(Request $request, StockRequisition $requisition)
    {
        $data = $request->validate(['reason' => 'required|string|max:500']);

        $this->service->reject($requisition, $request->user(), $data['reason']);

        return back()->with('status', 'Requisition rejected.');
    }

    public function cancel(Request $request, StockRequisition $requisition)
    {
        $data = $request->validate(['reason' => 'nullable|string|max:500']);

        $this->service->cancel($requisition, $request->user(), $data['reason'] ?? 'Cancelled by requester.');

        return back()->with('status', 'Requisition cancelled.');
    }
}
