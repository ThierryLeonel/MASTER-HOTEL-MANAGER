<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Removed at the user's explicit request ("remove the clean and the
 * dirty status"). `rooms.housekeeping_status` (clean/dirty/inspected) was
 * a separate cleanliness label shown next to a room's real `status`
 * (available/occupied/maintenance/out_of_service) on the room board — set
 * automatically to 'dirty' at checkout and by a resolved maintenance
 * ticket, and to 'clean'/'inspected' when a housekeeping task was
 * completed. None of that auto-tracking is wanted any more.
 *
 * The separate Housekeeping module itself (the task list where staff
 * create/start/complete cleaning and inspection jobs — `housekeeping_tasks`
 * table, `HousekeepingService`, `HousekeepingController`) is UNCHANGED —
 * only the room's own clean/dirty/inspected label and its automatic
 * side-effects are removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->dropColumn('housekeeping_status');
        });
    }

    public function down(): void
    {
        Schema::table('rooms', function (Blueprint $table) {
            $table->enum('housekeeping_status', ['clean', 'dirty', 'inspected'])->default('clean')->index();
        });
    }
};
