<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Charge-types unification (Sept 2026 — see ChargeType's own docblock):
 * `folio_transactions.type` used to be a MySQL ENUM with a fixed list of
 * allowed values, widened by hand in code every time a new charge type or
 * transaction kind was introduced (see
 * 2025_01_02_000800_add_front_desk_outlet_support.php,
 * 2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php, and the
 * migration that added 'credit_settlement' — each is the exact same
 * "MySQL ENUM widening / SQLite string column" pattern this migration
 * follows).
 *
 * That approach cannot support this feature's whole point: "just build it
 * in such a way i can edit and or just add or delete." An admin adding a
 * brand new charge type from the Charge Types screen — with no code
 * deploy — would otherwise hit a hard database error the moment anyone
 * tried to actually post that new type to a folio, because MySQL would
 * refuse any string not already baked into the fixed ENUM list. This was
 * found and fixed during this same unification, before it could ever
 * surface as a "charge posted fine in the form, then failed on save"
 * report from the field.
 *
 * The fix: convert the column to a plain VARCHAR — a pure widening,
 * identical in spirit to every ENUM-widening migration before it, just
 * done once, permanently, instead of by hand every time a type is added.
 * Every existing value already fits (VARCHAR is a strict superset of what
 * the ENUM allowed), so this changes no existing data and needs no
 * backfill — it only removes the artificial cap on which strings MySQL
 * will accept here. SQLite has already been a plain, uncapped string
 * column for this field the whole time (see every prior ENUM-widening
 * migration's own "SQLite" comment), so this brings MySQL in line with
 * SQLite's existing, already-safe behaviour rather than the other way
 * around.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->isSqlite()) {
            Schema::table('folio_transactions', function (Blueprint $t) {
                $t->string('type', 60)->change();
            });

            return;
        }

        DB::statement('ALTER TABLE folio_transactions MODIFY COLUMN type VARCHAR(60) NOT NULL');
    }

    public function down(): void
    {
        // Deliberately a no-op: reverting to a fixed ENUM would risk
        // failing outright if any admin-added charge type's code (not
        // knowable at migration-authoring time, and different on every
        // installation) has since been posted to a real folio — the same
        // "never risk rewriting/breaking real financial history"
        // reasoning used throughout this engagement. A plain string
        // column that happens to accept a wider range of values than the
        // old ENUM did is not a correctness risk in either direction, so
        // there is nothing that genuinely needs undoing.
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
