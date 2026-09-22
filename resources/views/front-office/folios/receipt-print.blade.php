@extends('layouts.print')
@section('title', 'Payment Receipt — ' . $transaction->folio->stay->guest->full_name)
@section('document-title', 'Payment Receipt')
@section('content')

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-48">Guest</td><td class="py-2 font-medium">{{ $transaction->folio->stay->guest->full_name }}</td></tr>
        <tr><td class="py-2 text-slate-500">Room</td><td class="py-2">{{ $transaction->folio->stay->room->room_number }}</td></tr>
        <tr><td class="py-2 text-slate-500">Date</td><td class="py-2">{{ $transaction->transaction_date->format('d M Y H:i') }}</td></tr>
        <tr><td class="py-2 text-slate-500">Method</td><td class="py-2">{{ str_replace('_', ' ', ucfirst($transaction->payment_method ?? '—')) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Received by</td><td class="py-2">{{ $transaction->user->name }}</td></tr>
    </tbody>
</table>

<div class="rounded border border-slate-300 p-4 text-center">
    <div class="text-xs uppercase text-slate-500">Amount received</div>
    <div class="text-3xl font-semibold">{{ number_format(abs($transaction->amount), 0) }} XAF</div>
</div>

@if ($transaction->isVoided())
    <p class="mt-4 text-xs text-red-600 font-medium">This payment has been voided — this receipt no longer reflects the folio's current balance.</p>
@endif
@endsection
