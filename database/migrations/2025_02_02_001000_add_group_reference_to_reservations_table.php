<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request ("i want to be able to book multi reservations and
 * multi bookings instead of entering 1 by 1"): a single guest/party
 * booking several rooms for the same stay dates in one go
 * (ReservationService::createGroup()) still creates one `Reservation`
 * row per room — every existing per-reservation flow (approve, reject,
 * check-in, deposit, folio, checkout, reporting) keeps working completely
 * unchanged, one row at a time. This column is purely an additional,
 * optional label: every row created together in the same multi-room
 * submission shares one freshly generated reference, so the Reservations
 * list and each reservation's own page can show "these N rooms were
 * booked together" and link between them, without needing a whole new
 * parallel "booking group" table.
 *
 * Nullable and additive: every reservation created before this shipped,
 * and every ordinary single-room booking made after it, has
 * `group_reference = null` and behaves exactly as it always has.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('reservations', 'group_reference')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->string('group_reference', 40)->nullable()->after('reservation_number')->index();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('reservations', 'group_reference')) {
            Schema::table('reservations', function (Blueprint $table) {
                $table->dropColumn('group_reference');
            });
        }
    }
};
