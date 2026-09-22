<?php

namespace Database\Seeders;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\PosTable;
use Illuminate\Database\Seeder;

class PosSeeder extends Seeder
{
    public function run(): void
    {
        $bar = [
            'Beers' => [['Local Lager (33cl)', 1000], ['Imported Beer (33cl)', 1800]],
            'Spirits' => [['Whisky (single)', 3500], ['Vodka (single)', 3000]],
            'Soft Drinks' => [['Soft Drink (33cl)', 800], ['Bottled Water (50cl)', 500]],
        ];

        $restaurant = [
            'Starters' => [['Grilled Skewers', 3000], ['Vegetable Soup', 2000]],
            'Main Courses' => [['Grilled Fish & Plantain', 6500], ['Chicken Ndolé', 6000]],
            'Desserts' => [['Fruit Plate', 2000]],
        ];

        // Front Desk: receptionist petty sales — small walk-up items sold
        // at the reception counter, added post-9.7.4 at the user's
        // request. No PosTable rows below for this outlet: a counter
        // sale has no seating to assign (OrderController::store()'s
        // `pos_table_id` is already nullable).
        $frontDesk = [
            'Petty Sales' => [
                ['Bottled Water (50cl)', 500],
                ['Fruit Juice (33cl)', 1000],
                ['Soft Drink (33cl)', 800],
                ['Biscuits (pack)', 500],
                ['Snacks (assorted)', 700],
            ],
        ];

        // Bar Outside / Bucareaux (post-9.8 second fix round): two more
        // separately-run sales points — see
        // database/migrations/2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php.
        // Seeded with a small starter menu so the outlet is immediately
        // usable; the owner can add/rename items from the Menu screen
        // like any other outlet.
        $barOutside = [
            'Drinks' => [['Local Lager (33cl)', 1000], ['Soft Drink (33cl)', 800], ['Bottled Water (50cl)', 500]],
        ];

        $bucareaux = [
            'Items' => [['Soft Drink (33cl)', 800], ['Bottled Water (50cl)', 500], ['Snacks (assorted)', 700]],
        ];

        foreach ([
            'bar' => $bar,
            'restaurant' => $restaurant,
            'front_desk' => $frontDesk,
            'bar_outside' => $barOutside,
            'bucareaux' => $bucareaux,
        ] as $outlet => $categories) {
            foreach ($categories as $categoryName => $items) {
                $category = MenuCategory::firstOrCreate(['outlet' => $outlet, 'name' => $categoryName]);

                foreach ($items as [$itemName, $price]) {
                    MenuItem::firstOrCreate(
                        ['outlet' => $outlet, 'name' => $itemName],
                        ['menu_category_id' => $category->id, 'price' => $price],
                    );
                }
            }
        }

        foreach (range(1, 4) as $n) {
            PosTable::firstOrCreate(['outlet' => 'bar', 'label' => "Bar {$n}"], ['seats' => 4]);
        }
        foreach (range(1, 6) as $n) {
            PosTable::firstOrCreate(['outlet' => 'restaurant', 'label' => "T{$n}"], ['seats' => 4]);
        }
        foreach (range(1, 3) as $n) {
            PosTable::firstOrCreate(['outlet' => 'bar_outside', 'label' => "Out {$n}"], ['seats' => 4]);
        }
        // Bucareaux: a counter sale like Front Desk — no seating to assign.
    }
}
