<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * New feature round ("buttons to create, edit and delete complimentary
 * breakfast and also if the breakfast is delivered to the room"): records
 * whether a redeemed breakfast was taken in the restaurant or delivered to
 * the guest's room, same distinction the unrelated POS `orders` table
 * already tracks via `is_room_delivery` — but breakfast entitlements are
 * deliberately their own table (see BreakfastEntitlementService's
 * docblock) so this needs its own column rather than reusing that one.
 *
 * Nullable-with-default and additive: every entitlement redeemed before
 * this deploys defaults to "not delivered" (restaurant service), which
 * matches how they were actually served — nothing historical is rewritten.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('breakfast_entitlements', 'delivered_to_room')) {
            Schema::table('breakfast_entitlements', function (Blueprint $table) {
                $table->boolean('delivered_to_room')->default(false)->after('menu_item_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('breakfast_entitlements', 'delivered_to_room')) {
            Schema::table('breakfast_entitlements', function (Blueprint $table) {
                $table->dropColumn('delivered_to_room');
            });
        }
    }
};
