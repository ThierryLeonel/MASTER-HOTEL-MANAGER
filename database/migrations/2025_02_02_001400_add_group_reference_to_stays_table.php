<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request ("i do not see the multibooking in the check in"):
 * multi-room booking already existed for advance Reservations
 * (2025_02_02_001000_add_group_reference_to_reservations_table.php,
 * ReservationService::createGroup()) but was never built for Walk-in
 * Check-in — a walk-in party of several rooms had to be checked in one
 * room at a time, with no way to see afterward that they belonged
 * together. This column mirrors that same reservations one, on the same
 * table (Stay) walk-in check-in actually writes to, so it works the
 * identical way: every Stay created together in one multi-room walk-in
 * submission (CheckInService::checkInWalkInGroup()) shares one freshly
 * generated reference; every existing single-room check-in — from a
 * reservation or a walk-in — keeps writing `group_reference = null` and
 * is completely unaffected.
 *
 * Deliberately NOT a new "booking group" table for the same reason the
 * reservations column wasn't: one extra nullable, indexed label column
 * is enough to let the Stays list and each stay's own page show "these N
 * rooms checked in together" and link between them, without a parallel
 * structure to keep in sync.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stays', 'group_reference')) {
            Schema::table('stays', function (Blueprint $table) {
                $table->string('group_reference', 40)->nullable()->after('room_id')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stays', 'group_reference')) {
            Schema::table('stays', function (Blueprint $table) {
                $table->dropColumn('group_reference');
            });
        }
    }
};
