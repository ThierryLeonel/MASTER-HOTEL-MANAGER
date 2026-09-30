<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request (Sept 2026): "normally we have bar charge, restaurant
 * charge, frontdesk charge, rental charge, pool fee, Gym Fee, etc
 * include all connect them and charges can be posted and payment can be
 * received and recorded to the different sources, just build it in such
 * a way i can edit and or just add or delete." Clarified with the user
 * before building this: one unified list, with Gym & Swimming Pool
 * folded in rather than kept as a separate module.
 *
 * Before this, "what can be charged to a guest" was a hardcoded PHP
 * constant (`FolioTransaction::CHARGE_TYPES`, five fixed values with no
 * admin screen at all), and "what can be sold on the spot to a walk-in"
 * was a separate, differently-shaped table (`facilities`/
 * `facility_rates`, Gym & Swimming Pool only). This table replaces both:
 * one row per charge type, each one independently flaggable as postable
 * to a guest's folio (`postable_to_folio`, deferred, paid at checkout —
 * what the old CHARGE_TYPES const covered), sellable on the spot to a
 * walk-in or resident guest (`walk_in_payable` — what the old Facility
 * table covered), or both.
 *
 * `department_id` is the same lever this engagement already built for
 * manually-recorded general payments (see
 * FinancialReportService::revenueByChargeType()'s Sept 2026 docblock) —
 * optional, and what lets a charge type's real revenue be correctly
 * recognised under the right department in every report, instead of
 * needing another hardcoded mapping updated by hand every time a new
 * charge type is added.
 *
 * `is_system` marks the handful of charge types this codebase relies on
 * by their exact `code` string in more than one place (room_charge's
 * complimentary-stay waiver in FolioService::postCharge(), for one) —
 * ChargeTypeController refuses to delete OR deactivate one of these, and
 * never lets its `code` be edited, but its `name`/`description`/
 * `department_id` stay fully editable like any other row. Every other
 * charge type — anything the user adds from here on, Rental Charge
 * included — has `is_system = false` and can be freely edited,
 * deactivated, or deleted (subject to the same "never delete something
 * with real history against it, deactivate instead" rule already used
 * for Income Source and the old Facility/FacilityRate tables).
 *
 * See the very next migration for how this table is actually populated —
 * kept separate so the schema and the one-time data backfill are each
 * easy to read and reason about on their own.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('charge_types', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('code', 40)->unique();
            $table->string('name', 100);
            $table->text('description')->nullable();
            $table->foreignId('department_id')->nullable()->constrained('departments')->nullOnDelete();
            $table->boolean('postable_to_folio')->default(true);
            $table->boolean('walk_in_payable')->default(false);
            // Mirrors FacilityAccessService::grantForGuest()'s existing
            // "free for a currently checked-in guest" path — only ever
            // consulted when walk_in_payable is also true.
            $table->boolean('free_for_resident_guests')->default(false);
            $table->boolean('is_system')->default(false);
            $table->boolean('is_active')->default(true);
            // Purely internal plumbing, never shown in the admin UI: when
            // this charge type is walk-in-payable, it is backed by a real
            // row in the pre-existing `facilities` table so
            // `facility_accesses.facility_id` (NOT NULL, already relied
            // on by real, tested history) keeps being satisfied exactly
            // as before — see ChargeTypeService's docblock for the full
            // reasoning. Null for a charge type that was never
            // walk-in-payable.
            $table->foreignId('legacy_facility_id')->nullable()->unique()->constrained('facilities')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('charge_type_rates', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->foreignId('charge_type_id')->constrained('charge_types')->cascadeOnDelete();
            $table->string('name', 100);
            $table->decimal('amount', 12, 2);
            $table->boolean('is_active')->default(true);
            // Same internal-plumbing reasoning as charge_types.legacy_facility_id above.
            $table->foreignId('legacy_facility_rate_id')->nullable()->unique()->constrained('facility_rates')->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('charge_type_rates');
        Schema::dropIfExists('charge_types');
    }
};
