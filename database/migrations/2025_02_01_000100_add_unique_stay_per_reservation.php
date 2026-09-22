<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correction round (audited defect #1): "prevent one reservation from
     * producing multiple stays." The application-layer guard
     * (App\Services\FrontOffice\CheckInService now locks+re-checks the
     * Reservation row itself, not just the Room) is necessary but not
     * sufficient on its own — this is the database-level backstop so that
     * even a bug, a raw insert, or a future code path that forgets the
     * lock still cannot physically create two Stay rows for the same
     * Reservation. `reservation_id` is nullable for walk-ins (no
     * Reservation at all); MySQL/MariaDB treat multiple NULLs in a unique
     * index as distinct from one another, so walk-ins are unaffected.
     *
     * Existing-data safety: before adding the constraint, check for and
     * report (never silently delete) any reservation_id that already has
     * more than one Stay row — see MIGRATION_NOTES in this delivery's
     * change summary for the exact diagnostic query and remediation
     * guidance if this ever fires on a real database.
     */
    public function up(): void
    {
        $duplicates = DB::table('stays')
            ->select('reservation_id', DB::raw('COUNT(*) as c'))
            ->whereNotNull('reservation_id')
            ->groupBy('reservation_id')
            ->having('c', '>', 1)
            ->pluck('reservation_id');

        if ($duplicates->isNotEmpty()) {
            throw new \RuntimeException(
                'Cannot add a unique constraint on stays.reservation_id: the following reservation_id values already '
                .'have more than one Stay row: '.$duplicates->implode(', ').'. This means the exact defect being fixed '
                .'has already occurred on this database. Do NOT delete either row automatically — inspect each pair '
                .'manually (which one has the real, currently-open folio/payments?), decide which Stay is authoritative, '
                .'and either null out reservation_id on the erroneous duplicate (if it was a genuine separate occupancy, '
                .'e.g. a walk-in that was miscoded) or merge/void it per your own bookkeeping policy, before re-running '
                .'this migration. See the delivery change summary, "Existing-data remediation" section, for the full '
                .'diagnostic query.'
            );
        }

        Schema::table('stays', function (Blueprint $table) {
            $table->unique('reservation_id', 'stays_reservation_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropUnique('stays_reservation_id_unique');
        });
    }
};
