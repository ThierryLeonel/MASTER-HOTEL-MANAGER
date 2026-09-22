<?php

namespace Tests\Feature\Concurrency;

use App\Models\FinancialAccount;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\Guest;
use App\Models\Payment;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Stay;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Real concurrency tests ("use REAL database concurrency tests with
 * separate connections (SQLite-only insufficient)").
 *
 * Why this class exists and why it looks nothing like an ordinary
 * Feature test: a single PHPUnit process holds exactly one Eloquent
 * connection, and PHP itself is single-threaded — calling a service
 * method "twice in a row" from one test can never prove a
 * lockForUpdate()/unique-constraint fix actually blocks a SECOND,
 * independent connection, because there never is a second connection.
 * The only way to genuinely exercise that is to have two separate OS
 * processes, each with its own DB connection, attempt the same real
 * action at (as close as the OS scheduler allows) the same instant —
 * which is exactly what tests/concurrency/run_scenario.php is for. This
 * class's job is only to: (1) build real, committed fixture rows (never
 * inside an uncommitted transaction — see below), (2) launch two sibling
 * `run_scenario.php` processes via proc_open with a shared target
 * start-time, (3) collect their JSON results, and (4) assert the safety
 * invariant that matters (exactly one winner / exactly one ledger row),
 * not a specific interleaving.
 *
 * Two deliberate departures from the rest of this suite:
 *
 *  - No `RefreshDatabase`. That trait wraps the whole test in one
 *    outer transaction and rolls it back at the end — which means a
 *    sibling process's own, completely separate connection would never
 *    see any fixture row this test creates in the first place (an
 *    uncommitted transaction is invisible outside the connection that
 *    opened it), regardless of any locking logic under test. Fixtures
 *    are created with plain, immediately-committing Eloquent calls and
 *    removed explicitly in tearDown() instead.
 *
 *  - A hard skip, not a fake pass, when the configured DB connection
 *    isn't a real shared-server database. `phpunit.xml` in this
 *    repository hardcodes `DB_CONNECTION=sqlite` / `DB_DATABASE=:memory:`
 *    — an in-memory SQLite database is scoped to the single connection
 *    that opened it, so a subprocess launched via proc_open could not
 *    see this test's fixtures even if it tried, and SQLite's coarse,
 *    whole-database locking is exactly the "SQLite-only insufficient"
 *    case the brief calls out by name. Rather than silently no-op or
 *    (worse) claim a pass that never tested anything, `setUp()` checks
 *    the ACTUAL runtime driver — not merely the phpunit.xml default,
 *    since a real CI/staging run might override it via `.env.testing` or
 *    environment variables to point at a real MySQL/PostgreSQL server —
 *    and calls `markTestSkipped()` with an explicit, honest reason when
 *    it isn't. This is the test class the final deliverables' "test
 *    results and limitations" writeup refers to when it says real
 *    concurrency coverage exists but could not itself be executed in a
 *    sandbox with no real database server.
 */
class RealDatabaseConcurrencyTest extends TestCase
{
    use CreatesUsers;

    /**
     * Seconds of head start given to both sibling processes before their
     * shared target instant, so "php run_scenario.php" has time to boot
     * a full Laravel application (autoload + service container) on a
     * loaded CI box before the synchronized action fires. Generous on
     * purpose — the busy-wait in run_scenario.php makes an overly long
     * head start harmless, whereas too short a one would let one process
     * simply finish booting late and lose the "race" on startup latency
     * alone rather than on the actual lock/constraint under test.
     */
    private const SYNCHRONIZED_START_OFFSET_SECONDS = 3.0;

    /** @var array<class-string, array<int>> IDs created by the running test, deleted in tearDown(). */
    private array $createdIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (! $this->realSharedDatabaseConnectionAvailable()) {
            $this->markTestSkipped(
                'Real database concurrency tests require a genuine shared-'
                .'server database connection (MySQL or PostgreSQL) that two '
                .'independent OS processes can each connect to and contend '
                .'on for a real row lock / unique-constraint violation. This '
                .'run is configured for '.$this->describeCurrentConnection().', '
                .'which cannot be shared across processes the way this suite '
                .'requires (an in-memory SQLite database, this project\'s '
                .'default test configuration, is scoped to a single '
                .'connection — see this class\'s docblock). Point '
                .'DB_CONNECTION at a real MySQL/PostgreSQL test database '
                .'(e.g. via .env.testing or CI environment variables, with '
                .'migrations already run against it) to execute this suite.'
            );
        }

        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
        $this->seed(\Database\Seeders\DepartmentSeeder::class);
        $this->seed(\Database\Seeders\FinancialAccountSeeder::class);
        $this->seed(\Database\Seeders\SettingsSeeder::class);
    }

    protected function tearDown(): void
    {
        // Nothing was created if setUp() skipped before reaching the
        // seed calls — guard so tearDown() is a no-op in that (normal,
        // in-sandbox) case rather than touching the DB at all.
        if ($this->createdIds !== []) {
            // Children before parents, to respect foreign keys.
            $this->deleteCreated(Payment::class);
            $this->deleteCreated(FolioTransaction::class);
            $this->deleteCreated(Folio::class);
            $this->deleteCreated(Stay::class);
            $this->deleteCreated(Reservation::class);
            $this->deleteCreated(Room::class);
            $this->deleteCreated(RoomCategory::class);
            $this->deleteCreated(Guest::class);
            $this->deleteCreated(User::class);
        }

        parent::tearDown();
    }

    /**
     * Audited defect #1: two processes both attempt to check the SAME
     * confirmed reservation into two DIFFERENT rooms at the same real
     * instant. Before the fix, the `confirmed` status was read once,
     * unlocked, before either process opened its transaction, so both
     * could pass it and both create a Stay — a genuine double-check-in
     * that a single-process test can never reproduce, since there is
     * only ever one read of one row. After the fix, the loser blocks on
     * `Reservation::lockForUpdate()` until the winner's transaction
     * commits, re-reads a status that is no longer 'confirmed', and
     * throws — so exactly one Stay must exist afterward, no matter which
     * process the OS happened to schedule first.
     */
    public function test_two_concurrent_check_ins_for_the_same_reservation_produce_exactly_one_stay(): void
    {
        $actor = $this->userWithRole('owner');
        $this->openRealShift('FO', $actor);

        $category = $this->track(RoomCategory::factory()->create(['base_rate' => 10000]));
        $roomA = $this->track(Room::factory()->create(['room_category_id' => $category->id, 'status' => 'available']));
        $roomB = $this->track(Room::factory()->create(['room_category_id' => $category->id, 'status' => 'available']));
        $guest = $this->track(Guest::factory()->create());
        $reservation = $this->track(Reservation::factory()->create([
            'guest_id' => $guest->id,
            'room_category_id' => $category->id,
            'room_id' => null,
            'rate_quoted' => 10000,
            'status' => 'confirmed',
            'requested_by' => $actor->id,
        ]));

        $startAt = microtime(true) + self::SYNCHRONIZED_START_OFFSET_SECONDS;

        $handleA = $this->launchScenario('checkin_race', [
            'reservation_id' => $reservation->id,
            'actor_id' => $actor->id,
            'room_id' => $roomA->id,
        ], $startAt);
        $handleB = $this->launchScenario('checkin_race', [
            'reservation_id' => $reservation->id,
            'actor_id' => $actor->id,
            'room_id' => $roomB->id,
        ], $startAt);

        $resultA = $this->collectResult($handleA, 'checkin_race');
        $resultB = $this->collectResult($handleB, 'checkin_race');

        $stays = Stay::where('reservation_id', $reservation->id)->get();
        foreach ($stays as $stay) {
            $this->track($stay);
        }

        $this->assertCount(
            1,
            $stays,
            'Exactly one Stay must exist for the reservation after two concurrent '
            .'check-in attempts — got: '.json_encode(['A' => $resultA, 'B' => $resultB])
        );

        $outcomes = collect([$resultA, $resultB])->pluck('outcome')->sort()->values()->all();
        $this->assertSame(
            ['error', 'success'],
            $outcomes,
            'One process should succeed and the other should cleanly fail '
            .'(never both succeed, never both fail) — got: '.json_encode(['A' => $resultA, 'B' => $resultB])
        );

        $loser = $resultA['outcome'] === 'error' ? $resultA : $resultB;
        $this->assertStringContainsString(
            'confirmed',
            $loser['message'],
            'The losing process should be rejected because the reservation is no '
            .'longer confirmed by the time it re-reads the locked row, not by some '
            .'unrelated error.'
        );

        // The room the loser tried to use must have been left untouched.
        $winnerRoomId = $resultA['outcome'] === 'success' ? $roomA->id : $roomB->id;
        $loserRoomId = $winnerRoomId === $roomA->id ? $roomB->id : $roomA->id;
        $this->assertSame('occupied', Room::find($winnerRoomId)->status);
        $this->assertSame('available', Room::find($loserRoomId)->status);
    }

    /**
     * Audited defect #32: two processes submit a deposit for the same
     * reservation carrying the IDENTICAL idempotency key at the same
     * real instant — simulating a genuine double-click or a network
     * retry that fires a second request before the first response ever
     * arrived. `ReservationService::recordDeposit()` /
     * `PaymentLedgerService::record()` both check "does a Payment with
     * this key already exist?" before writing — but that check and the
     * later insert are two separate statements, so two real, concurrent
     * connections can both pass the check and both reach the insert. The
     * `payments.idempotency_key` unique DB index is the actual backstop,
     * and PaymentLedgerService::record() now catches the resulting
     * unique-constraint violation and transparently returns the winner's
     * row (fixed alongside this test — previously an uncaught
     * QueryException would have surfaced as a raw error to whichever
     * request lost the race). Both processes must report success with
     * an IDENTICAL resulting deposit total, and exactly one Payment row
     * for that key must exist.
     */
    public function test_two_concurrent_deposits_with_the_same_idempotency_key_produce_exactly_one_payment(): void
    {
        $actor = $this->userWithRole('owner');
        $this->openRealShift('FO', $actor);
        $account = FinancialAccount::where('code', 'CASH')->firstOrFail();

        $category = $this->track(RoomCategory::factory()->create(['base_rate' => 10000]));
        $guest = $this->track(Guest::factory()->create());
        $reservation = $this->track(Reservation::factory()->create([
            'guest_id' => $guest->id,
            'room_category_id' => $category->id,
            'rate_quoted' => 10000,
            'status' => 'confirmed',
            'deposit_paid' => 0,
            'requested_by' => $actor->id,
        ]));

        $idempotencyKey = (string) Str::uuid();
        $startAt = microtime(true) + self::SYNCHRONIZED_START_OFFSET_SECONDS;

        $args = [
            'reservation_id' => $reservation->id,
            'actor_id' => $actor->id,
            'amount' => 5000,
            'financial_account_id' => $account->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $handleA = $this->launchScenario('idempotent_deposit_race', $args, $startAt);
        $handleB = $this->launchScenario('idempotent_deposit_race', $args, $startAt);

        $resultA = $this->collectResult($handleA, 'idempotent_deposit_race');
        $resultB = $this->collectResult($handleB, 'idempotent_deposit_race');

        $payments = Payment::where('idempotency_key', $idempotencyKey)->get();
        foreach ($payments as $payment) {
            $this->track($payment);
        }

        $this->assertSame(
            'success',
            $resultA['outcome'],
            'Process A should never surface a raw error for a retried/racing '
            .'idempotent request: '.json_encode($resultA)
        );
        $this->assertSame(
            'success',
            $resultB['outcome'],
            'Process B should never surface a raw error for a retried/racing '
            .'idempotent request: '.json_encode($resultB)
        );

        $this->assertCount(
            1,
            $payments,
            'Exactly one Payment must exist for the shared idempotency key after '
            .'two concurrent submissions — got: '.json_encode(['A' => $resultA, 'B' => $resultB])
        );

        $this->assertSame(
            $resultA['deposit_paid'],
            $resultB['deposit_paid'],
            'Both processes must observe the SAME final deposit_paid total — the '
            .'loser transparently gets the winner\'s already-recorded result, '
            .'never a second, additive application: '.json_encode(['A' => $resultA, 'B' => $resultB])
        );

        $this->assertSame('5000.00', (string) $reservation->fresh()->deposit_paid);
    }

    /**
     * Audited defect #32, second half — a genuine bug found WHILE
     * building this harness, not merely anticipated by it: two processes
     * post a folio payment carrying the SAME idempotency key at the same
     * real instant. Before the fix (see FolioService::postPayment()'s
     * docblock), the idempotency recheck ran before the folio's row lock
     * was taken, so both processes could pass it and both create their
     * own FolioTransaction — silently double-decrementing the real folio
     * balance for what should have been one payment, even though the
     * central `payments` ledger stayed correctly deduped to a single
     * row. This is exactly the kind of drift-between-ledgers a single-
     * process test cannot produce, since it can never hold two
     * connections open on the same folio at once. After the fix, the
     * loser blocks on the folio lock, then finds the winner's Payment
     * already committed and returns the winner's FolioTransaction
     * instead of creating a second one.
     */
    public function test_two_concurrent_folio_payments_with_the_same_idempotency_key_produce_exactly_one_folio_transaction(): void
    {
        $actor = $this->userWithRole('owner');
        $this->openRealShift('FO', $actor);
        $account = FinancialAccount::where('code', 'CASH')->firstOrFail();

        $category = $this->track(RoomCategory::factory()->create(['base_rate' => 10000]));
        $room = $this->track(Room::factory()->create(['room_category_id' => $category->id, 'status' => 'occupied']));
        $guest = $this->track(Guest::factory()->create());
        $stay = $this->track(Stay::factory()->create([
            'guest_id' => $guest->id,
            'room_id' => $room->id,
            'status' => 'active',
            'checked_in_by' => $actor->id,
        ]));
        $folio = $this->track(Folio::factory()->create(['stay_id' => $stay->id, 'status' => 'open', 'balance' => 30000]));

        $idempotencyKey = (string) Str::uuid();
        $startAt = microtime(true) + self::SYNCHRONIZED_START_OFFSET_SECONDS;

        $args = [
            'folio_id' => $folio->id,
            'actor_id' => $actor->id,
            'amount' => 10000,
            'financial_account_id' => $account->id,
            'idempotency_key' => $idempotencyKey,
        ];

        $handleA = $this->launchScenario('idempotent_folio_payment_race', $args, $startAt);
        $handleB = $this->launchScenario('idempotent_folio_payment_race', $args, $startAt);

        $resultA = $this->collectResult($handleA, 'idempotent_folio_payment_race');
        $resultB = $this->collectResult($handleB, 'idempotent_folio_payment_race');

        $payments = Payment::where('idempotency_key', $idempotencyKey)->get();
        foreach ($payments as $payment) {
            $this->track($payment);
        }

        $folioTransactions = FolioTransaction::where('folio_id', $folio->id)->where('type', 'payment')->get();
        foreach ($folioTransactions as $transaction) {
            $this->track($transaction);
        }

        $this->assertSame('success', $resultA['outcome'], 'Process A: '.json_encode($resultA));
        $this->assertSame('success', $resultB['outcome'], 'Process B: '.json_encode($resultB));

        $this->assertCount(
            1,
            $payments,
            'Exactly one central-ledger Payment must exist for the shared key — got: '
            .json_encode(['A' => $resultA, 'B' => $resultB])
        );

        $this->assertCount(
            1,
            $folioTransactions,
            'Exactly one FolioTransaction must exist for the shared key — a second, '
            .'genuinely duplicate one is precisely the bug this test was written to '
            .'catch — got: '.json_encode(['A' => $resultA, 'B' => $resultB])
        );

        $this->assertSame(
            $resultA['folio_transaction_id'],
            $resultB['folio_transaction_id'],
            'Both processes must be handed back the SAME FolioTransaction id.'
        );

        $this->assertSame(
            '20000.00',
            (string) $folio->fresh()->balance,
            'The folio balance must be decremented by the payment exactly ONCE '
            .'(30000 - 10000 = 20000) — a double-decrement (20000 - 10000 = 10000 '
            .'again) is the exact regression this test guards against.'
        );
    }

    private function realSharedDatabaseConnectionAvailable(): bool
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if (! in_array($driver, ['mysql', 'pgsql'], true)) {
                return false;
            }

            // A real round trip, not just a config read, proves this
            // connection is actually talking to a live server a sibling
            // OS process could also independently reach.
            $connection->select('select 1');

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    private function describeCurrentConnection(): string
    {
        try {
            return DB::connection()->getDriverName().' ('.config('database.default').')';
        } catch (\Throwable $e) {
            return 'an unreachable connection ('.$e->getMessage().')';
        }
    }

    /**
     * Opens a real, immediately-committed Shift — deliberately not via
     * this trait's own openShiftFor(), whose actor is thrown away, so
     * that a sibling process (which sees only committed rows) can rely
     * on the shift already being open by the time it runs.
     */
    private function openRealShift(string $departmentCode, User $actor): void
    {
        $department = \App\Models\Department::where('code', $departmentCode)->firstOrFail();
        app(\App\Services\Finance\ShiftService::class)->open($department, $actor);
    }

    private function track(mixed $model): mixed
    {
        $this->createdIds[get_class($model)][] = $model->getKey();

        return $model;
    }

    private function deleteCreated(string $class): void
    {
        $ids = $this->createdIds[$class] ?? [];

        if ($ids !== []) {
            $class::whereIn('id', $ids)->delete();
        }
    }

    /**
     * @param  array<string, mixed>  $args
     * @return array{process: resource, pipes: array}
     */
    private function launchScenario(string $scenario, array $args, float $startAt): array
    {
        $script = base_path('tests/concurrency/run_scenario.php');
        $command = [PHP_BINARY, $script, $scenario, json_encode($args, JSON_THROW_ON_ERROR), (string) $startAt];

        $process = proc_open($command, [
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ], $pipes, base_path());

        if (! is_resource($process)) {
            $this->fail("Could not launch concurrency scenario subprocess for '{$scenario}'.");
        }

        return ['process' => $process, 'pipes' => $pipes];
    }

    /**
     * @param  array{process: resource, pipes: array}  $handle
     * @return array<string, mixed>
     */
    private function collectResult(array $handle, string $scenario): array
    {
        $stdout = stream_get_contents($handle['pipes'][1]);
        $stderr = stream_get_contents($handle['pipes'][2]);
        fclose($handle['pipes'][1]);
        fclose($handle['pipes'][2]);
        $exitCode = proc_close($handle['process']);

        $decoded = json_decode((string) $stdout, true);

        if (! is_array($decoded)) {
            $this->fail(
                "Concurrency scenario '{$scenario}' produced no valid JSON (exit code {$exitCode}).\n"
                ."stdout: {$stdout}\nstderr: {$stderr}"
            );
        }

        return $decoded;
    }
}
