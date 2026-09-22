@extends('layouts.print')
@section('title', 'Payment Summary — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Payment Summary')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-48">Total in</td><td class="py-2 text-right text-green-700">{{ number_format($total_in, 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Total out</td><td class="py-2 text-right text-red-600">{{ number_format($total_out, 0) }}</td></tr>
        <tr class="border-t-2 border-slate-800 font-semibold text-base"><td class="py-2">Net</td><td class="py-2 text-right">{{ number_format($total_in - $total_out, 0) }}</td></tr>
    </tbody>
</table>

<table class="w-full text-sm mb-4">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
        <tr><th class="py-2">Date</th><th class="py-2">Payment #</th><th class="py-2">Method</th><th class="py-2">Guest</th><th class="py-2 text-right">Amount</th></tr>
    </thead>
    <tbody class="divide-y">
        @foreach ($payments as $p)
            <tr>
                <td class="py-2">{{ $p->recorded_at->format('d M Y H:i') }}</td>
                <td class="py-2">{{ $p->payment_number }}</td>
                <td class="py-2">{{ str_replace('_', ' ', $p->method) }}</td>
                <td class="py-2">{{ $p->guest->full_name ?? '—' }}</td>
                <td class="py-2 text-right">{{ number_format($p->amount, 0) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
