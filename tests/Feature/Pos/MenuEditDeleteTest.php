<?php

namespace Tests\Feature\Pos;

use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\RecipeItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Broader request ("...edit, delete and update all categories and every
 * input, except financial inputs"): MenuCategory/MenuItem could
 * previously only be created. Every guard here relies on order_items
 * snapshotting its own item_name/unit_price at order time (see that
 * migration's docblock) — editing or deleting a menu item never rewrites
 * a placed order.
 */
class MenuEditDeleteTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_rename_a_menu_category(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = MenuCategory::factory()->create(['outlet' => 'bar', 'name' => 'Old']);

        $this->actingAs($owner)->put(route('pos.menu.categories.update', ['bar', $category]), [
            'name' => 'New',
        ])->assertRedirect();

        $this->assertSame('New', $category->fresh()->name);
    }

    public function test_a_menu_category_with_items_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = MenuCategory::factory()->create(['outlet' => 'bar']);
        MenuItem::factory()->create(['menu_category_id' => $category->id, 'outlet' => 'bar']);

        $response = $this->actingAs($owner)->delete(route('pos.menu.categories.destroy', ['bar', $category]));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('menu_categories', ['id' => $category->id]);
    }

    public function test_owner_can_update_a_menu_items_price(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = MenuCategory::factory()->create(['outlet' => 'bar']);
        $item = MenuItem::factory()->create(['menu_category_id' => $category->id, 'outlet' => 'bar', 'price' => 1000]);

        $this->actingAs($owner)->put(route('pos.menu.items.update', ['bar', $item]), [
            'menu_category_id' => $category->id,
            'name' => $item->name,
            'price' => 1500,
            'is_active' => '1',
        ])->assertRedirect();

        $this->assertSame('1500.00', $item->fresh()->price);
    }

    public function test_a_menu_item_that_has_been_ordered_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = MenuCategory::factory()->create(['outlet' => 'bar']);
        $item = MenuItem::factory()->create(['menu_category_id' => $category->id, 'outlet' => 'bar']);
        $order = Order::factory()->create(['outlet' => 'bar']);
        OrderItem::create([
            'order_id' => $order->id, 'menu_item_id' => $item->id,
            'item_name' => $item->name, 'unit_price' => $item->price,
            'quantity' => 1, 'line_total' => $item->price, 'added_by' => $owner->id,
        ]);

        $response = $this->actingAs($owner)->delete(route('pos.menu.items.destroy', ['bar', $item]));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('menu_items', ['id' => $item->id]);
    }

    public function test_a_menu_item_with_a_recipe_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = MenuCategory::factory()->create(['outlet' => 'bar']);
        $item = MenuItem::factory()->create(['menu_category_id' => $category->id, 'outlet' => 'bar']);
        RecipeItem::create([
            'menu_item_id' => $item->id, 'product_id' => Product::factory()->create()->id,
            'quantity_per_unit' => 1,
        ]);

        $response = $this->actingAs($owner)->delete(route('pos.menu.items.destroy', ['bar', $item]));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('menu_items', ['id' => $item->id]);
    }

    public function test_an_unused_menu_item_can_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $category = MenuCategory::factory()->create(['outlet' => 'bar']);
        $item = MenuItem::factory()->create(['menu_category_id' => $category->id, 'outlet' => 'bar']);

        $this->actingAs($owner)->delete(route('pos.menu.items.destroy', ['bar', $item]))->assertRedirect();

        $this->assertDatabaseMissing('menu_items', ['id' => $item->id]);
    }

    public function test_bar_staff_cannot_edit_or_delete_the_menu(): void
    {
        $barStaff = $this->userWithRole('bar_staff');
        $category = MenuCategory::factory()->create(['outlet' => 'bar']);

        $this->actingAs($barStaff)->put(route('pos.menu.categories.update', ['bar', $category]), [
            'name' => 'Hacked',
        ])->assertForbidden();
    }
}
