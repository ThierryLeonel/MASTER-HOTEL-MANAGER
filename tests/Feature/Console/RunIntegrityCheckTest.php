<?php

namespace Tests\Feature\Console;

use App\Models\AccountsReceivable;
use App\Models\Folio;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Phase 9.6 (PHASE9_AUDIT_AND_PLAN.md §3.7). Confirms `php artisan
 * integrity:check` is wired to the real service (not a duplicated
 * calculation) and returns the exit code a cron job or CI step would
 * actually depend on.
 */
class RunIntegrityCheckTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_exits_successfully_and_reports_pass_on_a_clean_database(): void
    {
        $this->artisan('integrity:check')
            ->assertSuccessful()
            ->expectsOutputToContain('Data integrity check found no issues.');
    }

    public function test_it_exits_with_failure_and_reports_the_issue_when_something_is_wrong(): void
    {
        $folio = Folio::factory()->create();
        $creator = User::factory()->create();

        AccountsReceivable::create([
            'folio_id' => $folio->id,
            'guest_id' => $folio->stay->guest_id,
            'original_amount' => 10000,
            'amount_outstanding' => 11000,
            'status' => 'open',
            'due_date' => now()->addDays(30),
            'created_by' => $creator->id,
        ]);

        $this->artisan('integrity:check')
            ->assertFailed()
            ->expectsOutputToContain('Data integrity check found real issues')
            ->expectsOutputToContain('[FAIL]')
            ->expectsOutputToContain('issue(s)');
    }
}
