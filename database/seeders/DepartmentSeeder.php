<?php

namespace Database\Seeders;

use App\Models\Department;
use Illuminate\Database\Seeder;

class DepartmentSeeder extends Seeder
{
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
    }
}
