<?php

namespace Tests\Feature\FrontOffice;

use App\Models\FacilityAccess;
use App\Models\Payment;
use App\Models\Stay;
use Database\Seeders\FacilitySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Gym & Swimming Pool (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md
 * §20) — free access for checked-in guests, paid access for walk-ins,
 * both granted at the front desk.
 */
class FacilityAccessTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->seed(FacilitySeeder::class);

        // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): Front
        // Office now refuses a facility grant/sale without an open shift.
        $this->openShiftFor('FO');
    }

    public function test_front_desk_grants_free_access_to_a_checked_in_guest(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = Stay::factory()->create(['status' => 'active']);
        $gym = \App\Models\Facility::where('code', 'gym')->first();

        $this->actingAs($frontDesk)->post(route('facility-access.guest'), [
            'facility_id' => $gym->id,
            'stay_id' => $stay->id,
        ])->assertRedirect();

        $access = FacilityAccess::first();
        $this->assertSame($stay->id, $access->stay_id);
        $this->assertSame('0.00', $access->amount_charged);
        $this->assertTrue($access->isFree());
        $this->assertSame(0, Payment::count(), 'Free guest access must not create a payment.');
    }

    public function test_front_desk_cannot_grant_free_access_for_a_stay_that_is_not_active(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = Stay::factory()->create(['status' => 'closed']);
        $gym = \App\Models\Facility::where('code', 'gym')->first();

        $response = $this->actingAs($frontDesk)->post(route('facility-access.guest'), [
            'facility_id' => $gym->id,
            'stay_id' => $stay->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, FacilityAccess::count());
    }

    public function test_front_desk_sells_pool_access_to_a_walk_in_and_posts_a_payment(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $pool = \App\Models\Facility::where('code', 'pool')->first();
        $rate = $pool->rates()->first();

        $this->actingAs($frontDesk)->post(route('facility-access.walk-in'), [
            'facility_id' => $pool->id,
            'facility_rate_id' => $rate->id,
            'visitor_name' => 'Jean Walk-in',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $access = FacilityAccess::first();
        $this->assertFalse($access->isFree());
        $this->assertSame('Jean Walk-in', $access->visitor_name);
        $this->assertEquals($rate->amount, $access->amount_charged);
        $this->assertNotNull($access->payment_id);

        $payment = Payment::find($access->payment_id);
        $this->assertSame('in', $payment->direction);
        $this->assertSame('facility_access', $payment->source_module);

        $fo = \App\Models\Department::where('code', 'FO')->first();
        $this->assertSame($fo->id, $payment->department_id, 'Facility sales at the front desk should be attributed to the Front Office department.');
    }

    public function test_a_rate_belonging_to_a_different_facility_is_rejected(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $gym = \App\Models\Facility::where('code', 'gym')->first();
        $pool = \App\Models\Facility::where('code', 'pool')->first();
        $poolRate = $pool->rates()->first();

        $response = $this->actingAs($frontDesk)->post(route('facility-access.walk-in'), [
            'facility_id' => $gym->id,
            'facility_rate_id' => $poolRate->id,
            'visitor_name' => 'Mismatched Rate',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, FacilityAccess::count());
    }
}
