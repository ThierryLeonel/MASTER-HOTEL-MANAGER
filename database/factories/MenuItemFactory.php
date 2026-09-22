<?php

namespace Database\Factories;

use App\Models\MenuCategory;
use Illuminate\Database\Eloquent\Factories\Factory;

class MenuItemFactory extends Factory
{
    public function definition(): array
    {
        $category = MenuCategory::factory()->create();

        return [
            'menu_category_id' => $category->id,
            'outlet' => $category->outlet,
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'price' => fake()->randomElement([1500, 2500, 5000, 8000]),
            'is_active' => true,
        ];
    }

    public function forOutlet(string $outlet): static
    {
        return $this->state(function () use ($outlet) {
            $category = MenuCategory::factory()->create(['outlet' => $outlet]);

            return ['menu_category_id' => $category->id, 'outlet' => $outlet];
        });
    }
}
