<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Fourth post-9.8 fix round ("update the system such that it can catch
 * reservations from the website too"): every Reservation row requires a
 * real `requested_by` (a NOT NULL foreign key to `users`, unchanged by
 * this round — see the companion migration's docblock for why altering
 * that column was deliberately avoided rather than made nullable). A
 * website submission has no logged-in staff member to attribute it to,
 * so this seeder creates one real, permanent system account —
 * "Website Booking" — that every website-submitted reservation is
 * attributed to instead. It holds no role and therefore no permission
 * at all, `is_active` is false so it can never actually log in even if
 * its (random, unknown-to-anyone) password were somehow guessed, and
 * `ReservationController::index()`/`show()` display it exactly like any
 * other `requested_by` user, which doubles as a clear, honest signal to
 * staff reviewing the pending queue that a given request came from the
 * public booking page rather than a colleague.
 *
 * Idempotent (firstOrCreate by the fixed email) — safe to re-run on an
 * install that already has this account.
 */
class WebsiteBookingSeeder extends Seeder
{
    public const EMAIL = 'website-booking@system.local';

    public function run(): void
    {
        User::firstOrCreate(
            ['email' => self::EMAIL],
            [
                'name' => 'Website Booking',
                'username' => 'website-booking',
                'auth_type' => 'password_only',
                'password' => Hash::make(Str::random(64)),
                'department_id' => null,
                'is_active' => false,
                'email_verified_at' => now(),
            ]
        );
    }
}
