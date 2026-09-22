<?php

namespace App\Services\Admin;

use App\Exceptions\HmsException;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\Integrity\DataIntegrityCheckerService;
use App\Services\Sync\SyncableModels;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Seventh post-9.8 fix round, follow-up ("I NEED A BUTTON IN THE MASTER
 * ADMIN THAT CAN RESET ALL DATA AND THAT WHICH CAN EXTRACT THE DATA AND
 * THAT WICH CAN TRIGER BACKUP, YOU SHOULD NOT FORGET THIS IS A HYBRID
 * SYSTEM" — PHASE9_AUDIT_AND_PLAN.md §20.12). Three capabilities, all
 * gated behind the Owner-only `manage-system-data` permission:
 *
 *  - reset(): permanently clears every OPERATIONAL table (guests,
 *    reservations, payments, expenses, HR/inventory history, sync
 *    history, ...) while leaving every SETUP table untouched (staff
 *    logins, roles, departments, room list, financial accounts,
 *    settings). See OPERATIONAL_TABLES below for the exact, explicit
 *    list — deliberately an allowlist of what TO clear, not a denylist
 *    of what to keep, so a table this list doesn't yet know about is
 *    left alone by default (fails safe) rather than silently wiped.
 *  - backupJson(): a complete, restore-oriented dump of every real table
 *    (everything except pure framework/cache tables), one JSON document.
 *  - exportBusinessCsvZip(): a human-readable zip of CSVs covering the
 *    core business tables, meant to be opened in Excel/Sheets, not used
 *    for a technical restore.
 *
 * THE HYBRID-SYNC HAZARD (read this before touching reset() again): this
 * system can run as two independent, syncing installations
 * (HYBRID_ARCHITECTURE.md). Hard deletes never propagate through sync by
 * design (§3.1 of that document) — which sounds safe, but actually means
 * the OPPOSITE of what someone resetting data usually wants: if this
 * installation still has an active sync partner, the very next
 * `sync:run` will PULL the partner's copy of everything we just deleted
 * right back in via `applyUpsert()`'s "doesn't exist locally -> create
 * it" path, silently resurrecting the data over the following sync
 * cycle(s). reset() defends against this the only way that's actually
 * safe without a live two-node deployment to test against: it
 * deactivates every `SyncDevice` as part of the same transaction, which
 * (per that document's §6) immediately blocks that device from
 * authenticating in EITHER direction. This does not reset the partner
 * installation — that still has to be done separately, deliberately, on
 * that installation itself — it only stops this installation's wipe from
 * being silently undone by its own sync engine.
 */
class SystemDataService
{
    /**
     * Every table this action clears, explicitly. NOT included (and
     * therefore untouched by reset()): users, employee_profiles,
     * departments, room_categories, rooms (status is adjusted, not the
     * row), financial_accounts, facilities, facility_rates, products,
     * suppliers, units, recipe_items, menu_categories, menu_items,
     * pos_tables, leave_types, payroll_deduction_types,
     * payroll_deduction_brackets, settings, sync_devices (deactivated,
     * not deleted), audit_logs (kept as history — including the entry
     * this action itself writes), roles/permissions/spatie's pivot
     * tables, and every framework table (sessions, cache, jobs, ...).
     *
     * @var list<string>
     */
    public const OPERATIONAL_TABLES = [
        // Front office
        'guests', 'companies', 'reservations', 'stays', 'folios', 'folio_transactions',
        'housekeeping_tasks', 'maintenance_tickets',
        // Central payment ledger and everything that posts to it
        'payments', 'refund_requests', 'accounts_receivable',
        'cash_disbursements', 'disbursement_tickets',
        'cash_reconciliations', 'financial_account_reconciliations', 'accounting_days', 'shifts',
        // POS
        'orders', 'order_items',
        // Facilities (Gym/Pool) usage — not the facility/rate definitions themselves
        'facility_accesses',
        // Inventory movements — not the product/supplier catalog itself
        'purchase_orders', 'purchase_order_items', 'goods_receipts', 'goods_receipt_items',
        'stock_requisitions', 'stock_requisition_items', 'stock_counts', 'stock_count_items',
        'stock_wastages', 'stock_batches', 'stock_movements',
        // Finance
        'expenses',
        // HR history — not employee_profiles itself, which is staff setup
        'attendance_records', 'leave_requests', 'leave_balances',
        'payroll_runs', 'payslips', 'payslip_lines',
        // Sync history — sync_devices itself is deactivated below, not cleared
        'sync_batches', 'sync_items', 'sync_conflicts', 'sync_logs', 'sync_checkpoints',
    ];

    /**
     * Never included in a backup or export dump, regardless of table —
     * pure framework/cache/queue plumbing, not application or business
     * data. Restoring these from a backup would make no sense (a queued
     * job or cached value from a past moment is not meaningful later),
     * and a session/cache row could leak an unrelated active login.
     */
    private const NON_DATA_TABLES = [
        'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'migrations',
    ];

    /**
     * The core, human-meaningful business tables — what a non-technical
     * owner would actually recognize and want to read in a spreadsheet.
     * Deliberately a small, curated subset of OPERATIONAL_TABLES (plus a
     * few setup tables like rooms/financial_accounts for context), not
     * every table in the system — see backupJson() for the exhaustive
     * technical dump.
     */
    private const BUSINESS_EXPORT_TABLES = [
        'guests', 'companies', 'reservations', 'stays', 'folios', 'folio_transactions',
        'payments', 'refund_requests', 'accounts_receivable', 'expenses',
        'orders', 'order_items', 'rooms', 'financial_accounts',
    ];

    /**
     * Import (`importJsonBackup()`/`importBusinessCsvZip()` below,
     * Owner-only via `manage-system-data` — the same permission as
     * reset/backup/export): restores from a previous "Full backup"
     * download or a previous "Extract data" CSV zip. Deliberately the
     * mirror image of reset(), in both directions:
     *
     *  - Scope: import ONLY ever writes into OPERATIONAL_TABLES (or, for
     *    a CSV zip, whichever of BUSINESS_EXPORT_TABLES are also
     *    OPERATIONAL_TABLES — `rooms`/`financial_accounts` are exported
     *    "for context" but are SETUP tables and are never re-inserted by
     *    import, exactly as they are never cleared by reset). A JSON
     *    backup file also contains `users` and every other setup/config
     *    table (that's what makes it a full *technical* backup), but
     *    those keys are simply ignored here — never read, never
     *    inserted — the same fails-safe allowlist-not-denylist posture
     *    reset() itself documents. This is also what sidesteps the
     *    `users` table's stripped login-credential columns: since users
     *    is never restored by import, there's no login-credential gap to
     *    solve.
     *  - Guard: refuses unless every OPERATIONAL_TABLES table is
     *    currently empty (assertOperationalTablesAreEmpty()) — the safe
     *    default is "no merge, no silent overwrite", matching this
     *    project's standing "never silently rewrite/merge financial
     *    records" rule. In practice this means Import is for a freshly
     *    set-up installation, or immediately after using Reset above —
     *    never for layering historical data onto a hotel that's already
     *    live and operating.
     *  - Portability: because a restored row's foreign keys (e.g. a
     *    restored Stay's room_id, a restored Payment's
     *    financial_account_id) point at SETUP-table ids that import
     *    itself never touches, a restore is only guaranteed correct when
     *    those setup rows already exist with the SAME ids the backup was
     *    taken against — true by construction for "Backup -> Reset ->
     *    Import" on the very same installation (Reset never touches
     *    setup tables), but NOT guaranteed true when importing onto a
     *    different, independently-seeded installation. This is disclosed
     *    to the Owner in the UI rather than silently assumed, and every
     *    import runs the existing DataIntegrityCheckerService immediately
     *    afterward so any dangling reference is surfaced right away
     *    rather than lurking undetected.
     *  - Mechanism: like reset(), foreign-key checks are disabled for the
     *    duration of one all-or-nothing transaction, so 30+ operational
     *    tables can be restored without hand-maintaining a FK-dependency
     *    insert order — if any table's insert fails (e.g. a column this
     *    version of the schema requires didn't exist in an older
     *    backup), the whole transaction rolls back rather than leaving a
     *    partially-restored database.
     */
    private const IMPORTABLE_CSV_TABLES = [
        // Intersection of BUSINESS_EXPORT_TABLES and OPERATIONAL_TABLES —
        // 'rooms' and 'financial_accounts' are in the export for context
        // but are deliberately excluded here; see this class's docblock.
        'guests', 'companies', 'reservations', 'stays', 'folios', 'folio_transactions',
        'payments', 'refund_requests', 'accounts_receivable', 'expenses',
        'orders', 'order_items',
    ];

    public function __construct(
        private readonly AuditLogger $audit,
        private readonly DataIntegrityCheckerService $integrityChecker,
    ) {
    }

    /**
     * True only when every OPERATIONAL_TABLES table is currently empty —
     * the guard import() checks before writing anything.
     */
    public function operationalTablesAreEmpty(): bool
    {
        return array_sum($this->operationalRowCounts()) === 0;
    }

    /**
     * @return array<string, int> table => rows cleared
     */
    public function operationalRowCounts(): array
    {
        return collect(self::OPERATIONAL_TABLES)
            ->mapWithKeys(fn (string $table) => [$table => Schema::hasTable($table) ? (int) DB::table($table)->count() : 0])
            ->all();
    }

    public function hasActiveSyncDevices(): bool
    {
        return Schema::hasTable('sync_devices') && DB::table('sync_devices')->where('is_active', true)->exists();
    }

    /**
     * @return array<string, int> table => rows actually cleared
     */
    public function reset(User $actor): array
    {
        $summary = [];

        DB::transaction(function () use (&$summary) {
            Schema::disableForeignKeyConstraints();

            foreach (self::OPERATIONAL_TABLES as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                $summary[$table] = (int) DB::table($table)->count();
                DB::table($table)->delete();
            }

            // A room can only legitimately be 'occupied' because of a
            // stay that no longer exists after the delete above — flip
            // it back to available. 'maintenance'/'out_of_service'
            // describe the physical room, not a guest, so those are left
            // exactly as they were.
            if (Schema::hasTable('rooms')) {
                DB::table('rooms')->where('status', 'occupied')->update(['status' => 'available']);
            }

            // The hybrid-sync defense described in this class's own
            // docblock — deactivating, not deleting, so the device
            // registration/credential can simply be re-activated later
            // once the partner installation has also been reset.
            if (Schema::hasTable('sync_devices')) {
                DB::table('sync_devices')->update(['is_active' => false]);
            }

            Schema::enableForeignKeyConstraints();
        });

        $this->audit->log(
            'admin.system_data_reset',
            'admin',
            null,
            null,
            ['rows_cleared' => $summary, 'sync_devices_deactivated' => $this->hasActiveSyncDevices() === false],
            actor: $actor,
        );

        return $summary;
    }

    /**
     * Restores OPERATIONAL_TABLES data from a previous "Full backup" JSON
     * download (the exact format backupJson() produces). See this class's
     * docblock (above the constructor) for the full scope/safety
     * reasoning shared with importBusinessCsvZip() below.
     *
     * @return array{rows: array<string, int>, integrity: array}
     */
    public function importJsonBackup(UploadedFile $file, User $actor): array
    {
        $this->assertOperationalTablesAreEmpty();

        $decoded = json_decode((string) file_get_contents($file->getRealPath()), true, flags: JSON_THROW_ON_ERROR);

        if (! is_array($decoded) || ! is_array($decoded['tables'] ?? null)) {
            throw new HmsException(
                'This does not look like a valid HMS backup file (missing a "tables" section) — '
                .'nothing was imported. Use a file produced by this system\'s own "Download full backup" button.'
            );
        }

        $summary = [];

        DB::transaction(function () use ($decoded, &$summary) {
            Schema::disableForeignKeyConstraints();

            // Only OPERATIONAL_TABLES keys are ever read from the file —
            // 'users' and every other setup/config table the backup also
            // contains is deliberately never looked at, let alone
            // inserted. See this class's docblock.
            foreach (self::OPERATIONAL_TABLES as $table) {
                $rows = $decoded['tables'][$table] ?? [];

                if (! is_array($rows) || $rows === [] || ! Schema::hasTable($table)) {
                    $summary[$table] = 0;

                    continue;
                }

                $summary[$table] = $this->insertRowsRespectingSchema($table, $rows);
            }

            Schema::enableForeignKeyConstraints();
        });

        return $this->finishImport('backup_json', $summary, $actor);
    }

    /**
     * Restores OPERATIONAL_TABLES data from a previous "Extract data" CSV
     * zip (the exact format exportBusinessCsvZip() produces). Only the
     * tables in IMPORTABLE_CSV_TABLES are ever processed — 'rooms.csv'
     * and 'financial_accounts.csv', present in that same zip "for
     * context", are deliberately skipped even if present, since those are
     * SETUP tables import never touches. See this class's docblock.
     *
     * @return array{rows: array<string, int>, integrity: array}
     */
    public function importBusinessCsvZip(UploadedFile $file, User $actor): array
    {
        $this->assertOperationalTablesAreEmpty();

        if (! class_exists(\ZipArchive::class)) {
            throw new HmsException("This server's PHP does not have the zip extension enabled, so a CSV import can't be read.");
        }

        $zip = new \ZipArchive();
        if ($zip->open($file->getRealPath()) !== true) {
            throw new HmsException('That file could not be opened as a zip archive — nothing was imported.');
        }

        $summary = [];

        DB::transaction(function () use ($zip, &$summary) {
            Schema::disableForeignKeyConstraints();

            foreach (self::IMPORTABLE_CSV_TABLES as $table) {
                $contents = $zip->getFromName($table.'.csv');

                if ($contents === false || ! Schema::hasTable($table)) {
                    $summary[$table] = 0;

                    continue;
                }

                $summary[$table] = $this->insertRowsRespectingSchema($table, $this->parseCsv($contents));
            }

            Schema::enableForeignKeyConstraints();
        });

        $zip->close();

        return $this->finishImport('business_csv_zip', $summary, $actor);
    }

    private function assertOperationalTablesAreEmpty(): void
    {
        if (! $this->operationalTablesAreEmpty()) {
            throw new HmsException(
                'This system already has data in its guest/reservation/payment history tables — '
                .'Import is only for a freshly set-up installation or one that has just been Reset '
                .'(option 3 below). Nothing was imported. If you want to replace what\'s currently '
                .'here, take a backup, use Reset, then Import.'
            );
        }
    }

    /**
     * @param  array<int, array<string, mixed>>  $rows
     * @return int rows inserted
     */
    private function insertRowsRespectingSchema(string $table, array $rows): int
    {
        $validColumns = array_flip(Schema::getColumnListing($table));
        $inserted = 0;

        // Chunked, not one giant insert — a large hotel's history could
        // otherwise exceed a single query's placeholder limit.
        foreach (array_chunk($rows, 500) as $chunk) {
            $filtered = array_map(
                fn (array $row) => array_intersect_key($row, $validColumns),
                array_filter($chunk, fn ($row) => is_array($row)),
            );

            if ($filtered === []) {
                continue;
            }

            try {
                DB::table($table)->insert(array_values($filtered));
            } catch (\Throwable $e) {
                throw new HmsException(
                    "Could not import \"{$table}\" — the file's data didn't match this system's current ".
                    "table structure ({$e->getMessage()}). Nothing was imported (the whole restore was rolled back)."
                );
            }

            $inserted += count($filtered);
        }

        return $inserted;
    }

    /**
     * @return array<int, array<string, string>>
     */
    private function parseCsv(string $contents): array
    {
        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $contents);
        rewind($stream);

        $header = fgetcsv($stream);
        $rows = [];

        if ($header !== false) {
            while (($line = fgetcsv($stream)) !== false) {
                if (count($line) === count($header)) {
                    $rows[] = array_combine($header, $line);
                }
            }
        }

        fclose($stream);

        return $rows;
    }

    /**
     * Shared tail for both import methods: audits the restore, then
     * immediately runs the existing integrity checker so any dangling
     * cross-reference (see this class's docblock on portability) is
     * surfaced to the Owner right away rather than lurking silently.
     *
     * @param  array<string, int>  $summary
     * @return array{rows: array<string, int>, integrity: array}
     */
    private function finishImport(string $format, array $summary, User $actor): array
    {
        $this->audit->log(
            'admin.system_data_import',
            'admin',
            null,
            null,
            ['format' => $format, 'rows_imported' => $summary],
            actor: $actor,
        );

        $integrityResults = $this->integrityChecker->run();

        return [
            'rows' => $summary,
            'integrity' => [
                'passed' => ! $this->integrityChecker->hasFailures($integrityResults),
                'results' => $integrityResults,
            ],
        ];
    }

    /**
     * A complete, restore-oriented dump: every real application table
     * (everything except NON_DATA_TABLES), as one JSON document keyed by
     * table name. `users` has its own login-credential columns stripped
     * first via the exact same SyncableModels::PROTECTED_ATTRIBUTES list
     * the sync engine itself uses (HYBRID_ARCHITECTURE.md §6) — a full
     * backup still needs to be useful for restoring who exists and what
     * role/department they're in, but must not become a second place a
     * leaked file hands over every password hash, PIN hash, and TOTP
     * secret in the hotel.
     */
    public function backupJson(): string
    {
        $tables = collect(Schema::getTables())
            ->pluck('name')
            ->reject(fn (string $name) => in_array($name, self::NON_DATA_TABLES, true))
            ->values();

        $protectedUserColumns = SyncableModels::protectedAttributesFor(User::class);

        $dump = $tables->mapWithKeys(function (string $table) use ($protectedUserColumns) {
            $rows = DB::table($table)->get()->map(function ($row) use ($table, $protectedUserColumns) {
                $row = (array) $row;
                if ($table === 'users') {
                    foreach ($protectedUserColumns as $column) {
                        unset($row[$column]);
                    }
                }

                return $row;
            });

            return [$table => $rows];
        });

        return json_encode([
            'generated_at' => now()->toIso8601String(),
            'note' => 'Full technical backup. Staff login credentials (password/PIN/TOTP) are deliberately excluded from the users table — see SystemDataService::backupJson().',
            'tables' => $dump,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    }

    /**
     * A human-readable export of the core business tables as one CSV per
     * table, zipped together. Requires the `zip` PHP extension — most
     * hosts (including the shared-hosting target this project documents
     * in README §10) have it, but it is not guaranteed, so this throws a
     * clear, actionable HmsException instead of a raw fatal error when
     * it's missing, exactly like every other "the environment doesn't
     * support this" case elsewhere in this project.
     *
     * @return string absolute path to the generated zip file
     */
    public function exportBusinessCsvZip(): string
    {
        if (! class_exists(\ZipArchive::class)) {
            throw new HmsException(
                "This server's PHP does not have the zip extension enabled, so a CSV export can't be built. "
                ."Use \"Full backup\" instead, or ask your hosting provider to enable php-zip."
            );
        }

        $path = storage_path('app/hms-data-export-'.now()->format('Y-m-d-His').'.zip');

        $zip = new \ZipArchive();
        if ($zip->open($path, \ZipArchive::CREATE | \ZipArchive::OVERWRITE) !== true) {
            throw new HmsException('Could not create the export file on this server. Check that storage/app is writable.');
        }

        foreach (self::BUSINESS_EXPORT_TABLES as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            $rows = DB::table($table)->get();
            $csv = fopen('php://temp', 'w+');

            if ($rows->isNotEmpty()) {
                fputcsv($csv, array_keys((array) $rows->first()));
                foreach ($rows as $row) {
                    fputcsv($csv, (array) $row);
                }
            }

            rewind($csv);
            $zip->addFromString($table.'.csv', stream_get_contents($csv));
            fclose($csv);
        }

        $zip->close();

        return $path;
    }
}
