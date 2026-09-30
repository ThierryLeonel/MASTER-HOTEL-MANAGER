@extends('layouts.app')
@section('title', 'Management Report')
@section('content')
@include('reports._date-filter')

<div class="mb-4 flex gap-3 text-xs">
    <a href="{{ route('reports.management-report.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
</div>

<div class="grid grid-cols-4 gap-4 mb-6">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Occupancy rate</div>
        <div class="text-2xl font-semibold">{{ $kpis['occupancy_rate'] }}%</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500" title="Average Daily Rate — total room revenue divided by rooms actually sold">ADR</div>
        <div class="text-2xl font-semibold">{{ number_format($kpis['adr'], 0) }}</div>
        <div class="text-xs text-slate-500">Average Daily Rate</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500" title="Revenue Per Available Room — total room revenue divided by every room available, occupied or not">RevPAR</div>
        <div class="text-2xl font-semibold">{{ number_format($kpis['revpar'], 0) }}</div>
        <div class="text-xs text-slate-500">Revenue Per Available Room</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Net cash flow</div>
        <div class="text-2xl font-semibold {{ $kpis['net_cash_flow'] < 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($kpis['net_cash_flow'], 0) }}</div>
    </div>
</div>

<div class="grid grid-cols-2 gap-4">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="font-medium mb-2">Revenue by charge type</div>
        <table class="w-full text-sm">
            <tbody class="divide-y">
                @foreach ($revenue['by_type'] as $type => $amount)
                    <tr><td class="py-1">{{ str_replace('_', ' ', ucfirst($type)) }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
                @endforeach
                <tr class="font-semibold"><td class="py-1">Total</td><td class="py-1 text-right">{{ number_format($revenue['total'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="font-medium mb-2">Expenses by category</div>
        <table class="w-full text-sm">
            <tbody class="divide-y">
                @forelse ($expenses['by_category'] as $category => $amount)
                    <tr><td class="py-1">{{ $category }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
                @empty
                    <tr><td class="py-1 text-slate-500" colspan="2">No expenses requested in this period.</td></tr>
                @endforelse
                <tr class="font-semibold"><td class="py-1">Requested total</td><td class="py-1 text-right">{{ number_format($expenses['total_requested'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm col-span-2">
        <div class="font-medium mb-2">Accounts receivable aging (as of today)</div>
        <table class="w-full text-sm">
            <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Age</th><th class="py-1 text-right">Count</th><th class="py-1 text-right">Amount</th></tr></thead>
            <tbody class="divide-y">
                @foreach ($receivables['buckets'] as $label => $bucket)
                    <tr><td class="py-1">{{ $label }}</td><td class="py-1 text-right">{{ $bucket['count'] }}</td><td class="py-1 text-right">{{ number_format($bucket['amount'], 0) }}</td></tr>
                @endforeach
                <tr class="font-semibold"><td class="py-1">Total outstanding</td><td></td><td class="py-1 text-right">{{ number_format($receivables['total_outstanding'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>
</div>
@endsection
