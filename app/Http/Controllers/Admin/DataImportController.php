<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Import\SpreadsheetImportService;
use Illuminate\Http\Request;

/**
 * "no it is suppose to be only in the Master admin" — bulk CSV import
 * lives entirely on the Master Admin — Data screen, gated on the same
 * Owner-only `manage-system-data` permission as Reset/Backup/Restore/
 * Export (`Admin\SystemDataController`), not scattered across each
 * resource's own screen with that resource's own permission the way an
 * earlier version of this feature did. See SpreadsheetImportService's own
 * docblock for the import mechanics themselves (preview-then-confirm,
 * why CSV, per-entity matching rules) — this controller is deliberately
 * thin, the same shape as SystemDataController: parse the request, call
 * the service, render the result.
 */
class DataImportController extends Controller
{
    private const VALID_OUTLETS = ['bar', 'restaurant', 'front_desk', 'bar_outside', 'bucareaux'];

    public function __construct(private readonly SpreadsheetImportService $importer)
    {
    }

    public function index()
    {
        return view('admin.system-data.import.index');
    }

    // -----------------------------------------------------------------
    // Guests
    // -----------------------------------------------------------------

    public function guestsForm()
    {
        return view('admin.system-data.import.guests', ['preview' => null]);
    }

    public function guestsTemplate()
    {
        return $this->csvDownload($this->importer->guestTemplateCsv(), 'guests-import-template.csv');
    }

    public function guestsPreview(Request $request)
    {
        $token = $this->storeUploadedFile($request);
        $preview = $this->importer->previewGuests($token);

        return view('admin.system-data.import.guests', compact('preview'));
    }

    public function guestsCommit(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $result = $this->importer->commitGuests($request->string('token')->toString(), $request->user());

        return redirect()->route('admin.system-data.import.index')->with(
            'status',
            "Guests import complete: {$result['create']} added, {$result['update']} updated, {$result['skip']} skipped, {$result['error']} had errors and were not imported."
        );
    }

    // -----------------------------------------------------------------
    // Reservations
    // -----------------------------------------------------------------

    public function reservationsForm()
    {
        return view('admin.system-data.import.reservations', ['preview' => null]);
    }

    public function reservationsTemplate()
    {
        return $this->csvDownload($this->importer->reservationTemplateCsv(), 'reservations-import-template.csv');
    }

    public function reservationsPreview(Request $request)
    {
        $token = $this->storeUploadedFile($request);
        $preview = $this->importer->previewReservations($token);

        return view('admin.system-data.import.reservations', compact('preview'));
    }

    public function reservationsCommit(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $result = $this->importer->commitReservations($request->string('token')->toString(), $request->user());

        return redirect()->route('admin.system-data.import.index')->with(
            'status',
            "Reservations import complete: {$result['create']} created (all pending approval, as usual), {$result['skip']} skipped as likely duplicates, {$result['error']} had errors and were not imported."
        );
    }

    // -----------------------------------------------------------------
    // Rooms
    // -----------------------------------------------------------------

    public function roomsForm()
    {
        return view('admin.system-data.import.rooms', ['preview' => null]);
    }

    public function roomsTemplate()
    {
        return $this->csvDownload($this->importer->roomTemplateCsv(), 'rooms-import-template.csv');
    }

    public function roomsPreview(Request $request)
    {
        $token = $this->storeUploadedFile($request);
        $preview = $this->importer->previewRooms($token);

        return view('admin.system-data.import.rooms', compact('preview'));
    }

    public function roomsCommit(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $result = $this->importer->commitRooms($request->string('token')->toString(), $request->user());

        return redirect()->route('admin.system-data.import.index')->with(
            'status',
            "Rooms import complete: {$result['create']} added, {$result['update']} updated, {$result['skip']} skipped, {$result['error']} had errors and were not imported."
        );
    }

    // -----------------------------------------------------------------
    // Menu (one outlet at a time — the outlet is a plain request value
    // here, not a route segment, since every import route on this page
    // is fixed under admin/system-data regardless of which outlet the
    // person is currently working with).
    // -----------------------------------------------------------------

    public function menuForm(Request $request)
    {
        $outlet = $this->validatedOutlet($request->query('outlet', 'bar'));

        return view('admin.system-data.import.menu', ['outlet' => $outlet, 'preview' => null]);
    }

    public function menuTemplate(Request $request)
    {
        $outlet = $this->validatedOutlet($request->query('outlet', 'bar'));

        return $this->csvDownload($this->importer->menuItemTemplateCsv($outlet), $outlet.'-menu-import-template.csv');
    }

    public function menuPreview(Request $request)
    {
        $outlet = $this->validatedOutlet($request->input('outlet'));
        $token = $this->storeUploadedFile($request);
        $preview = $this->importer->previewMenuItems($token, $outlet);

        return view('admin.system-data.import.menu', compact('outlet', 'preview'));
    }

    public function menuCommit(Request $request)
    {
        $request->validate(['token' => 'required|string']);
        $outlet = $this->validatedOutlet($request->input('outlet'));

        $result = $this->importer->commitMenuItems($request->string('token')->toString(), $outlet, $request->user());

        return redirect()->route('admin.system-data.import.index')->with(
            'status',
            ucfirst($outlet)." menu import complete: {$result['create']} items added, {$result['update']} updated, {$result['skip']} skipped, {$result['error']} had errors and were not imported."
        );
    }

    private function validatedOutlet(?string $outlet): string
    {
        $outlet = (string) $outlet;
        abort_unless(in_array($outlet, self::VALID_OUTLETS, true), 404);

        return $outlet;
    }

    // -----------------------------------------------------------------
    // Staff
    // -----------------------------------------------------------------

    public function staffForm()
    {
        return view('admin.system-data.import.staff', ['preview' => null, 'credentials' => null]);
    }

    public function staffTemplate()
    {
        return $this->csvDownload($this->importer->staffTemplateCsv(), 'staff-import-template.csv');
    }

    public function staffPreview(Request $request)
    {
        $token = $this->storeUploadedFile($request);
        $preview = $this->importer->previewStaff($token);

        return view('admin.system-data.import.staff', ['preview' => $preview, 'credentials' => null]);
    }

    public function staffCommit(Request $request)
    {
        $request->validate(['token' => 'required|string']);

        $result = $this->importer->commitStaff($request->string('token')->toString(), $request->user());

        // Rendered directly (not redirected) so the generated
        // credentials list survives exactly one page load and is never
        // put in a session flash or URL where it could linger.
        return view('admin.system-data.import.staff', [
            'preview' => null,
            'credentials' => $result,
        ]);
    }

    // -----------------------------------------------------------------

    private function storeUploadedFile(Request $request): string
    {
        $request->validate(['file' => 'required|file|mimes:csv,txt|max:5120']);

        return $this->importer->storeUpload($request->file('file'));
    }

    private function csvDownload(string $content, string $filename)
    {
        return response($content, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }
}
