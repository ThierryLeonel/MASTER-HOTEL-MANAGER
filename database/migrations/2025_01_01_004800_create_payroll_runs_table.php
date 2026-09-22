<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * draft -> approved -> paid. Separation of duties: `manage-payroll`
 * (Accountant/GM/Owner) can create a run and generate/regenerate its
 * payslips while still draft; only `approve-payroll-run` (GM/Owner —
 * deliberately not Accountant, so the preparer isn't also the signer)
 * can lock it; `pay-payroll-run` (Accountant/GM/Owner) then posts every
 * payslip to the central payment ledger, which is where the
 * accounting-day lock actually applies (see AccountingDayService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_runs', function (Blueprint $table) {
            $table->id();
            $table->date('period_start');
            $table->date('period_end');
            $table->enum('status', ['draft', 'approved', 'paid'])->default('draft');
            $table->foreignId('generated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['period_start', 'period_end']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_runs');
    }
};
