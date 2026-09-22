<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The single central payment ledger (spec section 16): every actual
     * money movement from every module — guest folio settlement, bar/
     * restaurant cash sales, and (in later phases) expense payments,
     * payroll payments, refunds — writes exactly one row here in addition
     * to whatever module-specific record it also belongs to. This is
     * intentionally minimal for now (no reconciliation/day-lock yet —
     * that lands with the Finance phase) but exists from day one so no
     * payment anywhere in the system is ever an orphaned financial
     * record with nowhere central to be seen.
     */
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->string('payment_number', 40)->unique();

            $table->enum('direction', ['in', 'out'])->default('in')->comment('in = money received (sales, folio settlement); out = refunds/expenses/payroll in later phases.');
            $table->decimal('amount', 14, 2);
            $table->enum('method', ['cash', 'mobile_money', 'card', 'bank_transfer'])->nullable();

            $table->string('source_module', 60)->comment('e.g. front_office, pos_bar, pos_restaurant, finance, payroll');
            $table->string('source_reference', 100)->nullable()->comment('Human-readable reference to the originating record, e.g. an order number or folio id.');
            $table->string('reference_type')->nullable()->comment('Fully qualified model class of the source record, when there is one.');
            $table->unsignedBigInteger('reference_id')->nullable();

            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete();
            $table->foreignId('recorded_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('recorded_at')->useCurrent();
            $table->text('notes')->nullable();

            $table->timestamps();

            $table->index(['reference_type', 'reference_id']);
            $table->index(['source_module', 'recorded_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
