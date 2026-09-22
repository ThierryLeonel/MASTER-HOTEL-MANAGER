<?php

namespace App\Console\Commands;

use App\Models\Reservation;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;

/**
 * Correction round (audited defect #33): before this delivery, every
 * website-submitted deposit-proof file (a guest's mobile-money screenshot,
 * often carrying their phone number and transaction details) was stored on
 * the PUBLIC disk (storage/app/public/deposit-proofs) and served from a
 * predictable, unauthenticated URL. New uploads go straight to the private
 * 'local' disk now (see WebsiteBookingController::store()), but any file
 * uploaded by a guest BEFORE this fix is still sitting on the public disk
 * and Reservation.deposit_proof_path still points at it — this command is
 * the one-time, idempotent remediation for that existing data.
 *
 * Deliberately does not touch or delete anything by guessing; it only ever
 * acts on a reservation whose recorded deposit_proof_path it can find on
 * the public disk right now. Running it more than once is safe: a
 * reservation whose file has already been moved (and whose path already
 * points at the private disk) is silently skipped on the next run, since
 * Storage::disk('public')->exists() will be false for it.
 *
 * This must be run once, manually, as part of upgrading an installation
 * that predates this fix (see the delivery's migration/upgrade
 * instructions) — it is never run automatically by a migration, per the
 * explicit instruction not to silently rewrite/move existing data without
 * an operator's deliberate action.
 */
class MigrateDepositProofsToPrivateDisk extends Command
{
    protected $signature = 'reservations:migrate-deposit-proofs-to-private {--dry-run : List what would be moved without moving anything}';

    protected $description = 'One-time remediation: move any pre-existing website deposit-proof files off the public disk onto the private disk, and repoint their reservations.';

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $moved = 0;
        $missing = 0;
        $alreadyPrivate = 0;

        Reservation::query()
            ->whereNotNull('deposit_proof_path')
            ->orderBy('id')
            ->chunkById(200, function ($reservations) use (&$moved, &$missing, &$alreadyPrivate, $dryRun) {
                foreach ($reservations as $reservation) {
                    $path = $reservation->deposit_proof_path;

                    if (! Storage::disk('public')->exists($path)) {
                        // Either already migrated (now only on 'local'), or
                        // genuinely missing — either way there is nothing on
                        // the public disk left to remediate for this row.
                        if (Storage::disk('local')->exists($path)) {
                            $alreadyPrivate++;
                        } else {
                            $missing++;
                            $this->warn("  Reservation {$reservation->reservation_number}: path '{$path}' not found on either disk — leaving untouched.");
                        }

                        return;
                    }

                    $this->line("  Reservation {$reservation->reservation_number}: {$path} (public -> local)");

                    if ($dryRun) {
                        $moved++;

                        return;
                    }

                    $contents = Storage::disk('public')->get($path);
                    Storage::disk('local')->put($path, $contents);

                    if (! Storage::disk('local')->exists($path)) {
                        $this->error("  Reservation {$reservation->reservation_number}: write to private disk failed — leaving the public copy in place, not deleting anything.");

                        return;
                    }

                    // Only delete the public copy once the private copy is
                    // confirmed written — never delete-then-write.
                    Storage::disk('public')->delete($path);
                    $moved++;
                }
            });

        $verb = $dryRun ? 'Would move' : 'Moved';
        $this->info("{$verb} {$moved} file(s) from the public disk to the private disk. {$alreadyPrivate} already remediated. {$missing} referenced a path found on neither disk.");

        return self::SUCCESS;
    }
}
