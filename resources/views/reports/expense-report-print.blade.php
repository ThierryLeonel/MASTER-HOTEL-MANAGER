@extends('layouts.print')
@section('title', 'Expense Report — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Expense Report')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr class="font-semibold"><td class="py-2">Requested total</td><td class="py-2 text-right">{{ number_format($summary['total_requested'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Paid total</td><td class="py-2 text-right">{{ number_format($summary['total_paid'], 0) }}</td></tr>
    </tbody>
</table>

<table class="w-full text-sm mb-4">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
        <tr><th class="py-2">Expense #</th><th class="py-2">Date</th><th class="py-2">Category</th><th class="py-2">Status</th><th class="py-2 text-right">Amount</th></tr>
    </thead>
    <tbody class="divide-y">
        @foreach ($expenses as $e)
            <tr>
                <td class="py-2">{{ $e->expense_number }}</td>
                <td class="py-2">{{ $e->created_at->format('d M Y') }}</td>
                <td class="py-2">{{ $e->category }}</td>
                <td class="py-2">{{ $e->status }}</td>
                <td class="py-2 text-right">{{ number_format($e->amount, 0) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
