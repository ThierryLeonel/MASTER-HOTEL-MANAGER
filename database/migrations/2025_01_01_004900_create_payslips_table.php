<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One payslip per employee per run. The denormalized totals here
 * (gross/net/etc.) are always derived from the itemized `payslip_lines`
 * at generation time — same "denormalized cache of a real ledger"
 * pattern as `folios.balance`. A payslip is only ever regenerated
 * (deleted + recreated) while both it and its run are still `draft`;
 * once the run is approved every payslip flips to `finalized` and is
 * never edited again — a correction belongs in the next run, not a
 * rewrite of this one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_run_id')->constrained('payroll_runs')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->restrictOnDelete();
            $table->decimal('basic_salary', 12, 2);
            $table->decimal('total_allowances', 12, 2)->default(0);
            $table->decimal('absence_deduction', 12, 2)->default(0);
            $table->decimal('gross_pay', 12, 2);
            $table->decimal('total_statutory_deductions', 12, 2)->default(0);
            $table->decimal('other_deductions', 12, 2)->default(0);
            $table->decimal('net_pay', 12, 2);
            $table->enum('status', ['draft', 'finalized', 'paid'])->default('draft');
            $table->string('payment_method')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();

            $table->unique(['payroll_run_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslips');
    }
};
