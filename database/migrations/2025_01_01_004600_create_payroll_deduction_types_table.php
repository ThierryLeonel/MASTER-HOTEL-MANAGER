<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The "configurable statutory deduction brackets" requirement (spec
 * section 23) modeled as real, editable data rather than a hardcoded
 * tax formula: a deduction type is either a flat percentage of gross
 * pay (e.g. a social-security contribution) or a progressive bracket
 * table (e.g. an income-tax scale) — see payroll_deduction_brackets
 * for the bracket rows a `progressive_brackets` type owns. Owner/GM
 * add, edit, or deactivate deduction types from the UI; nothing about
 * the tax/contribution schedule is baked into PHP code.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payroll_deduction_types', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->unique();
            $table->enum('calculation_method', ['flat_percent', 'progressive_brackets']);
            $table->decimal('flat_rate_percent', 5, 2)->nullable()->comment('Used only when calculation_method = flat_percent.');
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('display_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payroll_deduction_types');
    }
};
