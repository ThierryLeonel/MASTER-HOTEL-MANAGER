<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "we need a
 * reconciled cash account, bank account, mtn mobile money, orange money
 * etc." Until now `payments.method` only ever recorded a coarse category
 * (cash/mobile_money/card/bank_transfer) — there was no way to tell one
 * bank account from another, or an MTN Mobile Money wallet from an Orange
 * Money one, let alone reconcile either against a real statement. A
 * `financial_accounts` row is one of those real-world places money sits:
 * the cash drawer, a specific bank account, a specific mobile money
 * wallet. The starter set (Cash/Bank/MTN Mobile Money/Orange Money) is
 * seeded but NOT hardcoded — the owner can add more from the Financial
 * Accounts screen (e.g. a second bank account) at any time, exactly like
 * `departments` already works.
 *
 * `opening_balance` exists because this software did not exist before
 * today — a real bank account already has money in it that no `payments`
 * row will ever explain. Reconciliation (see
 * `financial_account_reconciliations` below) compares opening_balance +
 * every payment's net movement against what the account actually holds.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_accounts', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('name', 100);
            $table->string('code', 30)->unique();
            $table->enum('type', ['cash', 'bank', 'mobile_money', 'card'])->index();
            $table->decimal('opening_balance', 14, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('financial_accounts');
    }
};
