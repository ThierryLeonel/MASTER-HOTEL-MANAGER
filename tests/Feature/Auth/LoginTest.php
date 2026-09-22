<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PragmaRX\Google2FA\Google2FA;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

class LoginTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_password_only_user_can_log_in(): void
    {
        $user = $this->userWithRole('duty_manager', [
            'auth_type' => 'password_only',
            'password' => bcrypt('correct-password'),
        ]);

        $this->post(route('login.identify'), ['username' => $user->username])
            ->assertRedirect(route('login.password'));

        $response = $this->post(route('login.password.attempt'), ['password' => 'correct-password']);

        $response->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_wrong_password_is_rejected_and_does_not_log_in(): void
    {
        $user = $this->userWithRole('duty_manager', [
            'auth_type' => 'password_only',
            'password' => bcrypt('correct-password'),
        ]);

        $this->post(route('login.identify'), ['username' => $user->username]);

        $response = $this->post(route('login.password.attempt'), ['password' => 'wrong-password']);

        $response->assertSessionHasErrors('password');
        $this->assertGuest();
    }

    public function test_password_mfa_user_must_enroll_totp_before_first_login_completes(): void
    {
        $user = $this->mfaUser('accountant');
        $this->assertFalse($user->totp_enabled);

        $this->post(route('login.identify'), ['username' => $user->username]);
        $this->post(route('login.password.attempt'), ['password' => 'password'])
            ->assertRedirect(route('login.totp.setup'));

        // Not logged in yet — MFA enrollment is not complete.
        $this->assertGuest();

        $setupPage = $this->get(route('login.totp.setup'));
        $setupPage->assertOk();

        $secret = session('auth.totp_setup_secret');
        $this->assertNotEmpty($secret);

        $validCode = (new Google2FA)->getCurrentOtp($secret);

        $this->post(route('login.totp.setup.attempt'), ['code' => $validCode])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
        $this->assertTrue($user->fresh()->totp_enabled);
    }

    public function test_password_mfa_user_with_totp_enabled_needs_valid_code(): void
    {
        $secret = (new Google2FA)->generateSecretKey();
        $user = $this->mfaUser('owner');
        $user->forceFill([
            'totp_enabled' => true,
            'totp_secret' => $secret,
            'totp_confirmed_at' => now(),
        ])->save();

        $this->post(route('login.identify'), ['username' => $user->username]);
        $this->post(route('login.password.attempt'), ['password' => 'password'])
            ->assertRedirect(route('login.totp'));

        // Wrong code fails.
        $this->post(route('login.totp.attempt'), ['code' => '000000'])
            ->assertSessionHasErrors('code');
        $this->assertGuest();

        // Correct code succeeds.
        $validCode = (new Google2FA)->getCurrentOtp($secret);
        $this->post(route('login.totp.attempt'), ['code' => $validCode])
            ->assertRedirect(route('dashboard'));
        $this->assertAuthenticatedAs($user);
    }

    public function test_pin_user_can_log_in_with_correct_pin(): void
    {
        $user = $this->pinUser('cashier', '4321');

        $this->post(route('login.identify'), ['username' => $user->username])
            ->assertRedirect(route('login.pin'));

        $this->post(route('login.pin.attempt'), ['pin' => '4321'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_pin_user_is_locked_out_after_max_failed_attempts(): void
    {
        $user = $this->pinUser('front_desk', '4321');

        $this->post(route('login.identify'), ['username' => $user->username]);

        // pin_max_attempts is seeded to 5.
        for ($i = 0; $i < 5; $i++) {
            $this->post(route('login.pin.attempt'), ['pin' => 'wrong'])->assertSessionHasErrors('pin');
        }

        $this->assertTrue($user->fresh()->isPinLocked());

        // Even the correct PIN is now rejected while locked.
        $response = $this->post(route('login.pin.attempt'), ['pin' => '4321']);
        $response->assertSessionHasErrors('pin');
        $this->assertGuest();
    }

    public function test_authenticated_user_can_log_out(): void
    {
        $user = $this->userWithRole('duty_manager', ['auth_type' => 'password_only']);

        $this->actingAs($user)->post(route('logout'))->assertRedirect(route('login'));

        $this->assertGuest();
    }

    public function test_inactive_user_cannot_start_login(): void
    {
        $user = $this->userWithRole('front_desk', ['is_active' => false, 'auth_type' => 'pin', 'pin_hash' => bcrypt('1234')]);

        $response = $this->post(route('login.identify'), ['username' => $user->username]);

        // Security fix: an inactive account no longer bounces back to
        // /login with a "username" error — it proceeds into the same
        // two-step flow a real, active account would (see
        // LoginEnumerationTest), so there is no longer a "username"
        // session error to assert here at all.
        $response->assertRedirect();
        $this->assertNotSame(route('login'), $response->headers->get('Location'));

        // Whichever step it landed on, the account cannot actually
        // complete login through it.
        $this->post(route('login.pin.attempt'), ['pin' => '1234']);
        $this->post(route('login.password.attempt'), ['password' => 'password']);
        $this->assertGuest();
    }

    public function test_the_login_flow_is_rate_limited_per_ip(): void
    {
        // Security-audit fix (Phase 7): /login (the username lookup step)
        // previously had no rate limiting at all, unlike the password and
        // TOTP attempt steps. The whole unauthenticated login route group
        // is now wrapped in a blanket throttle:20,1 (20 requests/minute/IP).
        $user = $this->userWithRole('front_desk', ['auth_type' => 'pin', 'pin_hash' => bcrypt('1234')]);

        for ($i = 0; $i < 20; $i++) {
            $this->post(route('login.identify'), ['username' => $user->username]);
        }

        $response = $this->post(route('login.identify'), ['username' => $user->username]);

        $response->assertStatus(429);
    }
}
