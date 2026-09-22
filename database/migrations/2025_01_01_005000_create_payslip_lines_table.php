<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The real itemized breakdown behind each payslip's totals (basic
 * salary, any allowance, the unpaid-absence deduction, one line per
 * active statutory deduction type, any other manual deduction) — an
 * auditable ledger rather than a single opaque net figure.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payslip_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payslip_id')->constrained('payslips')->cascadeOnDelete();
            $table->enum('type', ['earning', 'deduction']);
            $table->string('label');
            $table->decimal('amount', 12, 2);
            $table->timestamps();

            $table->index('payslip_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payslip_lines');
    }
};
