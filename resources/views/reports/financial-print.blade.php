@extends('layouts.print')
@section('title', 'Financial Report — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Financial Report')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<p class="text-xs font-medium mb-1">Revenue by charge type (accrual)</p>
<table class="w-full text-sm mb-4">
    <tbody class="divide-y">
        @foreach ($revenue['by_type'] as $type => $amount)
            <tr><td class="py-1">{{ str_replace('_', ' ', ucfirst($type)) }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
        @endforeach
        <tr class="font-semibold border-t border-slate-300"><td class="py-1">Total</td><td class="py-1 text-right">{{ number_format($revenue['total'], 0) }}</td></tr>
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Cash flow</p>
<table class="w-full text-sm mb-4">
    <tbody class="divide-y">
        <tr><td class="py-1">Cash in</td><td class="py-1 text-right">{{ number_format($cashFlow['cash_in'], 0) }}</td></tr>
        @foreach ($cashFlow['cash_out'] as $module => $amount)
            <tr><td class="py-1 pl-4 text-slate-500">Out — {{ str_replace('_', ' ', $module) }}</td><td class="py-1 text-right">-{{ number_format($amount, 0) }}</td></tr>
        @endforeach
        <tr class="font-semibold border-t border-slate-300"><td class="py-1">Net</td><td class="py-1 text-right">{{ number_format($cashFlow['net'], 0) }}</td></tr>
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Expenses</p>
<table class="w-full text-sm mb-4">
    <tbody class="divide-y">
        @forelse ($expenses['by_category'] as $category => $amount)
            <tr><td class="py-1">{{ $category }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="2">No expenses requested in this period.</td></tr>
        @endforelse
        <tr class="font-semibold border-t border-slate-300"><td class="py-1">Requested total</td><td class="py-1 text-right">{{ number_format($expenses['total_requested'], 0) }}</td></tr>
        <tr><td class="py-1">Paid total</td><td class="py-1 text-right">{{ number_format($expenses['total_paid'], 0) }}</td></tr>
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Accounts receivable aging (as of today)</p>
<table class="w-full text-sm">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Age</th><th class="py-1 text-right">Count</th><th class="py-1 text-right">Amount</th></tr></thead>
    <tbody class="divide-y">
        @foreach ($receivables['buckets'] as $label => $bucket)
            <tr><td class="py-1">{{ $label }}</td><td class="py-1 text-right">{{ $bucket['count'] }}</td><td class="py-1 text-right">{{ number_format($bucket['amount'], 0) }}</td></tr>
        @endforeach
        <tr class="font-semibold border-t border-slate-300"><td class="py-1">Total outstanding</td><td></td><td class="py-1 text-right">{{ number_format($receivables['total_outstanding'], 0) }}</td></tr>
    </tbody>
</table>
@endsection
