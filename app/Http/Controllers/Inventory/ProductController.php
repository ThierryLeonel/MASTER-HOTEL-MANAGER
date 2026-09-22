<?php

namespace App\Http\Controllers\Inventory;

use App\Http\Controllers\Controller;
use App\Models\Product;
use App\Models\Unit;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public function index()
    {
        $products = Product::with('unit')->orderBy('name')->get();
        $units = Unit::orderBy('name')->get();

        return view('inventory.products.index', compact('products', 'units'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'sku' => 'required|string|max:50|unique:products,sku',
            'name' => 'required|string|max:255',
            'unit_id' => 'required|exists:units,id',
            'category' => 'nullable|string|max:100',
            'reorder_level' => 'nullable|numeric|min:0',
        ]);

        Product::create($data);

        return back()->with('status', 'Product added.');
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
}
