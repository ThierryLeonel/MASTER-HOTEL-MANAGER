<?php

namespace Database\Seeders;

use App\Models\IncomeSource;
use Illuminate\Database\Seeder;

/**
 * Direct request ("let the income source be a dropdown... you should
 * identify all the income sources"), then a fast follow-up ("the list
 * should not be fixed... [staff] should be able to update, create and
 * delete it"): this starter set is exactly the list authored from
 * hotel-accounting practice for the dropdown's first version (see the
 * `income_sources` table's own migration for why it's scoped to
 * genuinely ad-hoc income only — nothing already tracked through its own
 * dedicated flow elsewhere, like room/folio charges, POS sales, gym/pool
 * facility access, or reservation deposits). It's seeded so a fresh
 * install has a usable dropdown on day one, but — per the follow-up
 * request — it is NOT hardcoded: Owner/General Manager/Accountant can
 * add, rename, or remove entries from the Income Sources screen at any
 * time, exactly like `financial_accounts`' own starter set already works.
 *
 * `firstOrCreate` (matching RolesAndPermissionsSeeder's own convention)
 * makes this idempotent — safe to re-run after a name someone already
 * added or edited, without duplicating rows or overwriting their edit.
 */
class IncomeSourceSeeder extends Seeder
{
    public const STARTER_SET = [
        'Rent',
        'Event hall / conference hire',
        'Guest advance payment',
        'Commission received',
        'Damage / breakage charge',
        'Lost and found (unclaimed item)',
        'Sponsorship / advertising',
        'Sale of scrap or used equipment',
        'Miscellaneous other income',
    ];

    public function run(): void
    {
        foreach (self::STARTER_SET as $name) {
            IncomeSource::firstOrCreate(['name' => $name]);
        }
    }
}
