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
 *
 * Fix, first fresh-MySQL-database deploy for a new hotel/site: this
 * migration's composite index used to rely on Laravel's auto-generated
 * name — 'financial_account_reconciliations_financial_account_id_
 * reconciled_at_index', 76 characters — which is over MySQL's hard
 * 64-character identifier limit (SQLSTATE[42000] error 1059). That never
 * surfaced before because every prior deploy either already had this
 * table (the `Schema::hasTable()` guard below returned before ever
 * reaching the index) or ran on a database (Postgres, in this project's
 * own earlier verification) that silently truncates an over-long
 * identifier instead of erroring. A genuinely fresh MySQL database is the
 * first case that actually executes this CREATE TABLE for real — and
 * because MySQL auto-commits DDL (no transactional CREATE TABLE/ALTER
 * TABLE the way most single-statement data changes are), the failure
 * left a real, specific half-finished state behind: the table itself
 * WAS created (that statement succeeded), but the immediately-following
 * `ALTER TABLE ... ADD INDEX` failed and rolled back nothing since there
 * was nothing transactional to roll back — and because the whole
 * migration's `up()` threw, Laravel never got to record this migration
 * as run. A plain retry of `php artisan migrate --force` would hit
 * `Schema::hasTable()` returning true from that already-created table,
 * `return` immediately (correct behavior in the ordinary case this guard
 * was written for), and quietly leave that specific database missing
 * this index forever. The `else` branch below exists specifically for
 * that one scenario: add the (now short, explicit) index only if a table
 * that already exists doesn't have it yet, changing nothing on a
 * database where this migration already completed cleanly the first
 * time.
 */
return new class extends Migration
{
    private const INDEX_NAME = 'far_account_reconciled_at_idx';

    public function up(): void
    {
        if (Schema::hasTable('financial_account_reconciliations')) {
            $hasIndex = collect(Schema::getIndexes('financial_account_reconciliations'))
                ->contains(fn (array $index) => $index['name'] === self::INDEX_NAME);

            if (! $hasIndex) {
                Schema::table('financial_account_reconciliations', function (Blueprint $table) {
                    $table->index(['financial_account_id', 'reconciled_at'], self::INDEX_NAME);
                });
            }

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

            // Explicit short name — see this migration's own docblock for
            // why this can't be left to Laravel's default auto-generated
            // name.
            $table->index(['financial_account_id', 'reconciled_at'], self::INDEX_NAME);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_account_reconciliations');
    }
};
