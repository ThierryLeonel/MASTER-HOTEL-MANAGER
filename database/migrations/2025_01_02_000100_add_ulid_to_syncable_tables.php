<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Phase 9.7.1 (PHASE9_AUDIT_AND_PLAN.md §6, Phase 9.7 sub-plan): the
 * foundational, highest-risk piece of the hybrid sync engine — every table
 * that can be written on either the local server or the cloud VPS needs a
 * globally-unique identifier, because the existing auto-increment integer
 * primary keys collide the moment two independently-writing databases both
 * hand out their own "row #1".
 *
 * This is an ADDITIVE column, not a primary-key replacement, exactly as
 * PHASE9_AUDIT_AND_PLAN.md's Phase 9.7 entry specifies: every existing
 * foreign key in the schema keeps pointing at the integer `id` column
 * unchanged, so this migration touches zero foreign key relationships. The
 * `ulid` column is nullable (existing/locally-created rows before a device
 * is ever synced simply have no ulid yet — the sync outbox, built in a
 * later sub-phase, is what assigns and uses it) and unique (enforced only
 * across non-null values in MySQL/MariaDB, which is exactly what's wanted:
 * many rows with no ulid yet is fine, two rows with the SAME ulid is not).
 *
 * The 48 tables below are every table backing an `App\Models\*` class
 * except `settings` — deliberately excluded here as a scoped decision, not
 * an oversight: settings are few, change rarely, and are administered
 * per-installation for now (see the Phase 9.7 sub-plan in
 * PHASE9_AUDIT_AND_PLAN.md §6 for the full rationale and the explicit list
 * of what is/isn't in scope). Framework/infrastructure tables (`users`
 * migration's own `password_reset_tokens`/`sessions` if present, `cache`,
 * `jobs`, `migrations`) and spatie/laravel-permission's tables (roles are
 * fixed and provisioned identically on both installations by
 * RolesAndPermissionsSeeder, not runtime-synced data) are excluded for the
 * same reason: they are not per-hotel business data that both sides
 * independently create or edit.
 */
return new class extends Migration
{
    /**
     * @var list<string>
     */
    private array $tables = [
        'accounting_days', 'accounts_receivable', 'attendance_records', 'audit_logs',
        'cash_reconciliations', 'departments', 'employee_profiles', 'expenses',
        'folios', 'folio_transactions', 'goods_receipts', 'goods_receipt_items',
        'guests', 'housekeeping_tasks', 'leave_balances', 'leave_requests', 'leave_types',
        'maintenance_tickets', 'menu_categories', 'menu_items', 'orders', 'order_items',
        'payments', 'payroll_deduction_brackets', 'payroll_deduction_types', 'payroll_runs',
        'payslips', 'payslip_lines', 'pos_tables', 'products', 'purchase_orders',
        'purchase_order_items', 'recipe_items', 'refund_requests', 'reservations',
        'room_categories', 'rooms', 'stays', 'stock_batches', 'stock_counts',
        'stock_count_items', 'stock_movements', 'stock_requisitions', 'stock_requisition_items',
        'stock_wastages', 'suppliers', 'units', 'users',
    ];

    public function up(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (! Schema::hasColumn($table, 'ulid')) {
                    $blueprint->ulid('ulid')->nullable()->unique()->after('id');
                }
            });
        }
    }

    public function down(): void
    {
        foreach ($this->tables as $table) {
            Schema::table($table, function (Blueprint $blueprint) use ($table) {
                if (Schema::hasColumn($table, 'ulid')) {
                    $blueprint->dropColumn('ulid');
                }
            });
        }
    }
};
