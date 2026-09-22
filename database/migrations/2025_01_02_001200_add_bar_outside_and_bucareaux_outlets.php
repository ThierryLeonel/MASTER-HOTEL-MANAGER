<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Two more sales points, added at the user's explicit request: "the bar
 * outside" and "the bucareaux" — described as "not really like a
 * department but they are separate and their sales and stocks are
 * managed separately." Judgment call (documented in full in
 * PHASE9_AUDIT_AND_PLAN.md §17): the user's answer confirmed these are
 * operationally separate points of sale with their own sales record,
 * which is exactly what the existing Bar/Restaurant/Front Desk POS
 * module already gives any outlet — it is already outlet-parametrized
 * end-to-end (`operate-{outlet}-pos`, `WHERE outlet = ?`), exactly the
 * same shape the front_desk outlet was added under in
 * 2025_01_02_000800_add_front_desk_outlet_support.php. Rather than
 * build a second, parallel POS system for two more counters, this
 * follows that same precedent: 'bar_outside' and 'bucareaux' become two
 * more outlet values, each with its own menu/items/tables/orders (so
 * its "sales" are separately recorded) and its own POS permission (so
 * only staff assigned to it can operate it). What still is NOT
 * separated is the underlying stock ledger — Bar and Restaurant don't
 * have separate stock locations from each other today either; every
 * outlet's menu items draw from the one shared `products`/stock-batch
 * pool via `recipe_items`, distinguished only by which menu items
 * belong to which outlet. "Bar Outside" and "Bucareaux" get the same
 * treatment as Bar and Restaurant already have: separate sales, shared
 * central stock pool. If a true separate physical stock location per
 * counter turns out to be what's meant, that is a materially bigger
 * change (multi-location inventory) and should be raised as its own
 * follow-up once confirmed.
 *
 * Same MySQL-ENUM-widening / SQLite-widening approach as the front_desk
 * migration this one is modeled on — see that file's docblock for the
 * full explanation of why raw MODIFY COLUMN is used on MySQL and a
 * plain string column on SQLite.
 */
return new class extends Migration
{
    private const TABLES = [
        'menu_categories' => "ENUM('bar', 'restaurant', 'front_desk', 'bar_outside', 'bucareaux')",
        'menu_items' => "ENUM('bar', 'restaurant', 'front_desk', 'bar_outside', 'bucareaux')",
        'pos_tables' => "ENUM('bar', 'restaurant', 'front_desk', 'bar_outside', 'bucareaux')",
        'orders' => "ENUM('bar', 'restaurant', 'front_desk', 'bar_outside', 'bucareaux')",
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

        // '{outlet}_charge' is the deferred-to-folio charge type
        // (App\Services\Pos\OrderService::close()) — every outlet that
        // can defer an order onto a guest's room folio needs its own
        // entry here, or that deferral would fail for the two new ones.
        DB::statement("ALTER TABLE folio_transactions MODIFY COLUMN type ENUM(
            'room_charge', 'bar_charge', 'restaurant_charge', 'front_desk_charge',
            'bar_outside_charge', 'bucareaux_charge', 'other_service',
            'payment', 'refund', 'adjustment'
        ) NOT NULL");
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            return;
        }

        foreach (self::TABLES as $table => $enumDef) {
            DB::statement("ALTER TABLE {$table} MODIFY COLUMN outlet ENUM('bar', 'restaurant', 'front_desk') NOT NULL");
        }

        DB::statement("ALTER TABLE folio_transactions MODIFY COLUMN type ENUM(
            'room_charge', 'bar_charge', 'restaurant_charge', 'front_desk_charge', 'other_service',
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
