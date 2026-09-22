<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * New requirement 5 (Marketing Manager role/dashboard: "create/approve
     * housekeeping") — the housekeeping module had no approval concept at
     * all before this (report -> start -> complete, with nothing to
     * approve). This is deliberately ADDITIVE rather than a change to the
     * existing `status` enum (pending/in_progress/done), so every existing
     * task, factory, and test that never opts into approval keeps working
     * exactly as before: `approval_status` defaults to 'not_required', and
     * HousekeepingService::start() only refuses to start a task whose
     * approval_status is 'pending' (created via createTask(...,
     * requiresApproval: true) and not yet approved) — 'not_required' and
     * 'approved' are both fine to start, so no existing call site's
     * behaviour changes unless it opts in.
     */
    public function up(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->enum('approval_status', ['not_required', 'pending', 'approved', 'rejected'])
                ->default('not_required')->after('status')->index();
            $table->foreignId('approved_by')->nullable()->after('approval_status')
                ->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable()->after('approved_by');
            $table->text('rejection_reason')->nullable()->after('approved_at');
        });
    }

    public function down(): void
    {
        Schema::table('housekeeping_tasks', function (Blueprint $table) {
            $table->dropConstrainedForeignId('approved_by');
            $table->dropColumn(['approval_status', 'approved_at', 'rejection_reason']);
        });
    }
};
