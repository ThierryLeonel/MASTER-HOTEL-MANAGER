<?php

namespace Tests\Feature\Public;

use App\Models\Guest;
use App\Models\Reservation;
use App\Models\RoomCategory;
use App\Models\User;
use Database\Seeders\WebsiteBookingSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Fourth post-9.8 fix round ("update the system such that it can catch
 * reservations from the website too"). Covers the public, unauthenticated
 * booking page end-to-end: the form renders without login, a submission
 * always lands as a normal `pending` reservation attributed to the seeded
 * "Website Booking" system user (never bypassing the approval gate from
 * spec section 22), a guest is matched by phone or created, an optional
 * deposit-proof upload is stored and surfaced to staff without ever
 * touching the payment ledger, spam protection (honeypot) and validation
 * are enforced, and staff can approve/reject a website-sourced reservation
 * exactly like any other pending one.
 */
class WebsiteBookingTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
        $this->seed(WebsiteBookingSeeder::class);
    }

    public function test_the_public_booking_form_renders_without_authentication(): void
    {
        RoomCategory::factory()->create(['name' => 'Deluxe Room', 'is_active' => true]);

        $response = $this->get(route('public.booking.create'));

        $response->assertOk();
        $response->assertSee('Deluxe Room');
    }

    public function test_a_guest_can_submit_a_booking_request_and_it_lands_as_a_pending_website_reservation(): void
    {
        $category = RoomCategory::factory()->create(['is_active' => true, 'base_rate' => 40000]);

        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'last_name' => 'Njoya',
            'phone' => '+237699112233',
            'email' => 'amina@example.com',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(3)->toDateString(),
            'adults' => 2,
            'children' => 1,
            'notes' => 'Late arrival, around 10pm',
        ]);

        $reservation = Reservation::first();
        $response->assertRedirect(route('public.booking.success', ['ref' => $reservation->reservation_number]));

        $this->assertNotNull($reservation);
        $this->assertSame('pending', $reservation->status);
        $this->assertSame('website', $reservation->source);
        $this->assertTrue($reservation->isFromWebsite());
        $this->assertSame($category->id, $reservation->room_category_id);
        $this->assertEquals(40000, $reservation->rate_quoted);

        $systemUser = User::where('email', WebsiteBookingSeeder::EMAIL)->firstOrFail();
        $this->assertSame($systemUser->id, $reservation->requested_by);

        $guest = Guest::where('phone', '+237699112233')->firstOrFail();
        $this->assertSame($guest->id, $reservation->guest_id);
        $this->assertSame('Amina', $guest->first_name);
    }

    public function test_a_booking_request_from_a_returning_guest_is_matched_by_phone_instead_of_duplicated(): void
    {
        $category = RoomCategory::factory()->create(['is_active' => true]);
        $existingGuest = Guest::factory()->create(['phone' => '+237677001122']);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Someone',
            'phone' => '+237677001122',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $this->assertSame(1, Guest::where('phone', '+237677001122')->count());
        $reservation = Reservation::first();
        $this->assertSame($existingGuest->id, $reservation->guest_id);
    }

    public function test_the_success_page_shows_the_reservation_reference(): void
    {
        $response = $this->get(route('public.booking.success', ['ref' => 'RSV-20260101-ABCDE']));

        $response->assertOk();
        $response->assertSee('RSV-20260101-ABCDE');
    }

    public function test_a_guest_can_attach_a_deposit_screenshot_and_claimed_amount_without_it_being_recorded_as_a_payment(): void
    {
        Storage::fake('public');
        $category = RoomCategory::factory()->create(['is_active' => true]);
        $file = UploadedFile::fake()->image('deposit.jpg');

        $this->post(route('public.booking.store'), [
            'first_name' => 'Paul',
            'phone' => '+237655443322',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'deposit_claimed_amount' => 15000,
            'deposit_reference' => 'MTN MoMo TX998877',
            'deposit_proof' => $file,
        ]);

        $reservation = Reservation::first();
        $this->assertEquals(15000, $reservation->deposit_claimed_amount);
        $this->assertSame('MTN MoMo TX998877', $reservation->deposit_reference);
        $this->assertNotNull($reservation->deposit_proof_path);
        Storage::disk('public')->assertExists($reservation->deposit_proof_path);

        // The claim is purely informational — no Payment row is created and
        // the reservation's real `deposit_paid` ledger total is untouched.
        // Only ReservationController::recordDeposit() (permission-gated,
        // backed by the single-writer PaymentLedgerService) can do that.
        $this->assertEquals(0, $reservation->deposit_paid);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_non_image_non_pdf_deposit_proof_is_rejected(): void
    {
        Storage::fake('public');
        $category = RoomCategory::factory()->create(['is_active' => true]);
        $file = UploadedFile::fake()->create('notes.txt', 10);

        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'Paul',
            'phone' => '+237655443322',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'deposit_proof' => $file,
        ]);

        $response->assertSessionHasErrors('deposit_proof');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_filled_honeypot_field_silently_creates_no_reservation(): void
    {
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $response = $this->post(route('public.booking.store'), [
            'company_website' => 'https://spambot.example.com',
            'first_name' => 'Bot',
            'phone' => '0000000000',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertRedirect(route('public.booking.success'));
        $this->assertDatabaseCount('reservations', 0);
        $this->assertDatabaseCount('guests', 0);
    }

    /** @dataProvider missingRequiredFieldProvider */
    public function test_required_fields_are_validated(string $field): void
    {
        $category = RoomCategory::factory()->create(['is_active' => true]);
        $payload = [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ];
        unset($payload[$field]);

        $response = $this->post(route('public.booking.store'), $payload);

        $response->assertSessionHasErrors($field);
        $this->assertDatabaseCount('reservations', 0);
    }

    public static function missingRequiredFieldProvider(): array
    {
        return [
            'first name' => ['first_name'],
            'phone' => ['phone'],
            'room category' => ['room_category_id'],
            'check-in date' => ['check_in_date'],
            'check-out date' => ['check_out_date'],
        ];
    }

    public function test_an_inactive_room_category_is_rejected(): void
    {
        $category = RoomCategory::factory()->create(['is_active' => false]);

        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertSessionHasErrors('room_category_id');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_nonexistent_room_category_is_rejected(): void
    {
        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => 999999,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertSessionHasErrors('room_category_id');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_check_out_must_be_after_check_in(): void
    {
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDays(3)->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertSessionHasErrors('check_out_date');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_a_check_in_date_in_the_past_is_rejected(): void
    {
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->subDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertSessionHasErrors('check_in_date');
        $this->assertDatabaseCount('reservations', 0);
    }

    public function test_the_public_booking_endpoint_is_rate_limited(): void
    {
        $category = RoomCategory::factory()->create(['is_active' => true]);

        for ($i = 0; $i < 10; $i++) {
            $this->post(route('public.booking.store'), [
                'first_name' => 'Amina',
                'phone' => '+23769911'.str_pad((string) $i, 4, '0', STR_PAD_LEFT),
                'room_category_id' => $category->id,
                'check_in_date' => now()->addDay()->toDateString(),
                'check_out_date' => now()->addDays(2)->toDateString(),
            ]);
        }

        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'OneTooMany',
            'phone' => '+237699119999',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $response->assertStatus(429);
    }

    public function test_staff_can_approve_a_website_sourced_reservation_exactly_like_any_other_pending_one(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);
        $reservation = Reservation::first();

        $response = $this->actingAs($frontDesk)->post(route('reservations.approve', $reservation));

        $response->assertRedirect();
        $this->assertSame('confirmed', $reservation->fresh()->status);
    }

    public function test_staff_can_reject_a_website_sourced_reservation(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);
        $reservation = Reservation::first();

        $response = $this->actingAs($frontDesk)->post(route('reservations.reject', $reservation), [
            'reason' => 'Room type no longer available for those dates',
        ]);

        $response->assertRedirect();
        $this->assertSame('rejected', $reservation->fresh()->status);
    }

    /**
     * Follow-up request ("let the amount sent be posted to the ledger
     * only when the reservation is approved"). Approving is the only
     * moment a guest-claimed deposit is allowed to become a real
     * `payments` row — see ReservationService::approve()'s docblock.
     * Posting goes through PaymentLedgerService with sourceModule
     * `reservation_deposit`, the same one manual staff deposit-recording
     * already uses, which is why it needs a Front Office shift open —
     * the exact same rule that already applied to recording a deposit
     * manually (§18.1), just triggered at a different moment now.
     */
    public function test_approving_a_website_reservation_with_a_claimed_deposit_and_method_posts_it_to_the_ledger(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $this->openShiftFor('FO');
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'deposit_claimed_amount' => 20000,
            'deposit_claimed_method' => 'mobile_money',
            'deposit_reference' => 'MTN MoMo TX445566',
        ]);
        $reservation = Reservation::first();
        $this->assertDatabaseCount('payments', 0, 'Submitting the request itself must never post to the ledger.');

        $this->actingAs($frontDesk)->post(route('reservations.approve', $reservation))->assertRedirect();

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertEquals(20000, $reservation->deposit_paid);
        $this->assertDatabaseCount('payments', 1);
        $payment = \App\Models\Payment::first();
        $this->assertEquals(20000, $payment->amount);
        $this->assertSame('mobile_money', $payment->method);
        $this->assertSame('reservation_deposit', $payment->source_module);
        $this->assertSame($reservation->guest_id, $payment->guest_id);
    }

    public function test_approving_a_website_reservation_with_a_deposit_claim_requires_an_open_front_office_shift(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        // Departments must exist for the shift gate to resolve Front
        // Office at all (PaymentLedgerService::resolveDepartmentId()
        // silently skips the gate if the department can't be found — see
        // ShiftGateTest for the same setup) — but deliberately no shift
        // is opened, since that's exactly what this test is proving.
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'deposit_claimed_amount' => 20000,
            'deposit_claimed_method' => 'mobile_money',
        ]);
        $reservation = Reservation::first();

        $threw = false;
        try {
            app(\App\Services\FrontOffice\ReservationService::class)->approve($reservation, $frontDesk);
        } catch (\App\Exceptions\HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Approving a reservation with a deposit claim must require an open Front Office shift.');
        $this->assertSame('pending', $reservation->fresh()->status, 'A blocked approval must not leave the reservation half-approved.');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_website_reservation_with_no_deposit_claim_can_still_be_approved_without_an_open_shift(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);
        $reservation = Reservation::first();

        $this->actingAs($frontDesk)->post(route('reservations.approve', $reservation))->assertRedirect();

        $this->assertSame('confirmed', $reservation->fresh()->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_a_claimed_amount_with_no_claimed_method_is_never_auto_posted_even_on_approval(): void
    {
        // Defensive coverage: the public form's own validation requires
        // both fields together, but the service itself must never guess
        // a payment method — proven directly against the service with a
        // row manipulated into this state, bypassing the form.
        $frontDesk = $this->pinUser('front_desk');
        $this->openShiftFor('FO');
        $category = RoomCategory::factory()->create(['is_active' => true]);
        $reservation = Reservation::factory()->for(Guest::factory())->create([
            'room_category_id' => $category->id,
            'status' => 'pending',
            'source' => 'website',
            'deposit_claimed_amount' => 15000,
            'deposit_claimed_method' => null,
        ]);

        app(\App\Services\FrontOffice\ReservationService::class)->approve($reservation, $frontDesk);

        $reservation->refresh();
        $this->assertSame('confirmed', $reservation->status);
        $this->assertEquals(0, $reservation->deposit_paid);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_approving_an_already_approved_reservation_throws_and_never_posts_the_deposit_twice(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $this->openShiftFor('FO');
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
            'deposit_claimed_amount' => 20000,
            'deposit_claimed_method' => 'mobile_money',
        ]);
        $reservation = Reservation::first();

        app(\App\Services\FrontOffice\ReservationService::class)->approve($reservation->fresh(), $frontDesk);
        $this->assertDatabaseCount('payments', 1);

        $threw = false;
        try {
            app(\App\Services\FrontOffice\ReservationService::class)->approve($reservation->fresh(), $frontDesk);
        } catch (\App\Exceptions\HmsException $e) {
            $threw = true;
        }

        $this->assertTrue($threw, 'Approving an already-approved reservation must throw, not silently succeed.');
        $this->assertDatabaseCount('payments', 1, 'A second approve() call must never post a duplicate deposit payment.');
    }

    public function test_the_reservations_list_shows_a_website_badge_for_a_website_sourced_reservation(): void
    {
        $frontDesk = $this->pinUser('front_desk');
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $response = $this->actingAs($frontDesk)->get(route('reservations.index'));

        $response->assertOk();
        $response->assertSee('Website');
    }

    public function test_the_website_booking_system_user_has_no_role_and_cannot_log_in(): void
    {
        $systemUser = User::where('email', WebsiteBookingSeeder::EMAIL)->firstOrFail();

        $this->assertFalse($systemUser->is_active);
        $this->assertCount(0, $systemUser->roles);
    }

    /**
     * Reproduces a real production incident directly: on the live
     * install, only `db:seed --class=RolesAndPermissionsSeeder` had ever
     * been run — not the full `db:seed` — so `WebsiteBookingSeeder`
     * (added in a later phase) never created this account, and every
     * single website booking submission 404'd. The old code was
     * `User::where(...)->firstOrFail()`, and Laravel's default exception
     * handler renders a ModelNotFoundException as a bare HTTP 404 — with
     * nothing anywhere hinting the real cause was a missing seeded row,
     * not a missing route. ReservationService::createFromWebsite() now
     * self-heals via firstOrCreate() instead, so this can't recur
     * regardless of seeding history.
     */
    public function test_a_booking_still_succeeds_even_if_the_website_booking_system_user_was_never_seeded(): void
    {
        User::where('email', WebsiteBookingSeeder::EMAIL)->delete();
        $category = RoomCategory::factory()->create(['is_active' => true]);

        $response = $this->post(route('public.booking.store'), [
            'first_name' => 'Amina',
            'phone' => '+237699112233',
            'room_category_id' => $category->id,
            'check_in_date' => now()->addDay()->toDateString(),
            'check_out_date' => now()->addDays(2)->toDateString(),
        ]);

        $reservation = Reservation::first();
        $this->assertNotNull($reservation, 'The reservation must still be created even if the system user was missing.');
        $response->assertRedirect(route('public.booking.success', ['ref' => $reservation->reservation_number]));

        $systemUser = User::where('email', WebsiteBookingSeeder::EMAIL)->first();
        $this->assertNotNull($systemUser, 'The system user must be self-healed, not left missing (which is what produced the live 404).');
        $this->assertSame($systemUser->id, $reservation->requested_by);
        $this->assertFalse($systemUser->is_active, 'A self-healed system user must match the seeder exactly: never active, never able to log in.');
        $this->assertCount(0, $systemUser->roles);
    }

    public function test_the_self_healed_system_user_is_not_duplicated_across_concurrent_or_repeated_submissions(): void
    {
        User::where('email', WebsiteBookingSeeder::EMAIL)->delete();
        $category = RoomCategory::factory()->create(['is_active' => true]);

        foreach (['+237699112201', '+237699112202'] as $i => $phone) {
            $this->post(route('public.booking.store'), [
                'first_name' => 'Guest'.$i,
                'phone' => $phone,
                'room_category_id' => $category->id,
                'check_in_date' => now()->addDay()->toDateString(),
                'check_out_date' => now()->addDays(2)->toDateString(),
            ]);
        }

        $this->assertSame(1, User::where('email', WebsiteBookingSeeder::EMAIL)->count());
        $this->assertSame(2, Reservation::count());
    }

    /**
     * Companion fix, same incident: with no custom error view in the
     * project, a guest who hit the 404 above saw Laravel's bare default
     * error page — which is what actually prompted the "not responsive
     * on my phone" report (it wasn't the booking form, it was the plain
     * page a guest landed on after the 404 fired). A branded, responsive
     * 404 page now exists regardless of what triggers a 404 in the
     * future.
     */
    public function test_a_missing_page_renders_the_branded_responsive_404_page(): void
    {
        $response = $this->get('/this-route-does-not-exist');

        $response->assertStatus(404);
        $response->assertSee('Page not found');
        $response->assertSee('width=device-width', false);
    }
}
