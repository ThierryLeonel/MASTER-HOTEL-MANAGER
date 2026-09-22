<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Complimentary ("comp") support, added at the user's explicit request
 * ("complimentary drink orders at the bar" and "special guest lodging"
 * where "the complimentary is free but the main [item] is not" — i.e.
 * per-item comps within an otherwise-billable order, and per-stay room
 * comps that don't waive a guest's other charges). Full design decision
 * in PHASE9_AUDIT_AND_PLAN.md §9.
 *
 * Two independent additions, both purely additive (nullable/defaulted
 * columns, no existing column touched):
 *
 * - `order_items.is_complimentary` / `comp_reason`: a comped line keeps
 *   its real `unit_price` (for reporting "what this would have cost")
 *   but App\Services\Pos\OrderService sets that line's `line_total` to
 *   0 — since OrderService::recalculateTotals() already just sums
 *   `line_total` across an order's items, a comped line naturally
 *   contributes nothing to the order's subtotal/tax/total with no
 *   change needed to that method at all. Applies to every POS outlet
 *   (Bar, Restaurant, Front Desk) since they all share one
 *   OrderService/OrderController.
 *
 * - `stays.is_complimentary` / `complimentary_reason` /
 *   `marked_complimentary_by` / `marked_complimentary_at`: marks a
 *   stay's ROOM charge only as waived — every other charge type
 *   (bar_charge, restaurant_charge, front_desk_charge, other_service)
 *   still posts and gets paid normally, per the user's explicit "extras
 *   still billed" scope. Enforced in two places: CheckOutService skips
 *   its own automatic final-night room_charge post when the stay is
 *   complimentary (silently, since checkout must never be blocked —
 *   spec section 10, "an unpaid balance never blocks checkout" applies
 *   equally to "an unposted comp charge"), and FolioService::postCharge()
 *   refuses a *manual* `room_charge` post against a complimentary
 *   stay's folio with a clear error, so front desk can't accidentally
 *   charge a comped guest for their room through the general "post
 *   charge" form.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->boolean('is_complimentary')->default(false)->after('line_total');
            $table->string('comp_reason', 255)->nullable()->after('is_complimentary');
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->boolean('is_complimentary')->default(false)->after('agreed_rate');
            $table->text('complimentary_reason')->nullable()->after('is_complimentary');
            $table->foreignId('marked_complimentary_by')->nullable()->after('complimentary_reason')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('marked_complimentary_at')->nullable()->after('marked_complimentary_by');
        });
    }

    public function down(): void
    {
        Schema::table('order_items', function (Blueprint $table) {
            $table->dropColumn(['is_complimentary', 'comp_reason']);
        });

        Schema::table('stays', function (Blueprint $table) {
            $table->dropConstrainedForeignId('marked_complimentary_by');
            $table->dropColumn(['is_complimentary', 'complimentary_reason', 'marked_complimentary_at']);
        });
    }
};
