<?php

namespace Tests\Feature\Auth;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Security fix regression coverage: LoginController::identify() used to
 * reveal, via its redirect target (or lack of one), both whether a
 * submitted username/email belonged to an active account and — if so —
 * whether it used PIN or password login. See LoginController's class
 * docblock for the full description of the fix. These tests exercise
 * the externally observable behavior only (routes, status codes,
 * session error keys) — never the internal pseudoUsesPinLogin() hash —
 * since that internal choice is deliberately an implementation detail.
 */
class LoginEnumerationTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_an_unknown_username_is_routed_forward_instead_of_erroring_at_identify(): void
    {
        $response = $this->post(route('login.identify'), ['username' => 'no-such-user-at-all']);

        // Before the fix this stayed on /login with a "username" session
        // error. It must now move forward into the two-step flow, exactly
        // like a real, active account does.
        $response->assertRedirect();
        $location = $response->headers->get('Location');
        $this->assertContains($location, [route('login.pin'), route('login.password')]);
        $response->assertSessionDoesntHaveErrors('username');

        $this->assertGuest();
    }

    public function test_an_unknown_username_reaches_a_real_login_pin_or_password_page(): void
    {
        $response = $this->post(route('login.identify'), ['username' => 'still-no-such-user']);
        $location = $response->headers->get('Location');

        $follow = $this->get($location);

        // The page renders normally (not a redirect back to /login) —
        // an attacker gets a real-looking next step either way.
        $follow->assertOk();
    }

    public function test_an_unknown_username_always_fails_the_next_step_with_a_generic_message_and_never_logs_in(): void
    {
        $response = $this->post(route('login.identify'), ['username' => 'yet-another-unknown-user']);
        $location = $response->headers->get('Location');

        if ($location === route('login.pin')) {
            $this->post(route('login.pin.attempt'), ['pin' => '0000'])
                ->assertSessionHasErrors('pin');
        } else {
            $this->post(route('login.password.attempt'), ['password' => 'anything'])
                ->assertSessionHasErrors('password');
        }

        $this->assertGuest();
    }

    public function test_the_same_unknown_username_is_routed_to_the_same_step_every_time(): void
    {
        // Consistency matters: a real account always routes the same way
        // for the same username, so a fake one must too, or the
        // inconsistency itself would become a distinguishing signal.
        $first = $this->post(route('login.identify'), ['username' => 'consistent-unknown-user']);
        $second = $this->post(route('login.identify'), ['username' => 'consistent-unknown-user']);

        $this->assertSame($first->headers->get('Location'), $second->headers->get('Location'));
    }

    public function test_a_real_active_username_still_logs_in_normally_after_the_fix(): void
    {
        // Regression guard: the fix must not break the ordinary,
        // legitimate case.
        $user = $this->pinUser('cashier', '9876');

        $this->post(route('login.identify'), ['username' => $user->username])
            ->assertRedirect(route('login.pin'));

        $this->post(route('login.pin.attempt'), ['pin' => '9876'])
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($user);
    }

    public function test_password_attempt_without_a_prior_identify_call_is_rejected(): void
    {
        $this->post(route('login.password.attempt'), ['password' => 'whatever'])
            ->assertStatus(419);

        $this->assertGuest();
    }

    public function test_pin_attempt_without_a_prior_identify_call_is_rejected(): void
    {
        $this->post(route('login.pin.attempt'), ['pin' => '1234'])
            ->assertStatus(419);

        $this->assertGuest();
    }
}
