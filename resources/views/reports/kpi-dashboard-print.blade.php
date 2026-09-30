@extends('layouts.print')
@section('title', 'KPI Dashboard — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'KPI Dashboard')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-4">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500">Occupancy rate</td><td class="py-2 text-right">{{ $kpis['occupancy_rate'] }}%</td></tr>
        <tr><td class="py-2 text-slate-500">ADR (Average Daily Rate)</td><td class="py-2 text-right">{{ number_format($kpis['adr'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">RevPAR (Revenue Per Available Room)</td><td class="py-2 text-right">{{ number_format($kpis['revpar'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Revenue (accrual)</td><td class="py-2 text-right">{{ number_format($kpis['total_revenue'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Expenses paid</td><td class="py-2 text-right">{{ number_format($kpis['total_expenses_paid'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Cash in</td><td class="py-2 text-right">{{ number_format($kpis['cash_in'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Cash out</td><td class="py-2 text-right">{{ number_format($kpis['cash_out'], 0) }}</td></tr>
        <tr class="border-t-2 border-slate-800 font-semibold"><td class="py-2">Net cash flow</td><td class="py-2 text-right">{{ number_format($kpis['net_cash_flow'], 0) }}</td></tr>
    </tbody>
</table>

<p class="text-xs text-slate-500">"Revenue (accrual)" is what was charged to guests in this period; "cash in/out" is what actually moved through the payment ledger.</p>
@endsection
