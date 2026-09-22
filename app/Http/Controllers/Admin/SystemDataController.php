<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Admin\SystemDataService;
use Illuminate\Http\Request;

/**
 * Seventh post-9.8 fix round, follow-up (`PHASE9_AUDIT_AND_PLAN.md`
 * §20.12) — "Master Admin" data management: reset, backup, export. See
 * SystemDataService's own docblock for the full reasoning, especially
 * the hybrid-sync hazard section before changing reset().
 */
class SystemDataController extends Controller
{
    /**
     * Typed word-for-word into the reset form — deliberately not a
     * checkbox alone, so a reflexive/accidental click can't trigger the
     * single most destructive action in this system. Matches this
     * project's own "RESET ALL DATA" phrasing back to the person
     * confirming it.
     */
    private const CONFIRMATION_PHRASE = 'RESET ALL DATA';

    public function __construct(private readonly SystemDataService $service)
    {
    }

    public function index()
    {
        return view('admin.system-data.index', [
            'rowCounts' => $this->service->operationalRowCounts(),
            'hasActiveSyncDevices' => $this->service->hasActiveSyncDevices(),
            'confirmationPhrase' => self::CONFIRMATION_PHRASE,
            // Feature: Import — the button is only meaningfully usable
            // once the guest/reservation/payment history is empty (see
            // SystemDataService::importJsonBackup()'s docblock for why);
            // the view uses this to explain, rather than silently
            // disable, the import forms when it's false.
            'operationalTablesAreEmpty' => $this->service->operationalTablesAreEmpty(),
        ]);
    }

    public function reset(Request $request)
    {
        $request->validate([
            'confirmation' => 'required|in:'.self::CONFIRMATION_PHRASE,
            'has_backup' => 'accepted',
        ], [
            'confirmation.in' => 'Type "'.self::CONFIRMATION_PHRASE.'" exactly to confirm — nothing was cleared.',
            'has_backup.accepted' => 'Confirm you have downloaded a backup first — nothing was cleared.',
        ]);

        $summary = $this->service->reset($request->user());

        $totalRows = array_sum($summary);
        $tablesCleared = count(array_filter($summary));

        return redirect()->route('admin.system-data.index')->with(
            'status',
            "Done. {$totalRows} rows cleared across {$tablesCleared} tables. Staff logins, roles, rooms, and financial accounts were left untouched. Any sync devices were deactivated — see the note on this page before reactivating one."
        );
    }

    public function backup()
    {
        $json = $this->service->backupJson();
        $filename = 'hms-backup-'.now()->format('Y-m-d-His').'.json';

        return response($json, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    public function export()
    {
        $path = $this->service->exportBusinessCsvZip();
        $filename = 'hms-data-export-'.now()->format('Y-m-d-His').'.zip';

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    /**
     * Restores from a previous "Full backup" JSON download. See
     * SystemDataService::importJsonBackup()'s docblock for the full
     * scope/safety design — Owner-only (same `manage-system-data`
     * permission as the rest of this controller), and refused outright
     * unless the guest/reservation/payment history is currently empty.
     */
    public function importJson(Request $request)
    {
        $request->validate([
            'confirm_import' => 'accepted',
            'backup_file' => 'required|file|mimes:json,txt|max:51200',
        ], [
            'confirm_import.accepted' => 'Confirm you understand what Import does — nothing was imported.',
            'backup_file.max' => 'That file is larger than this server currently allows uploading (50MB). Ask your host to raise upload_max_filesize/post_max_size if a real backup is bigger than that.',
        ]);

        $result = $this->service->importJsonBackup($request->file('backup_file'), $request->user());

        return $this->importRedirect($result, 'full backup file');
    }

    /**
     * Restores from a previous "Extract data" CSV zip. Same guard and
     * same permission as importJson() above — see
     * SystemDataService::importBusinessCsvZip()'s docblock.
     */
    public function importCsv(Request $request)
    {
        $request->validate([
            'confirm_import' => 'accepted',
            'csv_zip' => 'required|file|mimes:zip|max:51200',
        ], [
            'confirm_import.accepted' => 'Confirm you understand what Import does — nothing was imported.',
            'csv_zip.max' => 'That file is larger than this server currently allows uploading (50MB). Ask your host to raise upload_max_filesize/post_max_size if a real export is bigger than that.',
        ]);

        $result = $this->service->importBusinessCsvZip($request->file('csv_zip'), $request->user());

        return $this->importRedirect($result, 'CSV export zip');
    }

    /**
     * @param  array{rows: array<string, int>, integrity: array}  $result
     */
    private function importRedirect(array $result, string $sourceDescription): \Illuminate\Http\RedirectResponse
    {
        $totalRows = array_sum($result['rows']);
        $tablesFilled = count(array_filter($result['rows']));

        $status = "Done. {$totalRows} rows imported from the {$sourceDescription} across {$tablesFilled} tables.";

        $status .= $result['integrity']['passed']
            ? ' A data-integrity check ran immediately afterward and found no issues.'
            : ' A data-integrity check ran immediately afterward and found issues — see below before relying on this data.';

        return redirect()->route('admin.system-data.index')
            ->with('status', $status)
            ->with('importIntegrityResults', $result['integrity']['passed'] ? null : $result['integrity']['results']);
    }
}
