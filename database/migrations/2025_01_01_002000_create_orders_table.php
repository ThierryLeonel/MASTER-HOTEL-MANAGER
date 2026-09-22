<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * POS order lifecycle (spec section 13):
     *   draft -> confirmed -> closed
     *   draft/confirmed -> cancelled
     *
     * Item mutation rules (enforced in App\Services\Pos\OrderService, not
     * just in the UI): while draft, items may be freely added/edited/
     * removed; once confirmed, existing items are locked — only new
     * items may still be appended ("add-only" per spec section 13) —
     * until the order closes.
     *
     * Only a checked-in lodging guest (stay_id set, referencing an
     * active Stay) may defer the order onto their room folio; every
     * other close requires an immediate `method` and posts to the
     * central `payments` ledger instead.
     */
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 40)->unique();
            $table->enum('outlet', ['bar', 'restaurant'])->index();
            $table->foreignId('pos_table_id')->nullable()->constrained('pos_tables')->nullOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained('stays')->nullOnDelete()
                ->comment('Set only when this order is deferred onto a checked-in guest\'s room folio.');

            $table->enum('status', ['draft', 'confirmed', 'closed', 'cancelled'])->default('draft')->index();

            $table->decimal('subtotal', 14, 2)->default(0);
            $table->decimal('tax_amount', 14, 2)->default(0);
            $table->decimal('total_amount', 14, 2)->default(0);

            $table->enum('payment_method', ['cash', 'mobile_money', 'card', 'bank_transfer'])->nullable()
                ->comment('Null when deferred to a guest folio instead of paid immediately.');

            $table->foreignId('opened_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('confirmed_at')->nullable();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('closed_at')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
