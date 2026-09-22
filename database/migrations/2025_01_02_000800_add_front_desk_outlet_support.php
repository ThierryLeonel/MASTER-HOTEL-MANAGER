<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Front desk (receptionist) petty sales — juice, water, snacks,
 * biscuits, etc. — added after Phase 9.7.4 at the user's explicit
 * request ("the front desk will also be doing petty sale of things
 * like juice, water, snacks, biscuits").
 *
 * Design decision (documented in full in PHASE9_AUDIT_AND_PLAN.md §8):
 * rather than build a parallel "till" feature, this reuses the existing
 * Bar/Restaurant POS module verbatim — OrderController/OrderService/
 * MenuItem/PosTable/Order/OrderItem are already outlet-parametrized
 * (`operate-{outlet}-pos`, `WHERE outlet = ?`), so a third outlet value
 * is additive, not a new code path. Front desk counter sales simply
 * never set `pos_table_id` (already nullable — see OrderController::
 * store()'s validation), so no PosTable rows are needed for this
 * outlet.
 *
 * `outlet` and `folio_transactions.type` are native MySQL ENUM columns
 * (Blueprint::enum()), which Laravel's schema builder cannot widen
 * in-place without doctrine/dbal — hence the raw MODIFY COLUMN
 * statements below rather than a Schema::table() closure. Each list
 * preserves every existing value in its original order and only appends
 * the new one, so no existing row's value becomes invalid.
 *
 * Driver-aware fix: `phpunit.xml` runs the test suite against SQLite,
 * where these same columns were created via Blueprint::enum() too, but
 * SQLite has no ENUM type at all — Laravel's SQLite grammar represents
 * it as a plain column with a CHECK(... IN (...)) constraint, and SQLite
 * supports neither `ALTER TABLE ... MODIFY COLUMN` nor dropping/altering
 * a CHECK constraint in place. Running the MySQL-only statements above
 * against SQLite fails outright, which previously made this migration —
 * and therefore the whole suite — impossible to run under the configured
 * test database. On SQLite we instead widen the column with a plain
 * `change()`, which Laravel performs natively (no doctrine/dbal needed,
 * supported since Laravel 11) by rebuilding the table under the hood.
 * That drops the old CHECK's enumeration down to an ordinary string
 * column instead of a wider one — SQLite has no enum to begin with, so
 * nothing the application layer depends on (HmsException-based business
 * rules, not the database, already validate `outlet`/`type` values) is
 * weakened by this, and the schema stays perfectly valid for every
 * existing and new 'front_desk' row used throughout the test suite.
 * Production (MySQL) keeps the original enum-widening behavior and
 * reversibility unchanged.
 */
return new class extends Migration
{
    private const TABLES = [
        'menu_categories' => "ENUM('bar', 'restaurant', 'front_desk')",
        'menu_items' => "ENUM('bar', 'restaurant', 'front_desk')",
        'pos_tables' => "ENUM('bar', 'restaurant', 'front_desk')",
        'orders' => "ENUM('bar', 'restaurant', 'front_desk')",
    ];

    public function up(): void
    {
        if ($this->isSqlite()) {
            $this->widenOnSqlite();

            return;
        }

        foreach (self::TABLES as $table => $enumDef) {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN outlet {$enumDef} NOT NULL");
        }

        DB::statement("ALTER TABLE folio_transactions MODIFY COLUMN type ENUM(
            'room_charge', 'bar_charge', 'restaurant_charge', 'front_desk_charge', 'other_service',
            'payment', 'refund', 'adjustment'
        ) NOT NULL");
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            // No-op: on SQLite the column is already a plain string (no
            // enum to begin with), and re-narrowing it would fail the
            // same way an enum-narrowing rollback would on MySQL once
            // any row holds 'front_desk' — SQLite's test schema does not
            // need that constraint restored to stay valid.
            return;
        }

        // Reversible only if no row has actually taken the new enum
        // value yet — same caveat as any enum-narrowing rollback.
        foreach (self::TABLES as $table => $enumDef) {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN outlet ENUM('bar', 'restaurant') NOT NULL");
        }

        DB::statement("ALTER TABLE folio_transactions MODIFY COLUMN type ENUM(
            'room_charge', 'bar_charge', 'restaurant_charge', 'other_service',
            'payment', 'refund', 'adjustment'
        ) NOT NULL");
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }

    private function widenOnSqlite(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('outlet')->change();
            });
        }

        Schema::table('folio_transactions', function (Blueprint $t) {
            $t->string('type')->change();
        });
    }
};
