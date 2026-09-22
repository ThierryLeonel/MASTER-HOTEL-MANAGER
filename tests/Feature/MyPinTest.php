<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Post-9.8 fix round ("let each account be able to modify their personal
 * pin") — MyPinController, the self-service half of PIN management. The
 * admin half (only Owner/General Manager may RESET someone else's PIN)
 * was already true before this change, since 'manage-users' — the
 * permission UserController::resetPin() requires — is only ever granted
 * to those two roles (see RolesAndPermissionsSeeder); no code change was
 * needed for that half of the request.
 */
class MyPinTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_a_pin_user_can_change_their_own_pin_after_proving_the_current_one(): void
    {
        $user = $this->pinUser('front_desk', '1234');

        $this->actingAs($user)->post(route('my-pin.update'), [
            'current_pin' => '1234',
            'new_pin' => '5678',
            'new_pin_confirmation' => '5678',
        ])->assertRedirect();

        $this->assertTrue(Hash::check('5678', $user->fresh()->pin_hash));
    }

    public function test_the_wrong_current_pin_is_rejected(): void
    {
        $user = $this->pinUser('front_desk', '1234');

        $this->actingAs($user)->post(route('my-pin.update'), [
            'current_pin' => '0000',
            'new_pin' => '5678',
            'new_pin_confirmation' => '5678',
        ])->assertSessionHasErrors('current_pin');

        $this->assertTrue(Hash::check('1234', $user->fresh()->pin_hash));
    }

    public function test_the_new_pin_must_be_confirmed(): void
    {
        $user = $this->pinUser('front_desk', '1234');

        $this->actingAs($user)->post(route('my-pin.update'), [
            'current_pin' => '1234',
            'new_pin' => '5678',
            'new_pin_confirmation' => '0000',
        ])->assertSessionHasErrors('new_pin');
    }

    public function test_a_password_login_account_cannot_use_this_screen(): void
    {
        $user = $this->userWithRole('owner', ['auth_type' => 'password_only']);

        $this->actingAs($user)->post(route('my-pin.update'), [
            'current_pin' => '1234',
            'new_pin' => '5678',
            'new_pin_confirmation' => '5678',
        ])->assertSessionHasErrors('error');
    }

    public function test_only_owner_or_general_manager_can_reset_someone_elses_pin(): void
    {
        // manage-users (UserController::resetPin()'s gate) is only ever
        // granted to Owner/General Manager — front_desk holding neither
        // proves the admin-reset half of the request was already true.
        $frontDesk = $this->userWithRole('front_desk', ['auth_type' => 'password_only']);
        $target = $this->pinUser('bar_staff', '1234');

        $this->actingAs($frontDesk)->post(route('admin.users.reset-pin', $target), [
            'pin' => '9999',
        ])->assertForbidden();

        $owner = $this->userWithRole('owner');
        $this->actingAs($owner)->post(route('admin.users.reset-pin', $target), [
            'pin' => '9999',
        ])->assertRedirect();
        $this->assertTrue(Hash::check('9999', $target->fresh()->pin_hash));
    }
}
