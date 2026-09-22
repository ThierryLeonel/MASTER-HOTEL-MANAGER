<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('guests', function (Blueprint $table) {
            $table->id();
            $table->string('first_name');
            $table->string('last_name');
            $table->string('phone', 30)->nullable()->index();
            $table->string('email')->nullable()->index();
            $table->string('id_document_type', 40)->nullable()->comment('passport, national_id, drivers_license, other');
            $table->string('id_document_number', 100)->nullable();
            $table->string('nationality', 80)->nullable();
            $table->text('address')->nullable();
            $table->date('date_of_birth')->nullable();
            $table->text('notes')->nullable();
            $table->boolean('is_blacklisted')->default(false);
            $table->text('blacklist_reason')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index(['last_name', 'first_name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('guests');
    }
};
