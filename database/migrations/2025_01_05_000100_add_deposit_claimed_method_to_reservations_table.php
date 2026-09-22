<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Follow-up to the fourth post-9.8 fix round's website booking feature
 * (PHASE9_AUDIT_AND_PLAN.md §20.4), itself a direct follow-up request:
 * "let the amount sent be posted to the ledger only when the reservation
 * is approved." Doing that for real (posting an actual `payments` row,
 * not just a claim) needs a real `method` — `PaymentLedgerService::
 * record()` requires one, and this project deliberately never guesses a
 * payment method it wasn't told (see §18.3's identical reasoning for why
 * `mobile_money` is never auto-attributed to a specific provider). Before
 * this column existed, the guest's claimed deposit had an amount and a
 * free-text reference but no structured method, so there was nothing
 * honest to post automatically. This one nullable column is what makes
 * approval-time auto-posting possible without guessing — see
 * `ReservationService::approve()`'s docblock for how it's used.
 *
 * Nullable/additive, no existing row or query affected. Guarded with
 * Schema::hasTable() out of caution — a real deploy of this project hit
 * a "table already exists" error on a sibling migration from an
 * interrupted shared-hosting request (PHASE9_AUDIT_AND_PLAN.md §20.6);
 * this is an `addColumn`-style migration rather than a `create`, so the
 * equivalent guard here is `Schema::hasColumn()`.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('reservations', 'deposit_claimed_method')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            // Same four values as payments.method's own enum, kept as a
            // plain string (not a DB-level enum) so the two never need a
            // synchronized migration if the payments enum ever grows —
            // validated against the same allowed list in
            // WebsiteBookingController instead.
            $table->string('deposit_claimed_method', 20)->nullable()->after('deposit_reference');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('reservations', 'deposit_claimed_method')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn('deposit_claimed_method');
        });
    }
};
