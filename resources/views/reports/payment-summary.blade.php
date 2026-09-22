@extends('layouts.app')
@section('title', 'Payment Summary')
@section('content')
@include('reports._date-filter')

<div class="mb-4 flex gap-3 text-xs">
    <a href="{{ route('reports.payment-summary.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
    <a href="{{ route('reports.payment-summary.csv', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-200 px-3 py-1.5 hover:bg-slate-300">Download CSV</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-4 mb-4">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Total in</div>
        <div class="text-2xl font-semibold text-green-700">{{ number_format($total_in, 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Total out</div>
        <div class="text-2xl font-semibold text-red-600">{{ number_format($total_out, 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Net</div>
        <div class="text-2xl font-semibold">{{ number_format($total_in - $total_out, 0) }}</div>
    </div>
</div>

<div class="rounded-lg bg-white p-4 shadow-sm mb-4">
    <div class="font-medium mb-2">By method</div>
    <table class="w-full text-sm">
        <tbody class="divide-y">
            @foreach ($by_method as $method => $amount)
                <tr><td class="py-1">{{ str_replace('_', ' ', ucfirst($method)) }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
            @endforeach
        </tbody>
    </table>
</div>

<div class="rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Date</th><th class="p-3">Payment #</th><th class="p-3">Direction</th><th class="p-3">Method</th><th class="p-3">Source</th><th class="p-3">Guest</th><th class="p-3">Recorded by</th><th class="p-3 text-right">Amount</th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($payments as $p)
                <tr>
                    <td class="p-3">{{ $p->recorded_at->format('d M H:i') }}</td>
                    <td class="p-3">{{ $p->payment_number }}</td>
                    <td class="p-3">{{ $p->direction }}</td>
                    <td class="p-3">{{ str_replace('_', ' ', $p->method) }}</td>
                    <td class="p-3">{{ str_replace('_', ' ', $p->source_module) }}</td>
                    <td class="p-3">{{ $p->guest->full_name ?? '—' }}</td>
                    <td class="p-3">{{ $p->recordedBy->name ?? '—' }}</td>
                    <td class="p-3 text-right">{{ number_format($p->amount, 0) }}</td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="8">No payments in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
