<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New requirement: one complimentary breakfast per occupied lodging
     * unit per hotel business date — per STAY, not per person, and not
     * re-grantable by a same-day room move.
     *
     * The row is never deleted, and a correction never frees the slot back
     * up for a fresh redemption — `reversed_at`/`reversed_by`/
     * `reversal_reason` record a correction (and its linked stock
     * reversal, handled in BreakfastEntitlementService) while the unique
     * constraint below still counts the row, exactly as the requirement
     * asks: "deleting a record must not silently restore a used
     * entitlement."
     *
     * Keyed on (stay_id, business_date) rather than (room_id,
     * business_date): this is what actually satisfies BOTH stated rules at
     * once — normally one stay occupies one room, so this already caps it
     * at one per physical unit per day; and because the key travels with
     * the STAY rather than the room, a mid-day room change on the same
     * stay is still blocked from a second breakfast (the room-move case
     * the requirement calls out explicitly), while a genuine same-day
     * room turnover (Stay A checks out, Stay B checks into the same room
     * later that day) is a different stay_id and correctly gets its own
     * entitlement, since it is a separate occupied-unit-day.
     */
    public function up(): void
    {
        Schema::create('breakfast_entitlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stay_id')->constrained('stays')->restrictOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete()
                ->comment('Room the stay occupied at the moment of redemption (denormalized for the restaurant view/reporting).');
            $table->date('business_date');
            $table->timestamp('served_at');
            $table->foreignId('served_by')->constrained('users')->restrictOnDelete();

            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            $table->foreignId('menu_item_id')->nullable()->constrained('menu_items')->nullOnDelete()
                ->comment('Which breakfast menu item\'s recipe/stock was deducted for this redemption.');

            $table->timestamp('reversed_at')->nullable();
            $table->foreignId('reversed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('reversal_reason')->nullable();

            $table->timestamps();

            $table->unique(['stay_id', 'business_date'], 'breakfast_entitlements_one_per_stay_per_day');
            $table->index(['room_id', 'business_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breakfast_entitlements');
    }
};
