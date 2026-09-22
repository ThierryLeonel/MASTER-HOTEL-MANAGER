<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Cash disbursement (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md
 * §18) — distinct from the existing Expense module ("the accountant or
 * cashier has to be able to record cash disbursement not just expense
 * only"). Authorization model, per the user's own words: "before a
 * disbursement is done, the management creates the ticket, though
 * disbursement should also be able to be done without ticket but the
 * details will have to be clear and the detail of the superior
 * authorising it [must be recorded]." So a ticket here is an optional,
 * upfront authorization a manager raises for a planned cash-out — the
 * normal path — and `cash_disbursements.ticket_id` stays nullable so the
 * exception path (no ticket) is still fully supported, gated instead on
 * `cash_disbursements` capturing who authorized it. See that table's own
 * migration for how the "no ticket" case is enforced.
 *
 * A ticket is single-use by design (simplest correct behavior for a
 * first version): once a disbursement is recorded against it, its
 * status moves straight to 'used' — see CashDisbursementService. If this
 * hotel needs one ticket to be drawn down across several partial
 * disbursements later, that's a natural follow-up once the single-use
 * version is in daily use and its limits are felt in practice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('disbursement_tickets', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('ticket_number', 40)->unique();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('purpose', 500);
            $table->decimal('amount', 14, 2);
            $table->enum('status', ['open', 'used', 'cancelled'])->default('open')->index();
            $table->foreignId('authorized_by')->constrained('users')->restrictOnDelete()
                ->comment('The manager/superior who raised this authorization.');
            $table->text('notes')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disbursement_tickets');
    }
};
