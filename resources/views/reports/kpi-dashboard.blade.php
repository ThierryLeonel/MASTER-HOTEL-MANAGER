@extends('layouts.app')
@section('title', 'KPI Dashboard')
@section('content')
@include('reports._date-filter')

<div class="mb-4 text-xs">
    <a href="{{ route('reports.kpi-dashboard.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
</div>

<div class="grid grid-cols-4 gap-4">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Occupancy rate</div>
        <div class="text-2xl font-semibold">{{ $kpis['occupancy_rate'] }}%</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">ADR</div>
        <div class="text-2xl font-semibold">{{ number_format($kpis['adr'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">RevPAR</div>
        <div class="text-2xl font-semibold">{{ number_format($kpis['revpar'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Revenue (accrual)</div>
        <div class="text-2xl font-semibold">{{ number_format($kpis['total_revenue'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Expenses paid</div>
        <div class="text-2xl font-semibold">{{ number_format($kpis['total_expenses_paid'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Cash in</div>
        <div class="text-2xl font-semibold text-green-700">{{ number_format($kpis['cash_in'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Cash out</div>
        <div class="text-2xl font-semibold text-red-600">{{ number_format($kpis['cash_out'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Net cash flow</div>
        <div class="text-2xl font-semibold {{ $kpis['net_cash_flow'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($kpis['net_cash_flow'], 0) }}</div>
    </div>
</div>

<p class="text-xs text-slate-500 mt-4">"Revenue (accrual)" is what was charged to guests in this period (folio room/bar/restaurant/other charges); "cash in/out" is what actually moved through the payment ledger. The two will not match — a charge posted today may not be paid until later — see the Financial report for the full breakdown.</p>
@endsection
