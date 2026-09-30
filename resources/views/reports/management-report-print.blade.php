@extends('layouts.print')
@section('title', 'Management Report — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Management Report')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-56">Occupancy rate</td><td class="py-2 text-right">{{ $kpis['occupancy_rate'] }}%</td></tr>
        <tr><td class="py-2 text-slate-500">ADR (Average Daily Rate)</td><td class="py-2 text-right">{{ number_format($kpis['adr'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">RevPAR (Revenue Per Available Room)</td><td class="py-2 text-right">{{ number_format($kpis['revpar'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Net cash flow</td><td class="py-2 text-right">{{ number_format($kpis['net_cash_flow'], 0) }}</td></tr>
    </tbody>
</table>

<div class="font-medium mb-2">Revenue by charge type</div>
<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        @foreach ($revenue['by_type'] as $type => $amount)
            <tr><td class="py-1">{{ str_replace('_', ' ', ucfirst($type)) }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
        @endforeach
        <tr class="font-semibold border-t border-slate-800"><td class="py-1">Total</td><td class="py-1 text-right">{{ number_format($revenue['total'], 0) }}</td></tr>
    </tbody>
</table>

<div class="font-medium mb-2">Expenses by category</div>
<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        @forelse ($expenses['by_category'] as $category => $amount)
            <tr><td class="py-1">{{ $category }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="2">No expenses requested in this period.</td></tr>
        @endforelse
        <tr class="font-semibold border-t border-slate-800"><td class="py-1">Requested total</td><td class="py-1 text-right">{{ number_format($expenses['total_requested'], 0) }}</td></tr>
    </tbody>
</table>

<div class="font-medium mb-2">Accounts receivable aging (as of today)</div>
<table class="w-full text-sm">
    <tbody class="divide-y">
        @foreach ($receivables['buckets'] as $label => $bucket)
            <tr><td class="py-1">{{ $label }}</td><td class="py-1 text-right">{{ $bucket['count'] }}</td><td class="py-1 text-right">{{ number_format($bucket['amount'], 0) }}</td></tr>
        @endforeach
        <tr class="font-semibold border-t border-slate-800"><td class="py-1">Total outstanding</td><td></td><td class="py-1 text-right">{{ number_format($receivables['total_outstanding'], 0) }}</td></tr>
    </tbody>
</table>
@endsection
