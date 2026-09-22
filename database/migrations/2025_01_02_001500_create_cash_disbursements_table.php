<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The actual cash-out event — always posts to the central `payments`
 * ledger (direction 'out') via PaymentLedgerService, same as an expense
 * being paid. Two authorization shapes, both recorded on this one row
 * (App\Services\Finance\CashDisbursementService enforces exactly one of
 * them is present, matching the user's own description of the flow):
 *
 *  1. Ticket-backed (the normal path): `ticket_id` is set, and the
 *     authorizing manager's identity is already on that ticket
 *     (disbursement_tickets.authorized_by) — not duplicated here.
 *  2. Ticket-less (the exception path): `ticket_id` is null, and this
 *     row itself must clearly capture who authorized it — either an
 *     in-system user (`authorized_by_user_id`) or, when the authorizing
 *     superior isn't a system user / isn't available to be looked up,
 *     free-text `authorized_by_name` (+ optional `authorized_by_title`).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_disbursements', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('disbursement_number', 40)->unique();
            $table->foreignId('ticket_id')->nullable()->constrained('disbursement_tickets')->nullOnDelete();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->decimal('amount', 14, 2);
            $table->string('paid_to', 150)->comment('Who the cash was actually handed to.');
            $table->text('purpose');
            $table->enum('method', ['cash', 'mobile_money', 'card', 'bank_transfer'])->default('cash');

            // Ticket-less authorization capture — see docblock above.
            $table->foreignId('authorized_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('authorized_by_name', 150)->nullable();
            $table->string('authorized_by_title', 100)->nullable();

            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('disbursed_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_disbursements');
    }
};
