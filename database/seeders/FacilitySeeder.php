<?php

namespace Database\Seeders;

use App\Models\ChargeType;
use App\Models\Facility;
use App\Models\FacilityRate;
use Illuminate\Database\Seeder;

/**
 * Post-9.8 second fix round (PHASE9_AUDIT_AND_PLAN.md §20). Seeds Gym
 * and Pool with one starter rate each so the feature is immediately
 * usable; these are demo/default amounts only (spec section 40 — never
 * fake financial history) and should be reviewed and adjusted from the
 * Charge Types screen ('manage-charge-types') before real use.
 *
 * Charge-types unification (Sept 2026 — see ChargeType's own docblock):
 * this used to create bare `facilities`/`facility_rates` rows directly.
 * The one-time `2025_02_02_001200_seed_charge_types_from_existing_system`
 * migration backfills a matching `charge_types`/`charge_type_rates` row
 * for every Facility that already exists AT MIGRATION TIME — fine for an
 * already-running hotel (Gym/Pool already existed long before this
 * feature shipped, so that migration already linked them), but on a
 * genuinely fresh install, migrations run before this seeder does, so
 * that backfill would find nothing and Gym/Pool would be created here
 * with no matching charge type — invisible to the new Charge Types
 * screen and to front-desk's facility-access forms, both of which now
 * read from `charge_types`, not `facilities`, directly. This seeder now
 * creates the ChargeType (and its shadow Facility, exactly like
 * ChargeTypeService::syncLegacyFacility() would do from the admin
 * screen) directly instead, so Gym/Pool work correctly no matter when
 * this runs relative to that migration. Fully idempotent — safe to
 * re-run on an install that already has either.
 */
class FacilitySeeder extends Seeder
{
    public function run(): void
    {
        $this->seedWalkInChargeType(
            code: 'gym',
            name: 'Gym',
            description: 'Guests: free access. Walk-ins: pay a registration fee at the front desk.',
            rateName: 'Gym Registration',
            rateAmount: 5000,
        );

        $this->seedWalkInChargeType(
            code: 'pool',
            name: 'Swimming Pool',
            description: 'Guests: free access. Walk-ins: pay per slot at the front desk before entry.',
            rateName: 'Pool Slot',
            rateAmount: 2000,
        );
    }

    private function seedWalkInChargeType(string $code, string $name, string $description, string $rateName, float $rateAmount): void
    {
        // Covers both starting states: the charge-types migration already
        // linked an existing Facility (already-running hotel), or nothing
        // exists yet at all (fresh install) — either way, this ends with
        // exactly one ChargeType/Facility pair for this code.
        $chargeType = ChargeType::where('code', $code)->first();

        if (! $chargeType) {
            $facility = Facility::firstOrCreate(
                ['code' => $code],
                ['name' => $name, 'description' => $description, 'is_active' => true],
            );

            $chargeType = ChargeType::create([
                'code' => $code,
                'name' => $name,
                'description' => $description,
                'postable_to_folio' => false,
                'walk_in_payable' => true,
                'free_for_resident_guests' => true,
                'is_system' => false,
                'is_active' => true,
                'legacy_facility_id' => $facility->id,
            ]);
        }

        if ($chargeType->rates()->where('name', $rateName)->exists()) {
            return;
        }

        $facilityRate = null;
        if ($chargeType->legacy_facility_id !== null) {
            $facilityRate = FacilityRate::firstOrCreate(
                ['facility_id' => $chargeType->legacy_facility_id, 'name' => $rateName],
                ['amount' => $rateAmount, 'is_active' => true],
            );
        }

        $chargeType->rates()->create([
            'name' => $rateName,
            'amount' => $rateAmount,
            'is_active' => true,
            'legacy_facility_rate_id' => $facilityRate?->id,
        ]);
    }
}
