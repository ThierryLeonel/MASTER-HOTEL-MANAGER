@extends('layouts.print')
@section('title', 'Income Statement — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Income Statement')
@section('content')

<p class="text-xs text-slate-500 mb-2">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>
<p class="text-xs text-slate-500 mb-4">Management estimate — this system does not maintain a Chart of Accounts or General Ledger; not a formal double-entry statement.</p>

<table class="w-full text-sm">
    <tbody class="divide-y">
        <tr class="bg-slate-50"><td class="py-2 px-2 font-medium" colspan="2">Revenue</td></tr>
        @foreach ($statement['revenue_by_type'] as $type => $amount)
            <tr><td class="py-1 px-2 pl-6">{{ str_replace('_', ' ', ucfirst($type)) }}</td><td class="py-1 px-2 text-right">{{ number_format($amount, 0) }}</td></tr>
        @endforeach
        <tr class="font-semibold"><td class="py-1 px-2">Total revenue</td><td class="py-1 px-2 text-right">{{ number_format($statement['total_revenue'], 0) }}</td></tr>

        <tr class="bg-slate-50"><td class="py-2 px-2 font-medium" colspan="2">Expenses</td></tr>
        @forelse ($statement['expenses_by_category'] as $category => $amount)
            <tr><td class="py-1 px-2 pl-6">{{ $category }}</td><td class="py-1 px-2 text-right">-{{ number_format($amount, 0) }}</td></tr>
        @empty
            <tr><td class="py-1 px-2 pl-6 text-slate-500" colspan="2">No expenses requested in this period.</td></tr>
        @endforelse
        <tr class="font-semibold"><td class="py-1 px-2">Total expenses</td><td class="py-1 px-2 text-right">-{{ number_format($statement['total_expenses'], 0) }}</td></tr>

        <tr class="border-t-2 border-slate-800 text-base font-semibold">
            <td class="py-2 px-2">Net operating result</td>
            <td class="py-2 px-2 text-right">{{ number_format($statement['net_operating_result'], 0) }}</td>
        </tr>
    </tbody>
</table>
@endsection
