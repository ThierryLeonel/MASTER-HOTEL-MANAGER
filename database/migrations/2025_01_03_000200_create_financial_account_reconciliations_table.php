<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21). One row per
 * point-in-time reconciliation of a financial account — same shape as
 * Shift's expected_cash/declared_cash/variance triad, just for an
 * account instead of a department's till: `expected_balance` is computed
 * live from the account's opening_balance plus every payment ledgered
 * against it; `declared_balance` is what the accountant actually reads
 * off the bank statement or mobile money app; `variance` is the
 * difference. Unlike a Shift, an account reconciliation has no
 * "open"/"close" pairing — it's a checkpoint an accountant takes
 * periodically, per the answered clarifying question ("Accountant enters
 * the real balance from the statement/app").
 *
 * Guarded with Schema::hasTable() (added after a real deploy hit "table
 * already exists" on this specific migration): this hotel's site runs on
 * shared hosting with tight per-request execution limits (README §10,
 * InfinityFree or equivalent), and this table's CREATE TABLE statement is
 * the largest/slowest in its migration batch — the most likely one to
 * have the PHP request time out or the connection drop *after* MySQL
 * finished creating the table but *before* Laravel could write this
 * migration's row into its own `migrations` tracking table (that write
 * happens in a second, separate query right after the DDL succeeds). A
 * later `php artisan migrate --force` retry then sees this migration as
 * "not yet run" and tries the CREATE TABLE again, even though the table
 * is already there with the exact right structure. Safe specifically
 * because a single CREATE TABLE is atomic — MySQL never leaves a
 * "half-created" table behind, so if `financial_account_reconciliations`
 * already exists, skipping straight to returning is equivalent to having
 * just run this migration successfully; there is no partial-schema case
 * to worry about the way there would be for a multi-statement migration.
 * (Note: an earlier suggested fix referenced a `Schema::createIfNotExists()`
 * method — that method does not exist in Laravel's Schema facade, at any
 * version through Laravel 12; calling it would have thrown a fatal
 * "call to undefined method" error instead of fixing anything. The
 * `Schema::hasTable()` guard below is the real, documented way to make a
 * `Schema::create()` migration idempotent.)
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('financial_account_reconciliations')) {
            return;
        }

        Schema::create('financial_account_reconciliations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->foreignId('financial_account_id')->constrained('financial_accounts')->restrictOnDelete();
            $table->foreignId('reconciled_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('reconciled_at')->useCurrent();
            $table->decimal('expected_balance', 14, 2);
            $table->decimal('declared_balance', 14, 2);
            $table->decimal('variance', 14, 2);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['financial_account_id', 'reconciled_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_reconciliations');
    }
};
