<?php

namespace App\Http\Controllers\Reporting;

use App\Http\Controllers\Controller;
use App\Models\Guest;
use App\Services\Reporting\GuestTransactionHistoryService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Feature 2 ("Complete guest transaction history report across all
 * stays"): its own permission ('view-guest-history', restricted to
 * Manager/Duty Manager/Accountant/Marketing Manager/Owner per spec — see
 * RolesAndPermissionsSeeder), not folded into the broader
 * 'view-operational-reports'/'manage-guests'/'manage-reservations' any
 * of those roles might separately hold. Route middleware in routes/web.php
 * enforces this for every action here (screen, print, and CSV export
 * alike), not just the index screen — server-enforced, not merely a
 * hidden nav link.
 */
class GuestTransactionHistoryController extends Controller
{
    public function __construct(private readonly GuestTransactionHistoryService $service)
    {
    }

    public function index(Request $request)
    {
        $query = $request->string('q')->toString() ?: null;
        $guests = $query ? $this->service->searchGuests($query) : collect();

        return view('reports.guest-transaction-history.index', compact('guests', 'query'));
    }

    public function show(Guest $guest)
    {
        $report = $this->service->report($guest);

        return view('reports.guest-transaction-history.show', $report);
    }

    public function print(Guest $guest)
    {
        $report = $this->service->report($guest);

        return view('reports.guest-transaction-history.print', $report);
    }

    public function csv(Guest $guest): StreamedResponse
    {
        $report = $this->service->report($guest);

        $filename = 'guest-transaction-history-'.$guest->id.'-'.now()->format('Ymd').'.csv';

        return response()->streamDownload(function () use ($report) {
            $handle = fopen('php://output', 'w');

            fputcsv($handle, ['Stay', 'Room', 'Check-in', 'Check-out', 'Date', 'Type', 'Description', 'Amount', 'Method', 'Account', 'Staff', 'Voided']);

            foreach ($report['stays'] as $stayReport) {
                $stay = $stayReport['stay'];

                foreach ($stayReport['transactions'] as $tx) {
                    fputcsv($handle, [
                        $stay->id,
                        $stay->room->room_number ?? '—',
                        optional($stay->check_in_at)->toDateString(),
                        optional($stay->actual_check_out_at)->toDateString(),
                        optional($tx['transaction_date'])->toDateTimeString(),
                        $tx['type'],
                        $tx['description'],
                        $tx['amount'],
                        $tx['payment_method'] ?? '',
                        $tx['financial_account']->name ?? '',
                        $tx['staff']->name ?? '',
                        $tx['is_voided'] ? 'Yes' : 'No',
                    ]);
                }
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv']);
    }
}
