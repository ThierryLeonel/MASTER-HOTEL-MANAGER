@extends('layouts.print')
@section('title', 'Reservation Confirmation — ' . $reservation->reservation_number)
@section('document-title', 'Reservation Confirmation')
@section('content')

<div class="mb-6">
    <div class="text-xs uppercase text-slate-500">Reservation number</div>
    <div class="text-xl font-semibold">{{ $reservation->reservation_number }}</div>
</div>

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-48">Guest</td><td class="py-2 font-medium">{{ $reservation->guest->full_name }}</td></tr>
        <tr><td class="py-2 text-slate-500">Room category</td><td class="py-2">{{ $reservation->roomCategory->name }}</td></tr>
        <tr><td class="py-2 text-slate-500">Room</td><td class="py-2">{{ $reservation->room->room_number ?? 'Not yet assigned' }}</td></tr>
        <tr><td class="py-2 text-slate-500">Check-in</td><td class="py-2">{{ $reservation->check_in_date->format('d M Y') }}</td></tr>
        <tr><td class="py-2 text-slate-500">Check-out</td><td class="py-2">{{ $reservation->check_out_date->format('d M Y') }}</td></tr>
        <tr><td class="py-2 text-slate-500">Nights</td><td class="py-2">{{ $reservation->nights() }}</td></tr>
        <tr><td class="py-2 text-slate-500">Adults / Children</td><td class="py-2">{{ $reservation->adults }} / {{ $reservation->children }}</td></tr>
        <tr><td class="py-2 text-slate-500">Rate quoted</td><td class="py-2">{{ number_format($reservation->rate_quoted, 0) }} XAF / night</td></tr>
        <tr><td class="py-2 text-slate-500">Deposit</td><td class="py-2">{{ number_format($reservation->deposit_paid, 0) }} / {{ number_format($reservation->deposit_required, 0) }} XAF</td></tr>
        <tr><td class="py-2 text-slate-500">Status</td><td class="py-2"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $reservation->status }}</span></td></tr>
    </tbody>
</table>

<p class="text-xs text-slate-500">
    Requested by {{ $reservation->requestedBy->name }}
    @if ($reservation->approvedBy)
        · Approved by {{ $reservation->approvedBy->name }} on {{ $reservation->approved_at->format('d M Y H:i') }}
    @endif
</p>
@endsection
