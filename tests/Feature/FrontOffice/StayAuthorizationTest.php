<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Folio;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.0 security fix: stays.index and stays.show previously had no
 * permission gate at all (routes/web.php) — only the checkout POST was
 * gated. StayController::show() loads and the view renders the guest's
 * full folio balance, transaction ledger, and outstanding Accounts
 * Receivable, so this was a genuine financial-data exposure to any
 * authenticated role, including bar/restaurant staff and cashier. Both
 * routes are now gated on 'manage-guests', matching GuestController.
 */
class StayAuthorizationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function activeStayWithBalance(): Stay
    {
        $room = Room::factory()->create(['status' => 'occupied']);
        $stay = Stay::factory()->for($room)->create(['status' => 'active']);
        Folio::factory()->for($stay)->create(['status' => 'open', 'balance' => 45000]);

        return $stay;
    }

    /** @dataProvider rolesThatCanViewStays */
    public function test_roles_with_manage_guests_permission_can_view_the_active_stays_list(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);
        $this->activeStayWithBalance();

        $response = $this->actingAs($user)->get(route('stays.index'));

        $response->assertOk();
    }

    /** @dataProvider rolesThatCanViewStays */
    public function test_roles_with_manage_guests_permission_can_open_a_guest_folio(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithBalance();

        $response = $this->actingAs($user)->get(route('stays.show', $stay));

        $response->assertOk();
        $response->assertSee('45,000');
    }

    public static function rolesThatCanViewStays(): array
    {
        return [
            ['owner'],
            ['general_manager'],
            ['duty_manager'],
            ['front_desk'],
        ];
    }

    /** @dataProvider rolesThatCannotViewStays */
    public function test_roles_without_manage_guests_permission_cannot_view_the_active_stays_list(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);
        $this->activeStayWithBalance();

        $response = $this->actingAs($user)->get(route('stays.index'));

        $response->assertForbidden();
    }

    /** @dataProvider rolesThatCannotViewStays */
    public function test_roles_without_manage_guests_permission_cannot_open_a_guest_folio(string $role): void
    {
        $user = $this->userWithRole($role, ['auth_type' => 'password_only']);
        $stay = $this->activeStayWithBalance();

        $response = $this->actingAs($user)->get(route('stays.show', $stay));

        $response->assertForbidden();
        // Defense-in-depth check: the financial figure must never even be
        // present in the response body for a forbidden request.
        $response->assertDontSee('45,000');
    }

    public static function rolesThatCannotViewStays(): array
    {
        return [
            ['accountant'],
            ['cashier'],
            ['bar_staff'],
            ['restaurant_staff'],
        ];
    }
}
