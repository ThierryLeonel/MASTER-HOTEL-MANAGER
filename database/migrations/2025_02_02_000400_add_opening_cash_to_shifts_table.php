<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New feature round ("when a shift is been open we should declare the
 * opening balance, so that the system can properly calculate the expected
 * clossing and to know the variance at the end"): the till's starting
 * float was never captured — `expected_cash` was computed purely from the
 * shift's own ledgered cash payments (in minus out), which implicitly
 * assumed the drawer started at zero. See ShiftService::open() (now
 * requires it) and ::expectedCashFor() (now adds it in).
 *
 * Nullable and additive: every shift opened before this deploys keeps
 * `opening_cash` null, which `expectedCashFor()` treats as 0 — exactly the
 * assumption those shifts were already computed under, so no historical
 * expected/declared/variance figure on an already-closed shift changes.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('shifts', 'opening_cash')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->decimal('opening_cash', 14, 2)->nullable()->after('opened_at');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('shifts', 'opening_cash')) {
            Schema::table('shifts', function (Blueprint $table) {
                $table->dropColumn('opening_cash');
            });
        }
    }
};
