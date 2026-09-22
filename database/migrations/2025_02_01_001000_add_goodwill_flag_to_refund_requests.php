<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correction round (audited defect #20): "limit refunds to available
 * refundable funds ... with goodwill compensation as a separate
 * authorized action". An ordinary refund request is capped, at approval,
 * against (total received on this folio - refunds already approved) —
 * see RefundService::approve(). A goodwill compensation is a genuinely
 * different, discretionary decision (compensating a guest beyond what
 * they actually paid — a service failure credit, for example) and is
 * deliberately exempt from that cap, but still requires the same
 * 'approve-refund' authorization and still posts through the same
 * central ledger via FolioService::postRefund() — it is a separate
 * AUTHORIZED action, not an unchecked one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->boolean('is_goodwill')->default(false)->after('reason');
        });
    }

    public function down(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropColumn('is_goodwill');
        });
    }
};
