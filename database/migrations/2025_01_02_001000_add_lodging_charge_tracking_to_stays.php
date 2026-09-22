<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up request ("let the total cost be in the folio... the advance
 * on reservation are also payments... make some modifications following
 * global hotel (hospitality) standards"). Full design decision in
 * PHASE9_AUDIT_AND_PLAN.md — new section on lodging charges & deposits.
 *
 * `stays.nights_charged`: how many nights of room charge have already
 * been posted to this stay's folio. Purely additive, defaulted to 0 so
 * existing rows are unaffected. Before this round, the folio only ever
 * received a room_charge manually or via an optional one-night charge at
 * checkout — "cost per night" was informational text only, never a real
 * posted balance. From this round on, App\Services\FrontOffice\
 * CheckInService posts the FULL anticipated stay cost (agreed_rate x
 * nights) at check-in and records how many nights that covered here;
 * App\Services\FrontOffice\CheckOutService then only ever charges the
 * DELTA — genuine extensions beyond this number — and never auto-charges
 * or auto-refunds for an on-time or early departure. See Stay::nightsUntil().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->unsignedInteger('nights_charged')->default(0)->after('agreed_rate')
                ->comment('Nights of room charge already posted to the folio — see CheckInService/CheckOutService.');
        });
    }

    public function down(): void
    {
        Schema::table('stays', function (Blueprint $table) {
            $table->dropColumn('nights_charged');
        });
    }
};
