<?php

namespace Database\Seeders;

use App\Models\ChargeType;
use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
    /**
     * Sept 2026 (unified charge types): which of the system charge types
     * seeded by 2025_02_02_001200_seed_charge_types_from_existing_system.php
     * belong to which department — the same well-established, structural
     * facts already relied on elsewhere (e.g.
     * DepartmentReconciliationReportService::FRONT_OFFICE_OWN_CHARGE_TYPES/
     * DEPARTMENT_OUTLET). Kept here, not only in that migration, because a
     * fresh install may run migrations before this seeder ever runs —
     * departments might not exist yet when the charge_types migration
     * seeds its rows, leaving `department_id` null. Re-running this
     * (already idempotent, already re-run via the Deploy Helper's
     * "update roles & permissions"-style buttons) backfills it correctly
     * regardless of which order migrate/seed ran in, and is a harmless
     * no-op once every row already has its department set.
     */
    private const CHARGE_TYPE_DEPARTMENTS = [
        'room_charge' => 'FO',
        'front_desk_charge' => 'FO',
        'other_service' => 'FO',
        'bar_charge' => 'BAR',
        'restaurant_charge' => 'REST',
        'bar_outside_charge' => 'BAROUT',
        'bucareaux_charge' => 'BUCA',
    ];

    public function run(): void
    {
        $departments = [
            ['name' => 'Front Office', 'code' => 'FO', 'description' => 'Reservations, check-in/out, guest folios'],
            ['name' => 'Housekeeping', 'code' => 'HK', 'description' => 'Room cleaning and inspection'],
            ['name' => 'Maintenance', 'code' => 'MAINT', 'description' => 'Facility upkeep and repairs'],
            ['name' => 'Bar', 'code' => 'BAR', 'description' => 'Bar point of sale'],
            ['name' => 'Restaurant', 'code' => 'REST', 'description' => 'Restaurant point of sale'],
            // Post-9.8 second fix round: two more separately-run sales
            // points ("their sales and stocks are managed separately") —
            // see database/migrations/2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php
            // and PHASE9_AUDIT_AND_PLAN.md §17. Used for cash-department
            // attribution (Payment::department_id) and the new department
            // reconciliation report, same as every department below.
            ['name' => 'Bar Outside', 'code' => 'BAROUT', 'description' => 'Outdoor/annex bar point of sale, run separately from the main Bar'],
            ['name' => 'Bucareaux', 'code' => 'BUCA', 'description' => 'Bucareaux point of sale, run separately with its own sales and stock'],
            ['name' => 'Finance', 'code' => 'FIN', 'description' => 'Accounting, payments, reconciliation'],
            ['name' => 'Management', 'code' => 'MGMT', 'description' => 'Owner, General Manager, Duty Manager'],
        ];

        foreach ($departments as $department) {
            Department::firstOrCreate(['code' => $department['code']], $department);
        }

        // Sept 2026 (unified charge types) — see CHARGE_TYPE_DEPARTMENTS'
        // own docblock above. Guarded with Schema::hasTable() so this
        // seeder still runs cleanly on a database where the charge_types
        // migration hasn't been applied yet (shouldn't happen in normal
        // migrate-then-seed order, but this project's own history has
        // already shown migrations and seeders can run out of the
        // expected order on a live host).
        if (\Illuminate\Support\Facades\Schema::hasTable('charge_types')) {
            foreach (self::CHARGE_TYPE_DEPARTMENTS as $code => $departmentCode) {
                $chargeType = ChargeType::where('code', $code)->whereNull('department_id')->first();
                if (! $chargeType) {
                    continue;
                }

                $departmentId = Department::where('code', $departmentCode)->value('id');
                if ($departmentId !== null) {
                    $chargeType->update(['department_id' => $departmentId]);
                }
            }
        }
    }
}
