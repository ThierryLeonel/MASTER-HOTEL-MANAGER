<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Direct request ("WE ALSO NEED RESERVATION FOR EVENT: WHAT KIND OF
 * EVENT, WHICH SPACES DO THEY WANT TO RESERVE, ROOM, WHICH ROOMS
 * (SELECT), MAIN HALL, MINI HALL FOR EXAMPLE, POOL OR THE WHOLE OUTER
 * ENVIROMENT (SPACE) ETC"), clarified via AskUserQuestion: an admin-
 * editable list of bookable event venues (Main Hall, Mini Hall, Pool/
 * outdoor area, Conference Hall, and anything else an admin later adds —
 * "you know better" on the exact mechanism, so this follows the same
 * "managed list" pattern already proven by ChargeType/IncomeSource rather
 * than a hardcoded enum), plus a full event-booking record capturing
 * event type, expected guest count, a date/time range, and deposit/
 * approval handling mirroring how room Reservations already work
 * (explicitly requested: "mirroring how room reservations already handle
 * deposits and approval").
 *
 * `event_spaces` is deliberately its own small table rather than reusing
 * `facilities`/`charge_types` — an event space is not something a guest
 * is ever individually charged for walking in (it has no per-use rate at
 * all), it is a venue that gets HELD for a block of time, which is a
 * fundamentally different thing to model than a chargeable item.
 *
 * `event_reservations` can book any combination of managed event spaces
 * (`event_reservation_spaces`) AND/OR specific lodging rooms
 * (`event_reservation_rooms` — "WHICH ROOMS (SELECT)") — e.g. a wedding
 * might book the Main Hall for the reception and three specific rooms for
 * the wedding party, in one single event reservation. Both are separate
 * pivot tables rather than a single polymorphic one, since a room is an
 * existing, heavily-used entity (rooms) with its own double-booking rules
 * (Room::isAvailableFor(), already reused directly by
 * EventReservationService to prevent a room being double-booked between
 * a real guest Stay/Reservation and an event), while an EventSpace is
 * brand new and only ever conflicts with another event reservation.
 *
 * Deliberately NOT built this round (disclosed explicitly rather than
 * silently omitted — scope kept deliberately tight for a first,
 * reviewable version): deposit disposition (refund/forfeit/transfer) on
 * cancellation — unlike Reservation, an EventReservation's deposit simply
 * stays recorded with no further automated ledger movement when
 * cancelled; a manager handles any refund manually via the existing
 * Finance > Refunds screen if one is actually owed. No automatic tie-in
 * to the Room status state machine either — booking a room for an event
 * here does not flip Room.status or create a guest Stay; it is purely a
 * scheduling/availability reference so staff can see those rooms are
 * earmarked, and the front desk still runs an ordinary check-in
 * separately for whoever actually occupies them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('event_spaces', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('name', 100)->unique();
            $table->text('description')->nullable();
            $table->unsignedInteger('max_capacity')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('event_reservations', function (Blueprint $table) {
            $table->id();
            $table->ulid('ulid')->nullable()->unique();
            $table->string('event_reference', 30)->unique();
            $table->string('event_name', 150);
            // Free text ("what kind of event") rather than another managed
            // list — wedding/conference/birthday/etc. naming is highly
            // variable and, unlike an event space, carries no
            // availability/capacity rule of its own that would need a
            // real row to attach to.
            $table->string('event_type', 100);
            $table->foreignId('guest_id')->nullable()->constrained('guests')->nullOnDelete()
                ->comment('The booking contact, if an existing guest — mirrors reservations.guest_id.');
            // Mirrors the inline-contact pattern Reservation::createFromWebsite()
            // and CheckInController's own inline-guest-creation already use:
            // an event organizer is very often NOT a lodging guest at all.
            $table->string('contact_name', 150)->nullable();
            $table->string('contact_phone', 30)->nullable();
            $table->string('contact_email', 255)->nullable();
            $table->unsignedInteger('expected_guests')->nullable();
            $table->dateTime('start_at');
            $table->dateTime('end_at');
            $table->enum('status', ['pending', 'confirmed', 'completed', 'cancelled', 'rejected'])->default('pending')->index();
            $table->decimal('agreed_amount', 12, 2)->nullable()->comment('Total agreed price for the event, if one was quoted up front.');
            $table->decimal('deposit_required', 12, 2)->default(0);
            $table->decimal('deposit_paid', 12, 2)->default(0);
            $table->foreignId('requested_by')->constrained('users')->restrictOnDelete();
            $table->foreignId('approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->text('rejection_reason')->nullable();
            $table->text('cancellation_reason')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['start_at', 'end_at']);
        });

        Schema::create('event_reservation_spaces', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_reservation_id')->constrained('event_reservations')->cascadeOnDelete();
            $table->foreignId('event_space_id')->constrained('event_spaces')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['event_reservation_id', 'event_space_id']);
        });

        Schema::create('event_reservation_rooms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_reservation_id')->constrained('event_reservations')->cascadeOnDelete();
            $table->foreignId('room_id')->constrained('rooms')->restrictOnDelete();
            $table->timestamps();
            $table->unique(['event_reservation_id', 'room_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reservation_rooms');
        Schema::dropIfExists('event_reservation_spaces');
        Schema::dropIfExists('event_reservations');
        Schema::dropIfExists('event_spaces');
    }
};
