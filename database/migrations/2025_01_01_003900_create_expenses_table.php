<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Expense lifecycle: pending -> approved -> paid (or rejected/
 * cancelled). "Paid" is the only step that touches money — it's what
 * posts to the central payment ledger — so an approved-but-unpaid
 * expense is a real, correct, intermediate state (e.g. approved today,
 * paid once the supplier is actually in front of someone with cash).
 * Approval tier depends on `amount` vs the configurable
 * `expense_approval_threshold` setting, checked at approval time (not
 * baked in at request time) so a later threshold change applies to
 * whatever is still pending.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('expenses', function (Blueprint $table) {
            $table->id();
            $table->string('expense_number')->unique();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->string('category');
            $table->text('description');
            $table->decimal('amount', 14, 2);
            $table->enum('status', ['pending', 'approved', 'rejected', 'paid', 'cancelled'])->default('pending');
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('paid_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('payment_method')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('expenses');
    }
};
