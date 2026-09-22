<?php

namespace Tests\Feature\Admin;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * New requirement 5 ("Marketing Manager role and dashboard"): the role
 * grants front-office/guest-facing management capabilities (reservations,
 * discount and housekeeping approval, guest payments, guest history) but
 * spec explicitly excludes every POS/menu/inventory-selling capability —
 * "explicitly never sells/settles POS orders, manages the menu, or sells
 * inventory". These tests hit the real routes directly (never merely
 * checking whether a nav link renders) so the exclusion is proven
 * server-side, per the brief's explicit "test direct unauthorized
 * requests not just hidden links" instruction.
 */
class MarketingManagerRoleTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function marketingManager()
    {
        return $this->userWithRole('marketing_manager');
    }

    public function test_marketing_manager_can_view_the_dashboard(): void
    {
        $this->actingAs($this->marketingManager())->get(route('dashboard'))->assertOk();
    }

    public function test_marketing_manager_can_approve_a_reservation(): void
    {
        $manager = $this->marketingManager();
        $category = RoomCategory::factory()->create(['base_rate' => 10000]);
        $reservation = Reservation::factory()->create([
            'guest_id' => Guest::factory()->create()->id,
            'room_category_id' => $category->id,
            'rate_quoted' => 10000,
            'status' => 'pending',
        ]);

        $this->actingAs($manager)->post(route('reservations.approve', $reservation))->assertRedirect();

        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_marketing_manager_can_view_pending_discount_approvals(): void
    {
        $this->actingAs($this->marketingManager())->get(route('discount-approvals.index'))->assertOk();
    }

    public function test_marketing_manager_can_view_pending_housekeeping(): void
    {
        $this->actingAs($this->marketingManager())->get(route('housekeeping.index'))->assertOk();
    }

    public function test_marketing_manager_can_view_guest_transaction_history(): void
    {
        $guest = Guest::factory()->create();

        $this->actingAs($this->marketingManager())->get(route('reports.guest-transaction-history.show', $guest))->assertOk();
    }

    /**
     * @dataProvider posOutlets
     */
    public function test_marketing_manager_cannot_open_a_pos_order_for_any_outlet(string $outlet): void
    {
        $this->actingAs($this->marketingManager())
            ->post(route('pos.orders.store', $outlet), [])
            ->assertForbidden();
    }

    public static function posOutlets(): array
    {
        return [
            ['bar'], ['restaurant'], ['front_desk'], ['bar_outside'], ['bucareaux'],
        ];
    }

    public function test_marketing_manager_cannot_view_the_pos_screen_for_any_outlet(): void
    {
        $this->actingAs($this->marketingManager())->get(route('pos.index', 'restaurant'))->assertForbidden();
    }

    public function test_marketing_manager_cannot_manage_the_menu(): void
    {
        $this->actingAs($this->marketingManager())->get(route('pos.menu.index', 'restaurant'))->assertForbidden();
    }

    public function test_marketing_manager_cannot_manage_products_or_suppliers(): void
    {
        $manager = $this->marketingManager();

        $this->actingAs($manager)->get(route('inventory.products.index'))->assertForbidden();
        $this->actingAs($manager)->get(route('inventory.suppliers.index'))->assertForbidden();
    }

    public function test_marketing_manager_cannot_redeem_a_complimentary_breakfast(): void
    {
        // operate-restaurant-pos gates this too — same "no POS rights at
        // all" boundary, exercised through a different module's route.
        $this->actingAs($this->marketingManager())->get(route('breakfast.index'))->assertForbidden();
    }
}
