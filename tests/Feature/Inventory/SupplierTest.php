<?php

namespace Tests\Feature\Inventory;

use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request ("I want the accountant to be able to ... also the
 * suppliers too"): the accountant previously held no inventory
 * permissions at all — 'manage-suppliers' gates both viewing and
 * creating suppliers (a single permission for the whole screen, no
 * separate approval tier, unlike purchase orders).
 */
class SupplierTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_accountant_can_now_create_a_supplier(): void
    {
        $accountant = $this->userWithRole('accountant');

        $response = $this->actingAs($accountant)->post(route('inventory.suppliers.store'), [
            'name' => 'Douala Fresh Produce Ltd',
            'contact_name' => 'Jean Paul',
            'phone' => '+237699000111',
            'email' => 'orders@doualafresh.example.com',
        ]);

        $response->assertRedirect();
        $this->assertDatabaseHas('suppliers', ['name' => 'Douala Fresh Produce Ltd']);
    }

    public function test_accountant_can_view_the_suppliers_list(): void
    {
        $accountant = $this->userWithRole('accountant');
        Supplier::factory()->create(['name' => 'Yaounde Linens Co']);

        $response = $this->actingAs($accountant)->get(route('inventory.suppliers.index'));

        $response->assertOk();
        $response->assertSee('Yaounde Linens Co');
    }

    public function test_a_role_with_no_inventory_permission_still_cannot_create_a_supplier(): void
    {
        $cashier = $this->userWithRole('cashier');

        $response = $this->actingAs($cashier)->post(route('inventory.suppliers.store'), [
            'name' => 'Should Not Be Created',
        ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('suppliers', ['name' => 'Should Not Be Created']);
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs").
     */
    public function test_accountant_can_update_a_supplier(): void
    {
        $accountant = $this->userWithRole('accountant');
        $supplier = Supplier::factory()->create(['name' => 'Old Name']);

        $this->actingAs($accountant)->put(route('inventory.suppliers.update', $supplier), [
            'name' => 'New Name',
            'is_active' => '0',
        ])->assertRedirect();

        $supplier->refresh();
        $this->assertSame('New Name', $supplier->name);
        $this->assertFalse($supplier->is_active);
    }

    public function test_a_supplier_with_purchase_order_history_cannot_be_deleted(): void
    {
        $accountant = $this->userWithRole('accountant');
        $supplier = Supplier::factory()->create();
        \App\Models\PurchaseOrder::create([
            'po_number' => 'PO-SUP-1', 'supplier_id' => $supplier->id,
            'status' => 'draft', 'ordered_by' => $accountant->id,
        ]);

        $response = $this->actingAs($accountant)->delete(route('inventory.suppliers.destroy', $supplier));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('suppliers', ['id' => $supplier->id]);
    }

    public function test_an_unused_supplier_can_be_deleted(): void
    {
        $accountant = $this->userWithRole('accountant');
        $supplier = Supplier::factory()->create();

        $this->actingAs($accountant)->delete(route('inventory.suppliers.destroy', $supplier))->assertRedirect();

        $this->assertDatabaseMissing('suppliers', ['id' => $supplier->id]);
    }
}
