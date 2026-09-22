<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §22): "closing
 * client debt" — clarified via a follow-up question — needs to cover not
 * only a single guest's post-checkout balance (Accounts Receivable
 * already existed for that, just with no way to settle it — see the
 * `accounts_receivable` company_id migration below and
 * AccountsReceivableService) but also debt owed by a company/corporate
 * client across multiple stays and guests, with its own running balance.
 * A Company is a lightweight billing entity — a hotel guest is still a
 * real Guest row (a person who actually stayed); a Company is who's
 * paying, optionally, for one or more of those stays. No credit-limit
 * enforcement is built here (not asked for) — `credit_limit` is
 * informational only, shown alongside the company's running outstanding
 * balance so the owner can watch it, not something that blocks a
 * check-in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('companies', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('name', 150);
            $table->string('contact_name', 150)->nullable();
            $table->string('phone', 40)->nullable();
            $table->string('email', 255)->nullable();
            $table->decimal('credit_limit', 14, 2)->nullable()->comment('Informational only — not enforced at check-in.');
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('companies');
    }
};
