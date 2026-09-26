<?php

namespace Tests\Feature\FrontOffice;

use App\Models\Facility;
use App\Models\FacilityAccess;
use App\Models\FacilityRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Broader request ("...edit, delete and update all categories and every
 * input, except financial inputs"): Facility/FacilityRate could
 * previously only be created (plus a rate enable/disable toggle).
 */
class FacilityEditDeleteTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function facility(): Facility
    {
        return Facility::create(['code' => 'gym', 'name' => 'Gym', 'is_active' => true]);
    }

    public function test_owner_can_update_a_facility(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $facility = $this->facility();

        $this->actingAs($owner)->put(route('facilities.update', $facility), [
            'code' => 'gym',
            'name' => 'Fitness Center',
            'description' => 'Renamed',
        ])->assertRedirect();

        $this->assertSame('Fitness Center', $facility->fresh()->name);
    }

    public function test_a_facility_with_rates_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $facility = $this->facility();
        FacilityRate::create(['facility_id' => $facility->id, 'name' => 'Registration', 'amount' => 5000, 'is_active' => true]);

        $response = $this->actingAs($owner)->delete(route('facilities.destroy', $facility));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('facilities', ['id' => $facility->id]);
    }

    public function test_owner_can_update_a_rates_amount(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $facility = $this->facility();
        $rate = FacilityRate::create(['facility_id' => $facility->id, 'name' => 'Slot', 'amount' => 2000, 'is_active' => true]);

        $this->actingAs($owner)->put(route('facilities.rates.update', $rate), [
            'name' => 'Slot',
            'amount' => 2500,
        ])->assertRedirect();

        $this->assertSame('2500.00', $rate->fresh()->amount);
    }

    public function test_a_rate_with_access_history_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $facility = $this->facility();
        $rate = FacilityRate::create(['facility_id' => $facility->id, 'name' => 'Slot', 'amount' => 2000, 'is_active' => true]);
        FacilityAccess::create([
            'access_number' => 'FA-TEST-1', 'facility_id' => $facility->id,
            'facility_rate_id' => $rate->id, 'amount_charged' => 2000,
            'visitor_name' => 'Walk In', 'granted_by' => $owner->id, 'granted_at' => now(),
        ]);

        $response = $this->actingAs($owner)->delete(route('facilities.rates.destroy', $rate));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('facility_rates', ['id' => $rate->id]);
    }

    public function test_duty_manager_can_edit_facilities_but_front_desk_cannot(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $facility = $this->facility();

        $this->actingAs($dutyManager)->put(route('facilities.update', $facility), [
            'code' => 'gym', 'name' => 'Renamed by DM',
        ])->assertRedirect();

        $frontDesk = $this->userWithRole('front_desk');
        $this->actingAs($frontDesk)->put(route('facilities.update', $facility), [
            'code' => 'gym', 'name' => 'Hacked',
        ])->assertForbidden();
    }
}
