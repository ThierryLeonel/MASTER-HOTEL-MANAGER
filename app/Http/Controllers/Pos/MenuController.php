<?php

namespace App\Http\Controllers\Pos;

use App\Http\Controllers\Controller;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Product;
use App\Models\RecipeItem;
use Illuminate\Http\Request;

class MenuController extends Controller
{
    public function index(string $outlet)
    {
        $categories = MenuCategory::where('outlet', $outlet)->with(['items' => fn ($q) => $q->orderBy('name')])->orderBy('sort_order')->get();

        return view('pos.menu.index', compact('outlet', 'categories'));
    }

    public function storeCategory(Request $request, string $outlet)
    {
        $data = $request->validate(['name' => 'required|string|max:255']);

        MenuCategory::create(['outlet' => $outlet, 'name' => $data['name']]);

        return back()->with('status', 'Menu category added.');
    }

    public function storeItem(Request $request, string $outlet)
    {
        $data = $request->validate([
            'menu_category_id' => 'required|exists:menu_categories,id',
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
        ]);

        MenuItem::create([...$data, 'outlet' => $outlet]);

        return back()->with('status', 'Menu item added.');
    }

    /**
     * Recipe management (spec's inventory-POS link, Phase 3). A menu
     * item with no recipe rows is simply not tracked against stock —
     * deliberately backward compatible with Phase 2 outlets that don't
     * want recipe-level inventory (see the migration's docblock).
     */
    public function recipe(string $outlet, MenuItem $item)
    {
        $item->load(['recipeItems.product.unit']);
        $products = Product::where('is_active', true)->with('unit')->orderBy('name')->get();

        return view('pos.menu.recipe', compact('outlet', 'item', 'products'));
    }

    public function storeRecipeItem(Request $request, string $outlet, MenuItem $item)
    {
        $data = $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity_per_unit' => 'required|numeric|min:0.001',
        ]);

        RecipeItem::updateOrCreate(
            ['menu_item_id' => $item->id, 'product_id' => $data['product_id']],
            ['quantity_per_unit' => $data['quantity_per_unit']],
        );

        return back()->with('status', 'Recipe updated.');
    }

    public function removeRecipeItem(string $outlet, MenuItem $item, RecipeItem $recipeItem)
    {
        abort_unless((int) $recipeItem->menu_item_id === (int) $item->id, 404);

        $recipeItem->delete();

        return back()->with('status', 'Ingredient removed from recipe.');
    }
}
