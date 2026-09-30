<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * One-time data migration, run exactly once per database (Laravel tracks
 * completed migrations by filename — see the deploy-fix migration
 * earlier in this project for the same reasoning) — see
 * 2025_02_02_001100_create_charge_types_table.php's docblock for the
 * schema this fills in.
 *
 * Two things happen here, both guarded with existence checks so this is
 * still completely safe if it were ever somehow re-run:
 *
 *  1. The seven charge types this codebase already relies on by their
 *     exact `code` string get created as `is_system = true` rows:
 *     room_charge, bar_charge, restaurant_charge, front_desk_charge, and
 *     other_service (the old `FolioTransaction::CHARGE_TYPES` five) PLUS
 *     bar_outside_charge and bucareaux_charge. Those last two are a
 *     genuine, pre-existing gap found while building this: the "Post
 *     charge" form on a guest's active stay
 *     (front-office/stays/show.blade.php) already offered "Bar Outside
 *     charge" and "Bucareaux charge" as options, and
 *     Pos\OrderService::close() already tries to defer a Bar
 *     Outside/Bucareaux order to a folio under exactly these two type
 *     strings — but neither was ever in the old CHARGE_TYPES list, so
 *     both paths would fail with "Invalid charge type" the moment anyone
 *     actually used them. This migration closes that gap as a direct
 *     consequence of making the charge type list real, database-backed
 *     data instead of a hardcoded constant that quietly fell out of sync
 *     with the rest of the app.
 *
 *     `department_id` is left null here for all seven — see
 *     DepartmentSeeder::CHARGE_TYPE_DEPARTMENTS for why that backfill
 *     lives in the seeder instead (departments may not exist yet at
 *     migration time on a fresh install) and is safe to run any time
 *     after.
 *
 *  2. Every existing `Facility` row (Gym, Swimming Pool, or anything
 *     else a hotel already configured) gets a matching `charge_types`
 *     row created — `walk_in_payable = true`, `postable_to_folio =
 *     false` (matching how Facility access always worked: sold on the
 *     spot, never deferred to a folio; an operator can flip
 *     `postable_to_folio` on later from the new admin screen if they
 *     want a facility charged to a guest's room instead), linked back via
 *     `legacy_facility_id` — and every `FacilityRate` under it gets a
 *     matching `charge_type_rates` row, linked via
 *     `legacy_facility_rate_id`. The original `facilities`/
 *     `facility_rates`/`facility_accesses` tables and all their existing
 *     data are completely untouched by this — see ChargeTypeService's
 *     docblock for why the legacy tables keep being written to
 *     underneath the new admin screen, rather than being replaced
 *     outright.
 */
return new class extends Migration
{
    public function up(): void
    {
        $systemTypes = [
            ['code' => 'room_charge', 'name' => 'Room (Lodging)', 'description' => 'A guest\'s nightly room rate.'],
            ['code' => 'bar_charge', 'name' => 'Bar', 'description' => 'A bar tab deferred to a guest\'s folio.'],
            ['code' => 'restaurant_charge', 'name' => 'Restaurant', 'description' => 'A restaurant bill deferred to a guest\'s folio.'],
            ['code' => 'front_desk_charge', 'name' => 'Front Desk Sales', 'description' => 'A front-desk petty sale deferred to a guest\'s folio.'],
            ['code' => 'other_service', 'name' => 'Other Service', 'description' => 'Anything that doesn\'t fit one of the other categories.'],
            ['code' => 'bar_outside_charge', 'name' => 'Bar Outside', 'description' => 'A Bar Outside tab deferred to a guest\'s folio.'],
            ['code' => 'bucareaux_charge', 'name' => 'Bucareaux', 'description' => 'A Bucareaux tab deferred to a guest\'s folio.'],
        ];

        $now = now();

        foreach ($systemTypes as $type) {
            $exists = DB::table('charge_types')->where('code', $type['code'])->exists();
            if ($exists) {
                continue;
            }

            DB::table('charge_types')->insert([
                'ulid' => (string) Str::ulid(),
                'code' => $type['code'],
                'name' => $type['name'],
                'description' => $type['description'],
                'department_id' => null,
                'postable_to_folio' => true,
                'walk_in_payable' => false,
                'free_for_resident_guests' => false,
                'is_system' => true,
                'is_active' => true,
                'legacy_facility_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        if (! DB::getSchemaBuilder()->hasTable('facilities')) {
            return;
        }

        $facilities = DB::table('facilities')->get();

        foreach ($facilities as $facility) {
            $alreadyLinked = DB::table('charge_types')->where('legacy_facility_id', $facility->id)->exists();
            if ($alreadyLinked) {
                continue;
            }

            $chargeTypeId = DB::table('charge_types')->insertGetId([
                'ulid' => (string) Str::ulid(),
                // Facility codes are already unique and human-recognisable
                // (see the old FacilityController::generateCode()) — reused
                // as-is rather than generating a new one, so the two stay
                // visibly connected.
                'code' => $facility->code,
                'name' => $facility->name,
                'description' => $facility->description,
                'department_id' => null,
                'postable_to_folio' => false,
                'walk_in_payable' => true,
                'free_for_resident_guests' => true,
                'is_system' => false,
                'is_active' => $facility->is_active,
                'legacy_facility_id' => $facility->id,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            $rates = DB::table('facility_rates')->where('facility_id', $facility->id)->get();

            foreach ($rates as $rate) {
                DB::table('charge_type_rates')->insert([
                    'ulid' => (string) Str::ulid(),
                    'charge_type_id' => $chargeTypeId,
                    'name' => $rate->name,
                    'amount' => $rate->amount,
                    'is_active' => $rate->is_active,
                    'legacy_facility_rate_id' => $rate->id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Deliberately a no-op: this migration only ever creates
        // charge_types/charge_type_rates rows that mirror data owned
        // elsewhere (the seven system codes, or a copy of an existing
        // Facility/FacilityRate) — rolling it back would delete the very
        // rows every charge-type feature built on top of this migration
        // now depends on, with no safe way to know whether something
        // newer than this migration already references them. The
        // companion schema migration's own down() still drops the tables
        // entirely if that's genuinely what's needed.
    }
};
