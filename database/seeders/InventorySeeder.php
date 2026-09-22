<?php

namespace Database\Seeders;

use App\Models\MenuItem;
use App\Models\Product;
use App\Models\RecipeItem;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Inventory\StockService;
use Illuminate\Database\Seeder;

/**
 * Safe staging/demo data only (spec section 40) — units, a couple of
 * suppliers, and a handful of products with a *starting* stock batch
 * each, entered the same way a real opening balance would be: through
 * StockService::receiveStock(), never a raw quantity write, so FIFO
 * costing is meaningful from day one. Also links a couple of existing
 * bar/restaurant menu items (seeded in PosSeeder) to recipes, purely to
 * demonstrate the inventory-POS link end to end.
 */
class InventorySeeder extends Seeder
{
    public function run(): void
    {
        $stock = app(StockService::class);
        $bottle = Unit::firstOrCreate(['name' => 'Bottle'], ['abbreviation' => 'btl']);
        $kg = Unit::firstOrCreate(['name' => 'Kilogram'], ['abbreviation' => 'kg']);
        $litre = Unit::firstOrCreate(['name' => 'Litre'], ['abbreviation' => 'L']);
        $piece = Unit::firstOrCreate(['name' => 'Piece'], ['abbreviation' => 'pc']);

        $supplier = Supplier::firstOrCreate(
            ['name' => 'Douala Beverage Distributors'],
            ['contact_name' => 'Jean Mballa', 'phone' => '+237600000001', 'is_active' => true],
        );
        Supplier::firstOrCreate(
            ['name' => 'Central Market Fresh Produce'],
            ['contact_name' => 'Amina Njoya', 'phone' => '+237600000002', 'is_active' => true],
        );

        $products = [
            ['sku' => 'BEV-LAGER-33', 'name' => 'Local Lager (33cl bottle)', 'unit_id' => $bottle->id, 'category' => 'Beverages', 'reorder_level' => 48, 'cost' => 550],
            ['sku' => 'BEV-WHISKY-70', 'name' => 'Whisky (70cl bottle)', 'unit_id' => $bottle->id, 'category' => 'Beverages', 'reorder_level' => 6, 'cost' => 12000],
            ['sku' => 'FOOD-FISH-KG', 'name' => 'Fresh Fish', 'unit_id' => $kg->id, 'category' => 'Food', 'reorder_level' => 10, 'cost' => 2500],
            ['sku' => 'FOOD-PLANTAIN-KG', 'name' => 'Plantain', 'unit_id' => $kg->id, 'category' => 'Food', 'reorder_level' => 15, 'cost' => 600],
            ['sku' => 'BEV-SOFTDRINK-33', 'name' => 'Soft Drink (33cl bottle)', 'unit_id' => $bottle->id, 'category' => 'Beverages', 'reorder_level' => 48, 'cost' => 350],
            ['sku' => 'FOOD-WATER-50', 'name' => 'Bottled Water (50cl)', 'unit_id' => $piece->id, 'category' => 'Beverages', 'reorder_level' => 48, 'cost' => 200],
        ];

        $owner = User::whereHas('roles', fn ($q) => $q->where('name', 'owner'))->first();

        foreach ($products as $data) {
            $cost = $data['cost'];
            unset($data['cost']);

            $product = Product::firstOrCreate(['sku' => $data['sku']], $data);

            // Give every freshly-seeded product one opening stock batch,
            // through the real FIFO engine — never a raw quantity write.
            if ($owner && $product->wasRecentlyCreated) {
                $stock->receiveStock(
                    product: $product,
                    quantity: (float) $product->reorder_level * 2,
                    unitCost: $cost,
                    actor: $owner,
                    sourceModule: 'inventory_seed',
                    sourceReference: 'Opening balance',
                    notes: 'Opening stock (demo/staging data).',
                );
            }
        }

        // Link a couple of Phase 2 menu items to recipes, so the
        // POS-close -> FIFO-deduction path has something real to
        // exercise out of the box.
        $lager = Product::where('sku', 'BEV-LAGER-33')->first();
        $fish = Product::where('sku', 'FOOD-FISH-KG')->first();
        $plantain = Product::where('sku', 'FOOD-PLANTAIN-KG')->first();

        $lagerMenuItem = MenuItem::where('name', 'Local Lager (33cl)')->first();
        if ($lagerMenuItem && $lager) {
            RecipeItem::firstOrCreate(
                ['menu_item_id' => $lagerMenuItem->id, 'product_id' => $lager->id],
                ['quantity_per_unit' => 1],
            );
        }

        $fishMenuItem = MenuItem::where('name', 'Grilled Fish & Plantain')->first();
        if ($fishMenuItem && $fish && $plantain) {
            RecipeItem::firstOrCreate(
                ['menu_item_id' => $fishMenuItem->id, 'product_id' => $fish->id],
                ['quantity_per_unit' => 0.3],
            );
            RecipeItem::firstOrCreate(
                ['menu_item_id' => $fishMenuItem->id, 'product_id' => $plantain->id],
                ['quantity_per_unit' => 0.25],
            );
        }
    }
}
