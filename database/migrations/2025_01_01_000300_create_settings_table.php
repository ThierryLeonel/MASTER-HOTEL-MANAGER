<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Central configuration store (spec section 23: "Do not hardcode business
     * policy"). Every configurable threshold, rate, or operational rule reads
     * from this table at runtime via the Settings facade/service, seeded from
     * .env / SettingsSeeder on first install but editable afterward by
     * Owner/GM through the Settings screen without a code deploy.
     */
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->string('group')->default('general')->index()->comment('e.g. finance, front_office, payroll, security');
            $table->text('value')->nullable();
            $table->string('type', 20)->default('string')->comment('string|integer|decimal|boolean|json — used to cast value on read');
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
