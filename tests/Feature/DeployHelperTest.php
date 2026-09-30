<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * DeployHelperController (Phase 8, README §10 step 5) is deliberately
 * gated by raw environment variables rather than the `settings` table,
 * since it must work before that table (or any table) exists — see the
 * controller's own docblock. Tests here manipulate real process env vars
 * with putenv()/tearDown() rather than the `settings` table for the same
 * reason, and restore them afterward so this doesn't leak into other
 * test files.
 *
 * What these tests CANNOT verify (documented honestly rather than
 * pretended away): this sandbox's test database is always already
 * migrated by RefreshDatabase before any test method runs, and
 * APP_ENV=testing already disables CSRF verification framework-wide
 * regardless of this controller's own routing — so there is no way, from
 * inside this test suite, to actually exercise "does this page still
 * work against a literally empty, unmigrated production database with
 * SESSION_DRIVER=database/CACHE_STORE=database," which is the entire
 * reason routes/deploy.php registers these routes outside the normal
 * `web` middleware group. That specific claim is NOT VERIFIED by
 * anything below and can only be confirmed by trying it against a real
 * fresh shared-hosting database.
 */
class DeployHelperTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'a-very-long-randomly-generated-test-token-1234567890';

    protected function tearDown(): void
    {
        putenv('ENABLE_DEPLOY_HELPER');
        putenv('DEPLOY_TOKEN');
        unset($_ENV['ENABLE_DEPLOY_HELPER'], $_ENV['DEPLOY_TOKEN']);

        parent::tearDown();
    }

    private function enable(string $token = self::TOKEN): void
    {
        putenv('ENABLE_DEPLOY_HELPER=true');
        $_ENV['ENABLE_DEPLOY_HELPER'] = 'true';
        putenv('DEPLOY_TOKEN='.$token);
        $_ENV['DEPLOY_TOKEN'] = $token;
    }

    public function test_it_is_a_404_when_the_feature_flag_is_off(): void
    {
        // ENABLE_DEPLOY_HELPER is left unset (its documented default).
        putenv('DEPLOY_TOKEN='.self::TOKEN);
        $_ENV['DEPLOY_TOKEN'] = self::TOKEN;

        $response = $this->get(route('deploy-helper.index', ['token' => self::TOKEN]));

        $response->assertNotFound();
    }

    public function test_it_is_a_404_with_the_wrong_token(): void
    {
        $this->enable();

        $response = $this->get(route('deploy-helper.index', ['token' => 'not-the-right-token']));

        $response->assertNotFound();
    }

    public function test_it_is_a_404_when_the_token_is_still_the_shipped_placeholder(): void
    {
        // Guards against a deployer who enabled the flag but forgot to
        // actually change DEPLOY_TOKEN away from the .env.example value.
        $this->enable('change-this-to-a-random-64-character-string');

        $response = $this->get(route('deploy-helper.index', ['token' => 'change-this-to-a-random-64-character-string']));

        $response->assertNotFound();
    }

    public function test_it_shows_the_status_page_with_the_correct_token(): void
    {
        $this->enable();

        $response = $this->get(route('deploy-helper.index', ['token' => self::TOKEN]));

        $response->assertOk();
        $response->assertSee('Database connection');
    }

    public function test_migrate_is_safe_to_call_and_reports_output(): void
    {
        $this->enable();

        // The test database is already fully migrated by RefreshDatabase
        // before this method runs, so this exercises migrate's idempotency
        // (it must report success/"nothing to migrate" rather than error)
        // rather than a true fresh-install run.
        $response = $this->post(route('deploy-helper.migrate'), ['token' => self::TOKEN]);

        $response->assertOk();
        $response->assertSee('Migration output');
    }

    public function test_seeding_is_refused_once_a_user_exists_unless_explicitly_forced(): void
    {
        $this->enable();
        User::factory()->create();

        $response = $this->post(route('deploy-helper.seed'), ['token' => self::TOKEN]);

        $response->assertOk();
        $response->assertSee('Seeding refused');
    }

    public function test_seeding_proceeds_when_explicitly_forced_even_with_an_existing_user(): void
    {
        $this->enable();
        User::factory()->create();

        $response = $this->post(route('deploy-helper.seed'), ['token' => self::TOKEN, 'force' => '1']);

        $response->assertOk();
        $response->assertSee('Seeding output');
    }

    /**
     * Seventh post-9.8 fix round (`PHASE9_AUDIT_AND_PLAN.md` §20.11): the
     * whole reason these two get their own always-available button is
     * that they must NOT be blocked the way the generic seed() above
     * correctly is once real users exist — this is the entire point of
     * the fix, so it's asserted explicitly rather than assumed.
     */
    public function test_seeding_roles_and_permissions_alone_is_never_refused_even_with_an_existing_user(): void
    {
        $this->enable();
        User::factory()->create();

        $response = $this->post(route('deploy-helper.seed-roles-and-permissions'), ['token' => self::TOKEN]);

        $response->assertOk();
        $response->assertSee('Roles & permissions updated');
        $this->assertTrue(\Spatie\Permission\Models\Role::where('name', 'accountant')->exists());
    }

    public function test_seeding_financial_accounts_alone_is_never_refused_even_with_an_existing_user(): void
    {
        $this->enable();
        User::factory()->create();

        $response = $this->post(route('deploy-helper.seed-financial-accounts'), ['token' => self::TOKEN]);

        $response->assertOk();
        $response->assertSee('Financial accounts set up');
        $this->assertDatabaseHas('financial_accounts', ['code' => 'CASH']);
        $this->assertDatabaseHas('financial_accounts', ['code' => 'BANK']);
        $this->assertDatabaseHas('financial_accounts', ['code' => 'MTN']);
        $this->assertDatabaseHas('financial_accounts', ['code' => 'ORANGE']);
    }

    /**
     * The idempotency guarantee the whole fix depends on: running it a
     * second time against accounts that already exist (one of them with
     * a real balance already recorded against it) must change nothing.
     */
    public function test_seeding_financial_accounts_twice_never_duplicates_or_resets_an_existing_account(): void
    {
        $this->enable();
        // No factory exists for FinancialAccount (see
        // tests/Unit/FinancialAccountModelTest.php's own note on this) —
        // created directly, exactly like FinancialAccountSeeder itself does.
        \App\Models\FinancialAccount::create(['code' => 'CASH', 'name' => 'Front Desk Drawer', 'type' => 'cash', 'opening_balance' => 50000, 'is_active' => true]);

        $this->post(route('deploy-helper.seed-financial-accounts'), ['token' => self::TOKEN]);

        $this->assertSame(1, \App\Models\FinancialAccount::where('code', 'CASH')->count());
        $this->assertSame('Front Desk Drawer', \App\Models\FinancialAccount::where('code', 'CASH')->first()->name);
        $this->assertSame('50000.00', \App\Models\FinancialAccount::where('code', 'CASH')->first()->opening_balance);
    }

    /**
     * Charge-types unification round: same idempotency/no-SSH reasoning
     * as the two seeders above — see DeployHelperController::
     * seedDepartments()'s docblock. Proves both halves of what an
     * already-live hotel needs this for: a department row added in a
     * later round than go-live, and a system charge type's department
     * backfilled.
     */
    public function test_seeding_departments_alone_is_never_refused_even_with_an_existing_user(): void
    {
        $this->enable();
        User::factory()->create();

        $response = $this->post(route('deploy-helper.seed-departments'), ['token' => self::TOKEN]);

        $response->assertOk();
        $response->assertSee('Departments updated');
        $this->assertDatabaseHas('departments', ['code' => 'BAROUT']);
        $this->assertDatabaseHas('departments', ['code' => 'BUCA']);
        $this->assertSame(
            \App\Models\Department::where('code', 'BAR')->value('id'),
            \App\Models\ChargeType::where('code', 'bar_charge')->value('department_id'),
        );
    }

    public function test_seeding_departments_never_overwrites_a_charge_types_department_already_set_by_an_admin(): void
    {
        $this->enable();
        // An admin deliberately reassigned bar_charge to a different
        // department from the Charge Types screen — re-running this
        // seeder must never silently revert that choice.
        $this->post(route('deploy-helper.seed'), ['token' => self::TOKEN]);
        $finance = \App\Models\Department::where('code', 'FIN')->first();
        \App\Models\ChargeType::where('code', 'bar_charge')->update(['department_id' => $finance->id]);

        $this->post(route('deploy-helper.seed-departments'), ['token' => self::TOKEN]);

        $this->assertSame($finance->id, \App\Models\ChargeType::where('code', 'bar_charge')->value('department_id'));
    }

    public function test_these_three_safe_seeders_are_still_a_404_without_the_flag_and_token(): void
    {
        $this->post(route('deploy-helper.seed-roles-and-permissions'), ['token' => 'wrong'])->assertNotFound();
        $this->post(route('deploy-helper.seed-financial-accounts'), ['token' => 'wrong'])->assertNotFound();
        $this->post(route('deploy-helper.seed-departments'), ['token' => 'wrong'])->assertNotFound();
    }

    public function test_clear_caches_runs_the_fixed_safe_command_list(): void
    {
        $this->enable();

        $response = $this->post(route('deploy-helper.clear-caches'), ['token' => self::TOKEN]);

        $response->assertOk();
        $response->assertSee('config:clear');
        $response->assertSee('cache:clear');
    }

    /**
     * New requirement 7 ("hotel logo fix + browser favicon") diagnosis —
     * see DeployHelperController::storageLink()'s own docblock. This
     * actually runs `artisan storage:link` against this sandbox's real
     * filesystem (Artisan commands are not scoped by RefreshDatabase,
     * which only resets the database) — the same kind of real,
     * documented side effect test_disable_reports_a_result_either_way()
     * below already accepts for its own .env rewrite. Cleaned up
     * afterward so it doesn't leave a symlink behind for other test runs.
     */
    public function test_storage_link_creates_the_symlink(): void
    {
        $this->enable();

        if (is_link(public_path('storage'))) {
            @unlink(public_path('storage'));
        }

        $response = $this->post(route('deploy-helper.storage-link'), ['token' => self::TOKEN]);

        $response->assertOk();
        $this->assertTrue(is_link(public_path('storage')));

        @unlink(public_path('storage'));
    }

    public function test_disable_reports_a_result_either_way(): void
    {
        $this->enable();

        $response = $this->post(route('deploy-helper.disable'), ['token' => self::TOKEN]);

        // Whether the .env rewrite actually succeeds depends on this
        // sandbox's file permissions, which is not the thing under test
        // here — either outcome must report clearly rather than silently
        // failing, which is what both branches guarantee.
        $response->assertOk();
        $response->assertSee('ENABLE_DEPLOY_HELPER');
    }

    public function test_integrity_check_runs_and_reports_pass_on_a_clean_database(): void
    {
        $this->enable();

        // RefreshDatabase has already migrated the schema by this point,
        // so this exercises the "tables exist" branch, not the "run
        // migrations first" skip branch covered separately below — see
        // this class's docblock for why the latter genuinely cannot be
        // simulated from inside this suite.
        $response = $this->get(route('deploy-helper.integrity-check', ['token' => self::TOKEN]));

        $response->assertOk();
        $response->assertSee('Data integrity check');
        $response->assertSee('PASS');
    }

    public function test_integrity_check_reports_a_real_issue_when_one_exists(): void
    {
        $this->enable();

        $folio = \App\Models\Folio::factory()->create();
        $creator = User::factory()->create();
        \App\Models\AccountsReceivable::create([
            'folio_id' => $folio->id,
            'guest_id' => $folio->stay->guest_id,
            'original_amount' => 10000,
            'amount_outstanding' => 11000,
            'status' => 'open',
            'due_date' => now()->addDays(30),
            'created_by' => $creator->id,
        ]);

        $response = $this->get(route('deploy-helper.integrity-check', ['token' => self::TOKEN]));

        $response->assertOk();
        $response->assertSee('ISSUE');
    }

    public function test_integrity_check_is_a_404_without_the_flag_and_token(): void
    {
        $response = $this->get(route('deploy-helper.integrity-check', ['token' => 'wrong']));

        $response->assertNotFound();
    }
}
