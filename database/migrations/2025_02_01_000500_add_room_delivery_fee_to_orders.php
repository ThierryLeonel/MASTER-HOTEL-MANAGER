<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New requirement: a fixed 500 XAF delivery charge, once per delivered
     * order, when a food/drink order is delivered to a room/apartment/
     * suite. Implemented as columns on `orders` rather than a synthetic
     * OrderItem — OrderService::deductRecipeStock() only ever iterates
     * real OrderItem rows with a menu item's recipe, so keeping the fee out
     * of that table is what makes "do not deduct inventory for the
     * delivery fee itself" true by construction rather than by a special
     * case. OrderService::recalculateTotals() (this delivery) folds
     * `delivery_fee_amount` into `orders.total_amount`, so it is
     * automatically included in whatever the order already settles for
     * (cash payment or folio charge) — recognized exactly once, the same
     * moment the rest of the order's revenue is.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('is_room_delivery')->default(false)->after('pos_table_id');
            $table->foreignId('delivery_destination_stay_id')->nullable()->after('is_room_delivery')
                ->constrained('stays')->nullOnDelete();
            $table->decimal('delivery_fee_amount', 12, 2)->default(0)->after('delivery_destination_stay_id');

            $table->boolean('delivery_fee_waived')->default(false)->after('delivery_fee_amount');
            $table->foreignId('delivery_fee_waived_by')->nullable()->after('delivery_fee_waived')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('delivery_fee_waived_at')->nullable()->after('delivery_fee_waived_by');
            $table->text('delivery_fee_waive_reason')->nullable()->after('delivery_fee_waived_at');

            $table->timestamp('delivery_mode_locked_at')->nullable()->after('delivery_fee_waive_reason')
                ->comment('Set at confirm() — after this, delivery mode/fee can only change via an audited correction.');
        });

        Schema::create('order_delivery_corrections', function (Blueprint $table) {
            $table->id();
            $table->foreignId('order_id')->constrained('orders')->restrictOnDelete();
            $table->foreignId('actor_id')->constrained('users')->restrictOnDelete();
            $table->boolean('previous_is_room_delivery');
            $table->decimal('previous_delivery_fee_amount', 12, 2);
            $table->boolean('new_is_room_delivery');
            $table->decimal('new_delivery_fee_amount', 12, 2);
            $table->text('reason');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_delivery_corrections');

        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('delivery_destination_stay_id');
            $table->dropConstrainedForeignId('delivery_fee_waived_by');
            $table->dropColumn([
                'is_room_delivery', 'delivery_fee_amount', 'delivery_fee_waived',
                'delivery_fee_waived_at', 'delivery_fee_waive_reason', 'delivery_mode_locked_at',
            ]);
        });
    }
};
