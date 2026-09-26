<?php

namespace App\Http\Controllers\Pos;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\BreakfastEntitlement;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class MenuController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

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

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): renaming a menu category.
     * Never touches a placed order — order_items snapshots its own
     * `item_name`/`unit_price` at the time it was added (see that
     * migration's own docblock), so nothing here can rewrite a past sale.
     */
    public function updateCategory(Request $request, string $outlet, MenuCategory $category)
    {
        abort_unless($category->outlet === $outlet, 404);

        $data = $request->validate(['name' => 'required|string|max:255']);
        $old = $category->name;
        $category->update($data);

        $this->audit->log('pos.menu_category_updated', 'pos', $category, oldValues: ['name' => $old], newValues: ['name' => $category->name], actor: $request->user());

        return back()->with('status', 'Menu category updated.');
    }

    public function destroyCategory(Request $request, string $outlet, MenuCategory $category)
    {
        abort_unless($category->outlet === $outlet, 404);

        if ($category->items()->exists()) {
            throw new HmsException("Category \"{$category->name}\" still has menu items in it — move or delete those items first.");
        }

        $name = $category->name;
        $this->audit->log('pos.menu_category_deleted', 'pos', $category, oldValues: $category->toArray(), actor: $request->user());
        $category->delete();

        return back()->with('status', "Menu category \"{$name}\" deleted.");
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
     * Editing a menu item's name/category/description/price/active flag.
     * Safe by design against rewriting a past sale: every order line
     * snapshots its own name and price at the moment it was ordered (see
     * order_items' migration docblock) rather than reading them live from
     * here, so a later price correction or rename never changes what a
     * guest was actually charged historically.
     */
    public function updateItem(Request $request, string $outlet, MenuItem $item)
    {
        abort_unless($item->outlet === $outlet, 404);

        $data = $request->validate([
            'menu_category_id' => ['required', Rule::exists('menu_categories', 'id')->where('outlet', $outlet)],
            'name' => 'required|string|max:255',
            'description' => 'nullable|string',
            'price' => 'required|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', $item->is_active);

        $oldValues = $item->only(['menu_category_id', 'name', 'description', 'price', 'is_active']);
        $item->update($data);

        $this->audit->log('pos.menu_item_updated', 'pos', $item, oldValues: $oldValues, newValues: $item->only(array_keys($oldValues)), actor: $request->user());

        return back()->with('status', "Menu item \"{$item->name}\" updated.");
    }

    /**
     * Deleting is only allowed for an item that was never actually
     * ordered, has no configured recipe, and was never used for a
     * breakfast redemption — even though none of those three would
     * actually rewrite a historical amount (order_items/breakfast_
     * entitlements both snapshot or denormalize independently of this
     * row, and recipe_items is just configuration), losing the readable
     * link on a real record is still avoided by deactivating instead,
     * the same "any usage -> deactivate" rule used everywhere else in
     * this project.
     */
    public function destroyItem(Request $request, string $outlet, MenuItem $item)
    {
        abort_unless($item->outlet === $outlet, 404);

        $history = array_filter([
            'orders' => OrderItem::where('menu_item_id', $item->id)->exists(),
            'a configured recipe' => $item->hasRecipe(),
            'breakfast entitlement records' => BreakfastEntitlement::where('menu_item_id', $item->id)->exists(),
        ]);

        if ($history !== []) {
            throw new HmsException(
                "Menu item \"{$item->name}\" has history (".implode(', ', array_keys($history)).') and can\'t be deleted without losing that record. Deactivate it instead.'
            );
        }

        $name = $item->name;
        $this->audit->log('pos.menu_item_deleted', 'pos', $item, oldValues: $item->toArray(), actor: $request->user());
        $item->delete();

        return back()->with('status', "Menu item \"{$name}\" deleted.");
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
