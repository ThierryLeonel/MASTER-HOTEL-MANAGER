<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per spec section 10: unpaid balances at checkout do NOT block
     * checkout — they become Accounts Receivable instead. Settlement of
     * an AR record later posts a `payment` folio_transaction against the
     * (already closed) folio and reduces amount_outstanding here.
     */
    public function up(): void
    {
        Schema::create('accounts_receivable', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained('folios')->restrictOnDelete();
            $table->foreignId('guest_id')->constrained('guests')->restrictOnDelete();
            $table->decimal('original_amount', 14, 2);
            $table->decimal('amount_outstanding', 14, 2);
            $table->enum('status', ['open', 'partially_paid', 'paid', 'written_off'])->default('open')->index();
            $table->date('due_date')->nullable();
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('written_off_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('written_off_reason')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounts_receivable');
    }
};
