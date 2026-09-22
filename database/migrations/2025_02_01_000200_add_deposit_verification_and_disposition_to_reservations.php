<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Correction round (audited defects #3 and #8).
     *
     * Defect #3 (website deposit posted twice — once automatically at
     * approval, once again if staff also manually record it): approval no
     * longer silently posts a claimed website deposit to the ledger (see
     * ReservationService::approve() in this same delivery). Verifying and
     * posting it is now its own explicit, one-time, auditable action
     * (ReservationService::verifyWebsiteDeposit()) — these columns record
     * that it happened, and that action refuses to run a second time for
     * the same reservation.
     *
     * Defect #8 (a cancelled/rejected reservation's deposit just sits with
     * no refund/forfeiture/transfer workflow): these columns record the
     * one-time, audited disposition decision (ReservationService::
     * disposeDeposit()) — what happened to a deposit once its reservation
     * is cancelled/rejected, by whom, when, and (for a refund or transfer)
     * through which real financial account or to which other reservation.
     */
    public function up(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->timestamp('deposit_claim_verified_at')->nullable()->after('deposit_claimed_method');
            $table->foreignId('deposit_claim_verified_by')->nullable()->after('deposit_claim_verified_at')
                ->constrained('users')->nullOnDelete();

            $table->enum('deposit_disposition', ['pending', 'refunded', 'forfeited', 'transferred'])
                ->nullable()->after('deposit_paid')
                ->comment('Set once a reservation with deposit_paid > 0 is cancelled/rejected; null while the reservation is still live.');
            $table->foreignId('deposit_disposition_by')->nullable()->after('deposit_disposition')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('deposit_disposition_at')->nullable()->after('deposit_disposition_by');
            $table->text('deposit_disposition_notes')->nullable()->after('deposit_disposition_at');
            $table->foreignId('deposit_disposition_financial_account_id')->nullable()->after('deposit_disposition_notes')
                ->constrained('financial_accounts')->nullOnDelete();
            $table->foreignId('deposit_disposition_target_reservation_id')->nullable()->after('deposit_disposition_financial_account_id')
                ->constrained('reservations')->nullOnDelete()
                ->comment('For disposition=transferred: the other reservation the deposit was moved to.');
        });
    }

    public function down(): void
    {
        Schema::table('reservations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('deposit_claim_verified_by');
            $table->dropColumn('deposit_claim_verified_at');
            $table->dropConstrainedForeignId('deposit_disposition_financial_account_id');
            $table->dropConstrainedForeignId('deposit_disposition_target_reservation_id');
            $table->dropConstrainedForeignId('deposit_disposition_by');
            $table->dropColumn(['deposit_disposition', 'deposit_disposition_at', 'deposit_disposition_notes']);
        });
    }
};
