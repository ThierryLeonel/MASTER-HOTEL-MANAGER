<?php

namespace Tests\Feature\Finance;

use App\Models\ChargeType;
use App\Models\Facility;
use App\Models\FacilityAccess;
use App\Models\Folio;
use App\Models\FolioTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Direct request (Sept 2026): "normally we have bar charge, restaurant
 * charge, frontdesk charge, rental charge, pool fee, Gym Fee, etc
 * include all connect them... just build it in such a way i can edit and
 * or just add or delete." Replaces the old FacilityEditDeleteTest.php
 * (that screen — 'facilities.*' / FacilityController — is retired; see
 * routes/web.php) with equivalent coverage of the new, unified Charge
 * Types admin screen, plus new coverage for the `is_system` protections
 * this unification introduced.
 */
class ChargeTypeManagementTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_owner_can_add_a_new_charge_type(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->post(route('charge-types.store'), [
            'name' => 'Rental Charge',
            'description' => 'Equipment or space rented out to a guest.',
        ])->assertRedirect();

        $chargeType = ChargeType::where('name', 'Rental Charge')->first();
        $this->assertNotNull($chargeType);
        $this->assertFalse($chargeType->is_system);
        $this->assertNotEmpty($chargeType->code, 'A code should be auto-generated, never left blank.');
        $this->assertTrue($chargeType->is_active);
    }

    public function test_a_new_walk_in_payable_charge_type_gets_a_working_shadow_facility(): void
    {
        // Direct request context: "pool fee, Gym Fee" — a brand NEW
        // walk-in-payable type (not just the two seeded ones) must also
        // satisfy facility_accesses' real NOT NULL facility_id foreign
        // key underneath — see ChargeTypeService's own docblock.
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($owner)->post(route('charge-types.store'), [
            'name' => 'Spa',
            'walk_in_payable' => '1',
            'postable_to_folio' => '0',
        ])->assertRedirect();

        $chargeType = ChargeType::where('name', 'Spa')->first();
        $this->assertNotNull($chargeType->legacy_facility_id);
        $this->assertTrue(Facility::where('id', $chargeType->legacy_facility_id)->exists());
    }

    public function test_owner_can_update_a_charge_types_name(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = ChargeType::where('code', 'bar_charge')->first();

        $this->actingAs($owner)->put(route('charge-types.update', $chargeType), [
            'name' => 'Bar Sales',
            'description' => 'Renamed',
        ])->assertRedirect();

        $chargeType->refresh();
        $this->assertSame('Bar Sales', $chargeType->name);
        // The code a system type relies on structurally is never touched
        // by an admin edit — see ChargeTypeService::update()'s docblock.
        $this->assertSame('bar_charge', $chargeType->code);
    }

    public function test_a_system_charge_type_cannot_be_deactivated(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $roomCharge = ChargeType::where('code', 'room_charge')->first();

        $this->actingAs($owner)->put(route('charge-types.update', $roomCharge), [
            'name' => 'Room (Lodging)',
            'is_active' => '0',
        ])->assertRedirect();

        $this->assertTrue($roomCharge->fresh()->is_active, 'A system charge type must stay active — deactivating it would silently break existing functionality.');
    }

    public function test_a_system_charge_type_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $roomCharge = ChargeType::where('code', 'room_charge')->first();

        $response = $this->actingAs($owner)->delete(route('charge-types.destroy', $roomCharge));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('charge_types', ['id' => $roomCharge->id]);
    }

    public function test_a_charge_type_with_folio_history_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = ChargeType::create(['code' => 'custom_test_charge', 'name' => 'Custom Test Charge', 'is_active' => true]);
        $folio = Folio::factory()->create();
        FolioTransaction::create([
            'folio_id' => $folio->id,
            'type' => 'custom_test_charge',
            'amount' => 1000,
            'description' => 'Test charge',
            'source_module' => 'front_office',
            'user_id' => $owner->id,
            'transaction_date' => now(),
        ]);

        $response = $this->actingAs($owner)->delete(route('charge-types.destroy', $chargeType));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('charge_types', ['id' => $chargeType->id]);
    }

    public function test_a_charge_type_with_rates_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = ChargeType::create(['code' => 'custom_test_charge_2', 'name' => 'Custom Test Charge 2', 'is_active' => true]);
        $chargeType->rates()->create(['name' => 'Standard', 'amount' => 1000, 'is_active' => true]);

        $response = $this->actingAs($owner)->delete(route('charge-types.destroy', $chargeType));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('charge_types', ['id' => $chargeType->id]);
    }

    public function test_an_unused_non_system_charge_type_can_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = ChargeType::create(['code' => 'custom_test_charge_3', 'name' => 'Custom Test Charge 3', 'is_active' => true]);

        $this->actingAs($owner)->delete(route('charge-types.destroy', $chargeType))->assertRedirect();

        $this->assertDatabaseMissing('charge_types', ['id' => $chargeType->id]);
    }

    public function test_owner_can_update_a_rates_amount(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = ChargeType::create(['code' => 'custom_rated_charge', 'name' => 'Custom Rated Charge', 'walk_in_payable' => true, 'is_active' => true]);
        $rate = $chargeType->rates()->create(['name' => 'Slot', 'amount' => 2000, 'is_active' => true]);

        $this->actingAs($owner)->put(route('charge-types.rates.update', $rate), [
            'name' => 'Slot',
            'amount' => 2500,
        ])->assertRedirect();

        $this->assertSame('2500.00', $rate->fresh()->amount);
    }

    public function test_a_rate_with_access_history_cannot_be_deleted(): void
    {
        $owner = $this->userWithRole('owner', ['auth_type' => 'password_only']);
        $chargeType = ChargeType::create(['code' => 'custom_rated_charge_2', 'name' => 'Custom Rated Charge 2', 'walk_in_payable' => true, 'is_active' => true]);
        $facility = Facility::create(['code' => 'CUST-TEST', 'name' => 'Custom Rated Charge 2', 'is_active' => true]);
        $chargeType->update(['legacy_facility_id' => $facility->id]);
        $facilityRate = $facility->rates()->create(['name' => 'Slot', 'amount' => 2000, 'is_active' => true]);
        $rate = $chargeType->rates()->create(['name' => 'Slot', 'amount' => 2000, 'is_active' => true, 'legacy_facility_rate_id' => $facilityRate->id]);
        FacilityAccess::create([
            'access_number' => 'FA-TEST-2', 'facility_id' => $facility->id,
            'facility_rate_id' => $facilityRate->id, 'amount_charged' => 2000,
            'visitor_name' => 'Walk In', 'granted_by' => $owner->id, 'granted_at' => now(),
        ]);

        $response = $this->actingAs($owner)->delete(route('charge-types.rates.destroy', $rate));

        $response->assertSessionHasErrors('error');
        $this->assertDatabaseHas('charge_type_rates', ['id' => $rate->id]);
    }

    public function test_duty_manager_can_edit_charge_types_but_front_desk_cannot(): void
    {
        $dutyManager = $this->userWithRole('duty_manager');
        $chargeType = ChargeType::where('code', 'bar_charge')->first();

        $this->actingAs($dutyManager)->put(route('charge-types.update', $chargeType), [
            'name' => 'Renamed by DM',
        ])->assertRedirect();

        $frontDesk = $this->userWithRole('front_desk');
        $this->actingAs($frontDesk)->put(route('charge-types.update', $chargeType), [
            'name' => 'Hacked',
        ])->assertForbidden();
    }
}
