<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceipt;
use App\Models\Supplier;
use App\Services\AuditLogger;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index()
    {
        $suppliers = Supplier::orderBy('name')->get();

        return view('inventory.suppliers.index', compact('suppliers'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
        ]);

        Supplier::create($data);

        return back()->with('status', 'Supplier added.');
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): the supplier's own contact
     * record — never touches a purchase order or goods receipt already
     * raised against it (those keep their own supplier_id, and a PO's
     * pricing is captured on the PO itself, not read live from here).
     */
    public function update(Request $request, Supplier $supplier)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'contact_name' => 'nullable|string|max:255',
            'phone' => 'nullable|string|max:50',
            'email' => 'nullable|email|max:255',
            'address' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', $supplier->is_active);

        $oldValues = $supplier->only(['name', 'contact_name', 'phone', 'email', 'address', 'is_active']);
        $supplier->update($data);

        $this->audit->log('inventory.supplier_updated', 'inventory', $supplier, oldValues: $oldValues, newValues: $supplier->only(array_keys($oldValues)), actor: $request->user());

        return back()->with('status', "Supplier \"{$supplier->name}\" updated.");
    }

    /**
     * purchase_orders.supplier_id is restrictOnDelete (a real purchasing
     * history), and goods_receipts.supplier_id is nullOnDelete — a hard
     * delete there would silently strip which supplier a historical
     * receipt came from, exactly the kind of financial-adjacent record
     * rewrite this project avoids. Both are checked here so a supplier
     * with any real history is deactivated instead.
     */
    public function destroy(Request $request, Supplier $supplier)
    {
        if ($supplier->purchaseOrders()->exists()) {
            throw new HmsException("Supplier \"{$supplier->name}\" has purchase order history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        if (GoodsReceipt::where('supplier_id', $supplier->id)->exists()) {
            throw new HmsException("Supplier \"{$supplier->name}\" has goods receipt history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        $name = $supplier->name;
        $this->audit->log('inventory.supplier_deleted', 'inventory', $supplier, oldValues: $supplier->toArray(), actor: $request->user());
        $supplier->delete();

        return back()->with('status', "Supplier \"{$name}\" deleted.");
    }
}
