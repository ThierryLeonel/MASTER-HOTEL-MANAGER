<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progressive bracket rows for a `progressive_brackets` deduction type
 * — e.g. an income-tax scale with several bands, each taxed at its own
 * rate on only the portion of gross pay that falls within it (the
 * standard progressive-tax computation, never a flat rate on the
 * whole amount). `max_amount = null` marks the open-ended top bracket.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deduction_brackets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payroll_deduction_type_id')->constrained('payroll_deduction_types')->cascadeOnDelete();
            $table->decimal('min_amount', 12, 2);
            $table->decimal('max_amount', 12, 2)->nullable()->comment('Null = open-ended top bracket.');
            $table->decimal('rate_percent', 5, 2);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();

            $table->index('payroll_deduction_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deduction_brackets');
    }
};
