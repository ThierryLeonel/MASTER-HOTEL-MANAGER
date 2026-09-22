<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * HR-specific fields for a staff account, kept separate from `users`
 * (spec section 8/9's account/auth model) rather than bolted onto it —
 * not every user necessarily has payroll data on day one, and this
 * keeps the authentication table focused on authentication. One row
 * per user; `basic_salary` is what PayrollService reads to generate a
 * payslip, so an employee with no profile (or no basic_salary) is
 * simply not payroll-eligible yet.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('employee_profiles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->unique()->constrained('users')->cascadeOnDelete();
            $table->string('employee_number')->unique();
            $table->string('job_title');
            $table->enum('employment_type', ['full_time', 'part_time', 'contract'])->default('full_time');
            $table->date('hire_date');
            $table->date('termination_date')->nullable();
            $table->decimal('basic_salary', 12, 2)->nullable()->comment('Monthly basic salary in the configured currency. Null = not yet payroll-eligible.');
            $table->enum('payment_method', ['cash', 'bank_transfer', 'mobile_money'])->default('bank_transfer');
            $table->string('bank_account')->nullable();
            $table->string('national_id')->nullable();
            $table->enum('employment_status', ['active', 'suspended', 'terminated'])->default('active');
            $table->timestamps();

            $table->index('employment_status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('employee_profiles');
    }
};
