<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * The guest folio ledger (spec section 12). Rows are append-only:
     * corrections are made by inserting a new ADJUSTMENT/REFUND row that
     * references the original via `reverses_transaction_id`, never by
     * editing or deleting a posted row. `voided_at`/`void_reason` mark a
     * row as superseded without removing it from history.
     */
    public function up(): void
    {
        Schema::create('folio_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('folio_id')->constrained('folios')->restrictOnDelete();

            $table->enum('type', [
                'room_charge', 'bar_charge', 'restaurant_charge', 'other_service',
                'payment', 'refund', 'adjustment',
            ])->index();

            $table->decimal('amount', 14, 2)->comment('Charges are positive, payments/refunds are negative, relative to the folio balance.');
            $table->string('description');
            $table->string('source_module', 60)->comment('e.g. front_office, bar_pos, restaurant_pos, finance');
            $table->string('reference', 100)->nullable()->comment('External/related record reference, e.g. POS order number, payment ledger id');
            $table->string('payment_method', 40)->nullable()->comment('cash, mobile_money, card, bank_transfer — only set for type=payment/refund');

            $table->foreignId('user_id')->constrained('users')->restrictOnDelete()->comment('Staff member who posted this transaction.');
            $table->timestamp('transaction_date')->useCurrent();

            $table->foreignId('reverses_transaction_id')->nullable()->constrained('folio_transactions')->nullOnDelete();
            $table->timestamp('voided_at')->nullable();
            $table->text('void_reason')->nullable();

            $table->timestamps();

            $table->index(['folio_id', 'transaction_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('folio_transactions');
    }
};
