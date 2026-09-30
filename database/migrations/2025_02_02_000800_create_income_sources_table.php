<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request, in a fast follow-up to the income-source dropdown just
 * shipped ("the list should not be fixed... the accountant, or finance
 * officer [should be able to] update, create [it], and [the owner] should
 * be able to update, create and delete it"): the dropdown on the general
 * "record a payment" screen (front-office/payments/index.blade.php) was
 * initially built from a hardcoded `Payment::INCOME_SOURCES` PHP constant
 * — that was fine for choosing a value, but wrong for MANAGING the list,
 * which is exactly what this table now exists to let staff do without a
 * code deployment.
 *
 * This is deliberately its own small lookup table, not a change to the
 * `payments` table: `payments.income_source` (see that column's own
 * migration) stays free text and completely unaffected by anything done
 * here — renaming or even deleting an `IncomeSource` row never rewrites a
 * single historical payment, because that column never stored a foreign
 * key to this table in the first place, only the chosen (or typed) text
 * itself. That is exactly what makes a real, hard `delete()` here safe:
 * removing an option from this list only ever affects what the dropdown
 * offers going forward, never a single already-recorded transaction —
 * this table is pure picklist configuration, not a financial record, so
 * the engagement's standing "never rewrite historical financial records"
 * rule simply doesn't apply to it the way it does to `payments` itself.
 *
 * `ulid` is included directly at creation, matching how every other
 * syncable model's own creation migration already does this (see e.g.
 * `financial_accounts`'s own migration) rather than needing a later
 * addition to the shared `add_ulid_to_syncable_tables` migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('income_sources', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('name', 120)->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('income_sources');
    }
};
