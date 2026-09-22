<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Gym & Swimming Pool (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md
 * §20), verbatim from the user's own description: "we have a gym, and
 * swimming pool guest have free access but others can register for the
 * gym at the front desk and pay the registration fee even the swimming
 * pool slots can be paid at the receptionist (front desk) before granted
 * a access to the pool."
 *
 * Three tables:
 *  - `facilities`: the two facilities themselves (Gym, Pool) — a small
 *    lookup table rather than a hardcoded pair of strings, so a third
 *    facility (a spa, a tennis court) is just another row later, no code
 *    change.
 *  - `facility_rates`: what a facility can be sold for — "the gym
 *    registration fee" / "a pool slot" — configurable amounts (never
 *    hardcoded, matching every other price in this system) rather than a
 *    single fixed fee per facility, since a facility may reasonably sell
 *    more than one kind of access later (e.g. Pool: single slot vs a
 *    day pass) without a schema change.
 *  - `facility_accesses`: one row per grant of access — either free (a
 *    checked-in guest, `stay_id` set, no rate/charge) or paid (a walk-in,
 *    `stay_id` null, a `facility_rate_id` and the payment it was sold
 *    under).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('facilities', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('code', 30)->unique();
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('facility_rates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->string('name');
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('facility_accesses', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('access_number', 40)->unique();
            $table->foreignId('facility_id')->constrained('facilities')->restrictOnDelete();
            $table->foreignId('stay_id')->nullable()->constrained('stays')->nullOnDelete()
                ->comment('Set when this is free access for a currently checked-in guest.');
            $table->string('visitor_name', 150)->nullable()->comment('Set instead of stay_id for a paying walk-in.');
            $table->string('visitor_phone', 40)->nullable();
            $table->foreignId('facility_rate_id')->nullable()->constrained('facility_rates')->nullOnDelete();
            $table->decimal('amount_charged', 12, 2)->default(0);
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
            $table->foreignId('granted_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('granted_at')->useCurrent();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['facility_id', 'granted_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('facility_accesses');
        Schema::dropIfExists('facility_rates');
        Schema::dropIfExists('facilities');
    }
};
