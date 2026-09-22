<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Guest;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Post-9.8 fix round ("a text box that has the characteristic to search
 * from the existing guest list") — GuestController::search(), the small
 * JSON lookup behind the check-in/reservation/payments "search or add
 * guest" widgets.
 */
class GuestSearchTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_searching_by_name_returns_matching_guests(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        Guest::factory()->create(['first_name' => 'Amara', 'last_name' => 'Okafor', 'phone' => '699000111']);
        Guest::factory()->create(['first_name' => 'Brice', 'last_name' => 'Mballa', 'phone' => '677000222']);

        $response = $this->actingAs($frontDesk)->getJson(route('guests.search', ['q' => 'Amara']));

        $response->assertOk();
        $response->assertJsonCount(1);
        $response->assertJsonFragment(['full_name' => 'Amara Okafor']);
    }

    public function test_searching_by_phone_returns_matching_guests(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        Guest::factory()->create(['first_name' => 'Amara', 'last_name' => 'Okafor', 'phone' => '699000111']);

        $response = $this->actingAs($frontDesk)->getJson(route('guests.search', ['q' => '699000']));

        $response->assertOk();
        $response->assertJsonCount(1);
    }

    public function test_an_empty_query_returns_no_results_rather_than_the_whole_guest_list(): void
    {
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        Guest::factory()->count(3)->create();

        $response = $this->actingAs($frontDesk)->getJson(route('guests.search'));

        $response->assertOk();
        $response->assertJsonCount(0);
    }

    public function test_a_role_without_manage_guests_cannot_search(): void
    {
        $barStaff = $this->pinUser('bar_staff');
        Guest::factory()->create(['first_name' => 'Amara']);

        $response = $this->actingAs($barStaff)->getJson(route('guests.search', ['q' => 'Amara']));

        $response->assertForbidden();
    }
}
