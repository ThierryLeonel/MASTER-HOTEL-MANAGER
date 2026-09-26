<?php

namespace App\Http\Controllers\Inventory;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\GoodsReceiptItem;
use App\Models\Product;
use App\Models\PurchaseOrderItem;
use App\Models\RecipeItem;
use App\Models\StockBatch;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\StockRequisitionItem;
use App\Models\StockWastage;
use App\Models\Unit;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class ProductController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index()
    {
        $products = Product::with('unit')->orderBy('name')->get();
        $units = Unit::orderBy('name')->get();

        return view('inventory.products.index', compact('products', 'units'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'unit_id' => 'required|exists:units,id',
            'category' => 'nullable|string|max:100',
            'reorder_level' => 'nullable|numeric|min:0',
        ]);

        // "Product numbering (sku) ... should be automatically generated" —
        // the SKU used to be freehand-typed on every new product, which
        // invited both typos and clashes between similarly-named items.
        Product::create($data + ['sku' => $this->generateSku($data['category'] ?? null, $data['name'])]);

        return back()->with('status', 'Product added.');
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): a product's own definition
     * (name/SKU/unit/category/reorder level/active flag) was never
     * editable. SKU stays editable here (unlike a business-document number)
     * because it's a reference code staff may legitimately need to align
     * with a supplier's own catalogue code, not an audit-trail sequence.
     * The stock ledger itself (batches/movements/FIFO cost) is exactly the
     * "financial input" this stays away from — nothing here ever touches
     * quantity or cost, only the product's own attributes.
     */
    public function update(Request $request, Product $product)
    {
        $data = $request->validate([
            'sku' => ['required', 'string', 'max:50', Rule::unique('products', 'sku')->ignore($product->id)],
            'name' => 'required|string|max:255',
            'unit_id' => 'required|exists:units,id',
            'category' => 'nullable|string|max:100',
            'reorder_level' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', $product->is_active);

        $oldValues = $product->only(['sku', 'name', 'unit_id', 'category', 'reorder_level', 'is_active']);
        $product->update($data);

        $this->audit->log('inventory.product_updated', 'inventory', $product, oldValues: $oldValues, newValues: $product->only(array_keys($oldValues)), actor: $request->user());

        return back()->with('status', "Product \"{$product->name}\" updated.");
    }

    /**
     * `PRD-<slug>-XXXX`, slugged from the category when given (so items in
     * the same category sort/scan together) or the name otherwise, plus a
     * random suffix for uniqueness. Human-recognizable without ever being
     * typed by hand.
     */
    private function generateSku(?string $category, string $name): string
    {
        $base = strtoupper(Str::slug($category ?: $name, ''));
        $base = substr($base, 0, 12) ?: 'PRD';

        do {
            $sku = $base.'-'.strtoupper(Str::random(4));
        } while (Product::where('sku', $sku)->exists());

        return $sku;
    }

    /**
     * Deleting is only allowed for a product that has never actually
     * moved through the FIFO stock ledger or appeared on any purchasing/
     * usage document — every one of those tables (goods receipts,
     * purchase orders, requisitions, stock counts, wastage, recipes,
     * batches, movements) uses restrictOnDelete at the database level for
     * exactly this reason. A product with any such history is deactivated
     * instead (already supported via `is_active`, see update() above),
     * which already keeps it out of new purchase orders/recipes without
     * losing what it cost or moved historically.
     */
    public function destroy(Request $request, Product $product)
    {
        $history = array_filter([
            'purchase order lines' => PurchaseOrderItem::where('product_id', $product->id)->exists(),
            'goods receipt lines' => GoodsReceiptItem::where('product_id', $product->id)->exists(),
            'stock requisition lines' => StockRequisitionItem::where('product_id', $product->id)->exists(),
            'stock count lines' => StockCountItem::where('product_id', $product->id)->exists(),
            'wastage records' => StockWastage::where('product_id', $product->id)->exists(),
            'recipe usage' => RecipeItem::where('product_id', $product->id)->exists(),
            'stock batches' => StockBatch::where('product_id', $product->id)->exists(),
            'stock movements' => StockMovement::where('product_id', $product->id)->exists(),
        ]);

        if ($history !== []) {
            throw new HmsException(
                "Product \"{$product->name}\" has history (".implode(', ', array_keys($history)).') and can\'t be deleted without erasing that record. Deactivate it instead.'
            );
        }

        $name = $product->name;
        $this->audit->log('inventory.product_deleted', 'inventory', $product, oldValues: $product->toArray(), actor: $request->user());
        $product->delete();

        return back()->with('status', "Product \"{$name}\" deleted.");
    }

    public function storeUnit(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100|unique:units,name',
            'abbreviation' => 'required|string|max:20',
        ]);

        Unit::create($data);

        return back()->with('status', 'Unit added.');
    }

    public function updateUnit(Request $request, Unit $unit)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('units', 'name')->ignore($unit->id)],
            'abbreviation' => 'required|string|max:20',
        ]);

        $oldValues = $unit->only(['name', 'abbreviation']);
        $unit->update($data);

        $this->audit->log('inventory.unit_updated', 'inventory', $unit, oldValues: $oldValues, newValues: $unit->only(['name', 'abbreviation']), actor: $request->user());

        return back()->with('status', "Unit \"{$unit->name}\" updated.");
    }

    public function destroyUnit(Request $request, Unit $unit)
    {
        if ($unit->products()->exists()) {
            throw new HmsException("Unit \"{$unit->name}\" is still used by one or more products — change or delete those products first.");
        }

        $name = $unit->name;
        $this->audit->log('inventory.unit_deleted', 'inventory', $unit, oldValues: $unit->toArray(), actor: $request->user());
        $unit->delete();

        return back()->with('status', "Unit \"{$name}\" deleted.");
    }
}
