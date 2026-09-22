<?php

namespace Tests\Feature\Console;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * The `hms:check-access` diagnostic (PHASE9_AUDIT_AND_PLAN.md §16.4),
 * added so the owner can self-diagnose the live "front desk sees no
 * room-status control" report without needing this sandbox to have
 * access to the production database.
 */
class CheckUserAccessTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    public function test_it_reports_success_for_a_front_desk_user_who_holds_the_permission(): void
    {
        $user = $this->userWithRole('front_desk', ['email' => 'frontdesk1@hms.test']);

        $this->artisan('hms:check-access', ['email' => $user->email, 'permission' => 'update-room-status'])
            ->assertExitCode(0);
    }

    public function test_it_fails_clearly_for_an_unknown_email(): void
    {
        $this->artisan('hms:check-access', ['email' => 'nobody@nowhere.test'])
            ->assertExitCode(1);
    }

    public function test_it_fails_for_a_role_that_does_not_hold_the_permission(): void
    {
        $user = $this->userWithRole('bar_staff', ['email' => 'bar1@hms.test']);

        $this->artisan('hms:check-access', ['email' => $user->email, 'permission' => 'update-room-status'])
            ->assertExitCode(1);
    }
}
