<?php

namespace App\Services\Reporting;

use App\Models\FolioTransaction;
use App\Models\Room;
use App\Models\Stay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Occupancy/ADR/RevPAR and arrivals/departures — the standard
 * hospitality operations metrics, computed live from `stays` and
 * `folio_transactions` rather than a nightly batch job maintaining a
 * separate occupancy table (spec section 24: no duplicated totals).
 */
class OperationsReportService
{
    /**
     * @return array{
     *   total_rooms: int, days: int, room_nights_available: int,
     *   room_nights_occupied: int, occupancy_rate: float,
     *   adr: float, revpar: float, daily: array<int, array{date: string, occupied: int}>
     * }
     */
    public function occupancy(Carbon $start, Carbon $end): array
    {
        $totalRooms = Room::where('is_active', true)->count();
        $daily = [];
        $roomNightsOccupied = 0;

        for ($date = $start->copy()->startOfDay(); $date->lte($end); $date->addDay()) {
            $occupied = Stay::where('check_in_at', '<=', $date->copy()->endOfDay())
                ->where(function ($q) use ($date) {
                    $q->whereNull('actual_check_out_at')
                        ->orWhere('actual_check_out_at', '>', $date->copy()->startOfDay());
                })
                ->count();

            $daily[] = ['date' => $date->toDateString(), 'occupied' => $occupied];
            $roomNightsOccupied += $occupied;
        }

        $days = count($daily);
        $roomNightsAvailable = $totalRooms * $days;
        $occupancyRate = $roomNightsAvailable > 0 ? round($roomNightsOccupied / $roomNightsAvailable * 100, 1) : 0.0;

        $roomRevenue = (float) FolioTransaction::where('type', 'room_charge')
            ->whereNull('voided_at')
            ->whereBetween('transaction_date', [$start->copy()->startOfDay(), $end->copy()->endOfDay()])
            ->sum('amount');

        $adr = $roomNightsOccupied > 0 ? round($roomRevenue / $roomNightsOccupied, 2) : 0.0;
        $revpar = $roomNightsAvailable > 0 ? round($roomRevenue / $roomNightsAvailable, 2) : 0.0;

        return [
            'total_rooms' => $totalRooms,
            'days' => $days,
            'room_nights_available' => $roomNightsAvailable,
            'room_nights_occupied' => $roomNightsOccupied,
            'occupancy_rate' => $occupancyRate,
            'adr' => $adr,
            'revpar' => $revpar,
            'daily' => $daily,
        ];
    }

    /**
     * @return array{arrivals: \Illuminate\Support\Collection, departures: \Illuminate\Support\Collection}
     */
    public function arrivalsAndDepartures(Carbon $start, Carbon $end): array
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        $arrivals = Stay::with(['guest', 'room'])->whereBetween('check_in_at', $range)->orderBy('check_in_at')->get();
        $departures = Stay::with(['guest', 'room'])->whereBetween('actual_check_out_at', $range)->orderBy('actual_check_out_at')->get();

        return ['arrivals' => $arrivals, 'departures' => $departures];
    }

    /**
     * Phase 9.3 (PHASE9_AUDIT_AND_PLAN.md §3.4): completed (status=closed)
     * stays that checked out within the range, with the per-stay charge/
     * payment totals a front desk or management user would want when
     * looking up a past guest's history — previously the only stay list
     * in the system was the *active* stays screen (front-office/stays),
     * with nothing for stays that have already checked out.
     *
     * @return Collection<int, array{stay: Stay, nights: int, total_charged: float, total_paid: float, balance: float, sent_to_receivables: bool}>
     */
    public function guestStayHistory(Carbon $start, Carbon $end): Collection
    {
        $range = [$start->copy()->startOfDay(), $end->copy()->endOfDay()];

        return Stay::with(['guest', 'room.category', 'folio.accountsReceivable'])
            ->where('status', 'closed')
            ->whereBetween('actual_check_out_at', $range)
            ->orderByDesc('actual_check_out_at')
            ->get()
            ->map(function (Stay $stay) {
                $folio = $stay->folio;
                $transactions = $folio ? $folio->transactions()->whereNull('voided_at')->get() : collect();

                $totalCharged = (float) $transactions->whereIn('type', FolioTransaction::CHARGE_TYPES)->sum('amount');
                $totalPaid = (float) $transactions->where('type', 'payment')->sum(fn ($tx) => abs((float) $tx->amount));

                return [
                    'stay' => $stay,
                    'nights' => max(1, $stay->check_in_at->diffInDays($stay->actual_check_out_at)),
                    'total_charged' => $totalCharged,
                    'total_paid' => $totalPaid,
                    'balance' => $folio ? (float) $folio->balance : 0.0,
                    'sent_to_receivables' => $folio?->accountsReceivable !== null,
                ];
            });
    }
}
