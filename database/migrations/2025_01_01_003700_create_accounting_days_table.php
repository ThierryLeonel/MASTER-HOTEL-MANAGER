<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One row per calendar business date. A day is created (implicitly,
 * already open) the first time anything tries to post a payment on it
 * — see App\Services\Finance\AccountingDayService::today() — and is
 * locked exactly once by an explicit close-of-day action. Locking is
 * one-way: a mistake discovered after lock is fixed with a new
 * transaction on the current (open) day, never by reopening a locked
 * one (spec's "correction only via new transaction" rule, applied to
 * the calendar itself).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('accounting_days', function (Blueprint $table) {
            $table->id();
            $table->date('business_date')->unique();
            $table->enum('status', ['open', 'locked'])->default('open');
            $table->foreignId('locked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('locked_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accounting_days');
    }
};
