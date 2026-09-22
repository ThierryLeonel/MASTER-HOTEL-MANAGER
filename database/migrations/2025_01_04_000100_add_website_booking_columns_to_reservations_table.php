<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fourth post-9.8 fix round ("update the system such that it can catch
 * reservations from the website too"): a public, unauthenticated booking
 * page (App\Http\Controllers\Public\WebsiteBookingController) lets a
 * guest submit a reservation REQUEST directly — it always lands as
 * `status = 'pending'`, exactly like a phone or walk-in request, so a
 * staff member still reviews and assigns a room before it's confirmed
 * (spec section 22's approval gate is completely unchanged; the website
 * is just a new front door onto the same pending queue).
 *
 * Every new column here is nullable/defaulted — purely additive, no
 * existing row or query is affected.
 *
 * `source` distinguishes a website submission from a staff-entered one
 * for filtering/badging in the reservations list.
 *
 * `deposit_proof_path`/`deposit_claimed_amount`/`deposit_reference`
 * capture what the guest says they already sent (the clarifying answer
 * for this round: "request only but with upload of deposit screenshot
 * transaction details" — no online payment gateway; the guest attaches a
 * screenshot/receipt and the amount/reference they claim, staff verify
 * it and record the REAL deposit through the existing
 * ReservationController::recordDeposit() action — which still posts
 * through the one single-writer PaymentLedgerService — once the
 * reservation is confirmed, so this never creates a second, competing
 * way to record money).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->enum('source', ['staff', 'website'])->default('staff')->after('status')->index();
            $table->string('deposit_proof_path', 255)->nullable()->after('deposit_paid');
            $table->decimal('deposit_claimed_amount', 12, 2)->nullable()->after('deposit_proof_path');
            $table->string('deposit_reference', 150)->nullable()->after('deposit_claimed_amount');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropColumn(['source', 'deposit_proof_path', 'deposit_claimed_amount', 'deposit_reference']);
        });
    }
};
