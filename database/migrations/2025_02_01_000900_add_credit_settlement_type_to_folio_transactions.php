<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Correction round (audited defects #17 and #21): a genuine cash refund
 * (money leaving the hotel) and a guest settling an existing credit/debt
 * balance are different events from an ordinary folio 'payment' — neither
 * is well-described by 'refund' either (nothing is being given back; the
 * guest is paying something they owed, possibly after checkout). This new
 * 'credit_settlement' type lets FolioService distinguish "guest pays off
 * an outstanding balance" from "guest pays for something right now" in
 * ledger reports, and — combined with FolioService::post()'s loosened
 * closed-folio guard — is one of the two types (with 'refund') that may
 * still post to a folio that has already closed at checkout, per audited
 * defect #21 ("support guest-credit settlement after checkout").
 *
 * Same MySQL-enum-widening / SQLite-string-column pattern already
 * established by 2025_01_02_000800_add_front_desk_outlet_support.php and
 * 2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php — see
 * either of those migrations' own docblocks for the full reasoning.
 */
return new class extends Migration
{
    public function up(): void
    {
        if ($this->isSqlite()) {
            Schema::table('folio_transactions', function (Blueprint $t) {
                $t->string('type')->change();
            });

            return;
        }

        DB::statement("ALTER TABLE folio_transactions MODIFY COLUMN type ENUM(
            'room_charge', 'bar_charge', 'restaurant_charge', 'front_desk_charge',
            'bar_outside_charge', 'bucareaux_charge', 'other_service',
            'payment', 'refund', 'credit_settlement', 'adjustment'
        ) NOT NULL");
    }

    public function down(): void
    {
        if ($this->isSqlite()) {
            return;
        }

        DB::statement("ALTER TABLE folio_transactions MODIFY COLUMN type ENUM(
            'room_charge', 'bar_charge', 'restaurant_charge', 'front_desk_charge',
            'bar_outside_charge', 'bucareaux_charge', 'other_service',
            'payment', 'refund', 'adjustment'
        ) NOT NULL");
    }

    private function isSqlite(): bool
    {
        return Schema::getConnection()->getDriverName() === 'sqlite';
    }
};
