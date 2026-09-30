<?php

namespace Tests\Feature\FrontOffice;

use App\Models\ChargeType;
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
 *
 * Charge-types unification (Sept 2026): Gym/Pool are now two entries on
 * the unified, admin-editable Charge Types list (see ChargeType's own
 * docblock) rather than their own separate Facility model directly — this
 * test now drives the front-desk forms via `charge_type_id`/
 * `charge_type_rate_id`, matching FacilityAccessController's updated
 * validation, while still proving the underlying `facility_accesses` rows
 * (and their real, tested NOT NULL `facility_id` foreign key) are written
 * exactly as before under the hood.
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
        $gym = ChargeType::where('code', 'gym')->first();

        $this->actingAs($frontDesk)->post(route('facility-access.guest'), [
            'charge_type_id' => $gym->id,
            'stay_id' => $stay->id,
        ])->assertRedirect();

        $access = FacilityAccess::first();
        $this->assertSame($stay->id, $access->stay_id);
        $this->assertSame('0.00', $access->amount_charged);
        $this->assertTrue($access->isFree());
        // Written against the real, shadow Facility row underneath — the
        // whole point of the "unification is invisible to
        // facility_accesses' own schema" design (see ChargeTypeService's
        // docblock).
        $this->assertSame($gym->legacy_facility_id, $access->facility_id);
        $this->assertSame(0, Payment::count(), 'Free guest access must not create a payment.');
    }

    public function test_front_desk_cannot_grant_free_access_for_a_stay_that_is_not_active(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = Stay::factory()->create(['status' => 'closed']);
        $gym = ChargeType::where('code', 'gym')->first();

        $response = $this->actingAs($frontDesk)->post(route('facility-access.guest'), [
            'charge_type_id' => $gym->id,
            'stay_id' => $stay->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, FacilityAccess::count());
    }

    public function test_free_access_is_refused_for_a_charge_type_not_marked_free_for_resident_guests(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $stay = Stay::factory()->create(['status' => 'active']);
        // A folio-postable charge type (e.g. bar_charge) is never
        // walk-in-payable/free-for-guests — it has no legacy_facility_id
        // and free_for_resident_guests is false by default.
        $barCharge = ChargeType::where('code', 'bar_charge')->first();

        $response = $this->actingAs($frontDesk)->post(route('facility-access.guest'), [
            'charge_type_id' => $barCharge->id,
            'stay_id' => $stay->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, FacilityAccess::count());
    }

    public function test_front_desk_sells_pool_access_to_a_walk_in_and_posts_a_payment(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $pool = ChargeType::where('code', 'pool')->first();
        $rate = $pool->rates()->first();

        $this->actingAs($frontDesk)->post(route('facility-access.walk-in'), [
            'charge_type_id' => $pool->id,
            'charge_type_rate_id' => $rate->id,
            'visitor_name' => 'Jean Walk-in',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ])->assertRedirect();

        $access = FacilityAccess::first();
        $this->assertFalse($access->isFree());
        $this->assertSame('Jean Walk-in', $access->visitor_name);
        $this->assertEquals($rate->amount, $access->amount_charged);
        $this->assertSame($pool->legacy_facility_id, $access->facility_id);
        $this->assertSame($rate->legacy_facility_rate_id, $access->facility_rate_id);
        $this->assertNotNull($access->payment_id);

        $payment = Payment::find($access->payment_id);
        $this->assertSame('in', $payment->direction);
        $this->assertSame('facility_access', $payment->source_module);

        // Gym/Pool have no department_id by default (unchanged from
        // before this feature) — PaymentLedgerService::record() then
        // falls back to Front Office, exactly as it always has.
        $fo = \App\Models\Department::where('code', 'FO')->first();
        $this->assertSame($fo->id, $payment->department_id, 'Facility sales at the front desk should be attributed to the Front Office department.');
    }

    public function test_a_rate_belonging_to_a_different_charge_type_is_rejected(): void
    {
        $frontDesk = $this->userWithRole('front_desk');
        $gym = ChargeType::where('code', 'gym')->first();
        $pool = ChargeType::where('code', 'pool')->first();
        $poolRate = $pool->rates()->first();

        $response = $this->actingAs($frontDesk)->post(route('facility-access.walk-in'), [
            'charge_type_id' => $gym->id,
            'charge_type_rate_id' => $poolRate->id,
            'visitor_name' => 'Mismatched Rate',
            'financial_account_id' => $this->financialAccount('CASH')->id,
        ]);

        $response->assertSessionHasErrors('error');
        $this->assertSame(0, FacilityAccess::count());
    }
}
