<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Immutable audit trail (spec section 21). Rows are written only by
     * App\Services\AuditLogger and are never updated or deleted by
     * application code; no controller or policy grants normal users write
     * access to this table beyond inserts performed by the logger itself.
     */
    public function up(): void
    {
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('role_name')->nullable()->comment('Snapshot of the acting role at the time of the action, independent of later role changes.');
            $table->string('action', 100)->comment('e.g. reservation.approved, folio.payment_posted, refund.approved, day.locked');
            $table->string('module', 60)->index()->comment('e.g. front_office, pos, inventory, finance, hr, auth');
            $table->string('record_type')->nullable()->comment('Fully qualified model class of the affected record');
            $table->unsignedBigInteger('record_id')->nullable();
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('reason')->nullable()->comment('Free-text justification, required for corrections/refunds/write-offs');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['record_type', 'record_id']);
            $table->index(['module', 'action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
