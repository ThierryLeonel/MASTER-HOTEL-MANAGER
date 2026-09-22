<?php

/**
 * Real concurrency test harness (task: "use REAL database concurrency
 * tests with separate connections — SQLite-only insufficient").
 *
 * This script is deliberately NOT run through PHPUnit's process — it is
 * launched as its own separate OS process (via proc_open, see
 * tests/Feature/Concurrency/RealDatabaseConcurrencyTest.php), boots a
 * completely independent Laravel application instance, and therefore
 * opens its own independent database connection. That is the entire
 * point: a single PHPUnit process — even one that calls a service method
 * "twice" in a loop — can never actually prove a row lock blocks a SECOND
 * connection, because there is no second connection; PHP is
 * single-threaded and a single Eloquent connection cannot contend with
 * itself. Two of these processes, launched at (as close as the OS
 * scheduler allows) the same instant, are genuinely separate MySQL
 * client connections that can genuinely block each other on a real
 * InnoDB row lock — which is exactly what every "lock + recheck inside
 * the transaction" fix in this correction round depends on actually
 * working under real contention, not just passing a sequential test.
 *
 * Usage: php run_scenario.php <scenario> <json-args> <start-at-microtime>
 *
 * <start-at-microtime> is a shared target wall-clock time (as a float
 * unix timestamp with microsecond precision) computed once by the parent
 * test and passed identically to both sibling processes; each process
 * busy-waits until that instant before performing its one real action,
 * so both are genuinely attempting it at the same moment rather than
 * racing however the OS happened to schedule process startup.
 *
 * Prints exactly one line of JSON to stdout: {"outcome": "...", ...}.
 */

require __DIR__.'/../../vendor/autoload.php';

/** @var \Illuminate\Foundation\Application $app */
$app = require __DIR__.'/../../bootstrap/app.php';

/** @var \Illuminate\Contracts\Console\Kernel $kernel */
$kernel = $app->make(\Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

[$_, $scenario, $jsonArgs, $startAt] = $argv;
$args = json_decode($jsonArgs, true, flags: JSON_THROW_ON_ERROR);
$startAt = (float) $startAt;

// Busy-wait (not sleep()'s coarse granularity) until the shared start
// instant, so both sibling processes fire as close together as possible.
while (microtime(true) < $startAt) {
    usleep(500);
}

function jsonOut(array $payload): never
{
    fwrite(STDOUT, json_encode($payload));
    exit(0);
}

try {
    match ($scenario) {
        'checkin_race' => runCheckinRace($args),
        'idempotent_deposit_race' => runIdempotentDepositRace($args),
        'idempotent_folio_payment_race' => runIdempotentFolioPaymentRace($args),
        default => jsonOut(['outcome' => 'error', 'message' => "Unknown scenario: {$scenario}"]),
    };
} catch (\Throwable $e) {
    jsonOut(['outcome' => 'error', 'message' => get_class($e).': '.$e->getMessage()]);
}

/**
 * Audited defect #1 ("serialize deposit-recording and check-in with
 * post-lock recheck") / the broader "prevent duplicate stays" fix in
 * CheckInService::checkInFromReservation(): two processes both attempt
 * to check the SAME confirmed reservation into two DIFFERENT rooms at
 * (as near as two OS processes can manage) the same instant. Before the
 * fix, the `confirmed` status check ran against an unlocked read and
 * both could pass it, creating two Stay rows for one reservation and
 * double-applying its deposit. After the fix, the loser blocks on
 * Reservation::lockForUpdate() until the winner's transaction commits,
 * then re-reads a status that is no longer 'confirmed' and throws.
 */
function runCheckinRace(array $args): never
{
    $reservation = \App\Models\Reservation::findOrFail($args['reservation_id']);
    $actor = \App\Models\User::findOrFail($args['actor_id']);
    $roomId = (int) $args['room_id'];

    $stay = app(\App\Services\FrontOffice\CheckInService::class)->checkInFromReservation($reservation, $actor, roomId: $roomId);

    jsonOut(['outcome' => 'success', 'stay_id' => $stay->id]);
}

/**
 * Audited defect #32 ("idempotency protection for retried payment/deposit
 * submissions"): two processes submit a deposit for the same reservation
 * with the IDENTICAL idempotency key at (as near as possible) the same
 * instant — simulating a real double-click or a network retry that fires
 * a second HTTP request before the first response ever came back, which
 * is precisely the scenario a single-process test cannot reproduce (the
 * whole point of an idempotency key is defending against a genuinely
 * concurrent duplicate, not merely a sequential one). Exactly one Payment
 * row for that key must exist afterward.
 */
function runIdempotentDepositRace(array $args): never
{
    $reservation = \App\Models\Reservation::findOrFail($args['reservation_id']);
    $actor = \App\Models\User::findOrFail($args['actor_id']);

    $result = app(\App\Services\FrontOffice\ReservationService::class)->recordDeposit(
        $reservation,
        (float) $args['amount'],
        'cash',
        $actor,
        financialAccountId: (int) $args['financial_account_id'],
        idempotencyKey: $args['idempotency_key'],
    );

    jsonOut(['outcome' => 'success', 'deposit_paid' => (string) $result->deposit_paid]);
}

/**
 * Audited defect #32, second half — the FolioService::postPayment() race
 * found while building this very harness (see that method's own
 * docblock): two processes post a folio payment with the IDENTICAL
 * idempotency key at the same real instant. Before the fix, the
 * idempotency recheck ran BEFORE the folio's row lock was acquired, so
 * both could pass it and both create their own, genuinely duplicate
 * FolioTransaction — double-decrementing the real folio balance — even
 * though the central `payments` ledger stayed correctly deduped to one
 * row. After the fix, the folio is locked before the recheck, so the
 * loser blocks, then finds the winner's Payment already committed and
 * returns the winner's FolioTransaction instead of creating a second
 * one. Asserts on the folio's resulting balance, which is the thing a
 * single-process test cannot exercise a genuine double-decrement of.
 */
function runIdempotentFolioPaymentRace(array $args): never
{
    $folio = \App\Models\Folio::findOrFail($args['folio_id']);
    $actor = \App\Models\User::findOrFail($args['actor_id']);

    $transaction = app(\App\Services\FrontOffice\FolioService::class)->postPayment(
        $folio,
        (float) $args['amount'],
        'cash',
        $actor,
        financialAccountId: (int) $args['financial_account_id'],
        idempotencyKey: $args['idempotency_key'],
    );

    jsonOut([
        'outcome' => 'success',
        'folio_transaction_id' => $transaction->id,
        'folio_balance' => (string) $folio->fresh()->balance,
    ]);
}
