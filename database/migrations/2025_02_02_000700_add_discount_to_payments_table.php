<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request ("payments can involve discount if granted"), scoped (by
 * the user's own choice when asked) to the general-purpose "record a
 * payment" screen (PaymentController) — an accountant/front-desk staff
 * collecting money for something (rent, event hall hire, etc.) may have
 * granted a discount off the normal/quoted price. `payments.amount`
 * stays exactly what it always was — the real cash actually received
 * (never rewritten) — these two columns are purely informational: how
 * much less than the normal price was collected, and why, so a payment
 * with a discount shows its gross price, the discount, and the net
 * actually collected wherever it's displayed. No approval workflow here
 * (unlike the separate, pre-existing `discount_approvals` table for
 * room-rate discounts granted at check-in, which IS approval-gated) —
 * any staff recording the payment can simply note that a discount applied.
 *
 * Nullable and additive: existing payments are untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('payments', 'discount_amount')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->decimal('discount_amount', 14, 2)->nullable()->after('income_source');
                $table->string('discount_reason', 255)->nullable()->after('discount_amount');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('payments', 'discount_amount')) {
            Schema::table('payments', function (Blueprint $table) {
                $table->dropColumn(['discount_amount', 'discount_reason']);
            });
        }
    }
};
