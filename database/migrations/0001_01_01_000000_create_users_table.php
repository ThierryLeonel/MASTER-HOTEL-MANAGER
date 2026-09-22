<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('username')->unique()->comment('Login handle used by PIN-based staff logins (Cashier/Front Desk/Bar/Restaurant) and shown throughout the UI.');
            $table->string('email')->unique();
            $table->timestamp('email_verified_at')->nullable();

            // Auth model varies by role (spec section 9):
            //   password_only -> Duty Manager
            //   password_mfa  -> Owner, General Manager, Accountant (password + TOTP)
            //   pin           -> Cashier, Front Desk, Bar Staff, Restaurant Staff
            $table->enum('auth_type', ['password_only', 'password_mfa', 'pin'])->default('password_only');
            $table->string('password')->nullable()->comment('Null for pin-auth users; bcrypt hash otherwise.');
            $table->string('pin_hash')->nullable()->comment('Bcrypt hash of a 4-6 digit PIN; null for password-auth users.');

            // TOTP (MFA) — secret is encrypted at rest via the model cast, never stored plaintext.
            $table->boolean('totp_enabled')->default(false);
            $table->text('totp_secret')->nullable();
            $table->text('totp_recovery_codes')->nullable();
            $table->timestamp('totp_confirmed_at')->nullable();

            // FK to departments added in a later migration (departments table is created after this one).
            $table->unsignedBigInteger('department_id')->nullable()->index();
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('failed_pin_attempts')->default(0);
            $table->timestamp('pin_locked_until')->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();

            $table->rememberToken();
            $table->timestamps();
            $table->softDeletes()->comment('Staff records are deactivated (is_active=false) in normal operation; soft-delete preserves history for records referenced by past transactions.');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('users');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
