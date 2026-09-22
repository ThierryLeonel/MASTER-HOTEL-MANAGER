@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
<div class="grid grid-cols-2 gap-4 md:grid-cols-4">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Occupancy</div>
        <div class="text-2xl font-semibold">{{ $occupancyRate }}%</div>
        <div class="text-xs text-slate-500">{{ $roomsOccupied }} / {{ $roomsTotal }} rooms</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Arrivals today</div>
        <div class="text-2xl font-semibold">{{ $arrivalsToday }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Departures today</div>
        <div class="text-2xl font-semibold">{{ $departuresToday }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Active stays</div>
        <div class="text-2xl font-semibold">{{ $activeStays }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Pending reservations</div>
        <div class="text-2xl font-semibold">{{ $pendingReservations }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Rooms in maintenance</div>
        <div class="text-2xl font-semibold">{{ $roomsMaintenance }}</div>
    </div>
    {{-- Phase 9.0 security fix: this financial total is hidden from any
         role without view-financial-reports/view-operational-reports
         (bar/restaurant staff, cashier, front_desk) — see
         DashboardController's docblock. --}}
    @if ($canViewFinancials)
        <div class="rounded-lg bg-white p-4 shadow-sm">
            <div class="text-xs uppercase text-slate-500">Open receivables</div>
            <div class="text-2xl font-semibold">{{ $openReceivables }}</div>
            <div class="text-xs text-slate-500">{{ number_format($openReceivablesTotal, 0) }} XAF outstanding</div>
        </div>
    @endif
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Pending housekeeping</div>
        <div class="text-2xl font-semibold">{{ $pendingHousekeeping }}</div>
    </div>
</div>

@if ($ticketsAwaitingApproval > 0 && $canReviewMaintenance)
    <div class="mt-6 rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
        {{ $ticketsAwaitingApproval }} maintenance ticket(s) are awaiting approval to take a room out of service.
        <a href="{{ route('maintenance.index') }}" class="underline">Review now</a>.
    </div>
@endif

{{-- Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): purely permission-driven —
     see PendingApprovalsService. Each user sees only the queues they
     personally hold an approve-* permission for, so this section is
     naturally empty (and hidden) for roles with no approval duties. --}}
@if (count($pendingApprovals) > 0)
    <div class="mt-6 rounded-lg bg-white p-4 shadow-sm">
        <div class="font-medium mb-2">Pending approvals</div>
        <ul class="divide-y text-sm">
            @foreach ($pendingApprovals as $item)
                <li class="flex items-center justify-between py-2">
                    <span>{{ $item['label'] }}</span>
                    <a href="{{ route($item['route']) }}" class="rounded-full {{ $item['count'] > 0 ? 'bg-amber-100 text-amber-800' : 'bg-slate-100 text-slate-500' }} px-2.5 py-0.5 text-xs font-medium hover:underline">
                        {{ $item['count'] }}
                    </a>
                </li>
            @endforeach
        </ul>
    </div>
@endif

{{-- Phase 9.4: trailing-7-day financial summary, reusing
     FinancialReportService::incomeStatement() — see DashboardController's
     docblock. Gated on view-financial-reports specifically (not the
     broader $canViewFinancials used for receivables above). --}}
@if ($canViewFinancialReports)
    <div class="mt-6 rounded-lg bg-white p-4 shadow-sm">
        <div class="flex items-center justify-between mb-2">
            <div class="font-medium">Last 7 days</div>
            <a href="{{ route('reports.weekly-owner-summary') }}" class="text-xs text-slate-500 underline">Full weekly summary</a>
        </div>
        <div class="grid grid-cols-1 md:grid-cols-3 gap-4">
            <div>
                <div class="text-xs uppercase text-slate-500">Revenue</div>
                <div class="text-xl font-semibold">{{ number_format($weeklyFinancialSummary['total_revenue'], 0) }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-slate-500">Expenses</div>
                <div class="text-xl font-semibold">{{ number_format($weeklyFinancialSummary['total_expenses'], 0) }}</div>
            </div>
            <div>
                <div class="text-xs uppercase text-slate-500">Net result</div>
                <div class="text-xl font-semibold {{ $weeklyFinancialSummary['net_operating_result'] >= 0 ? 'text-green-700' : 'text-red-600' }}">
                    {{ number_format($weeklyFinancialSummary['net_operating_result'], 0) }}
                </div>
            </div>
        </div>
    </div>
@endif
@endsection
