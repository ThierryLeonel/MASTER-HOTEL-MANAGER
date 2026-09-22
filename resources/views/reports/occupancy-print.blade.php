@extends('layouts.print')
@section('title', 'Occupancy Report — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Occupancy Report')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500">Occupancy rate</td><td class="py-2 text-right">{{ $occupancy['occupancy_rate'] }}% ({{ $occupancy['room_nights_occupied'] }} / {{ $occupancy['room_nights_available'] }} room-nights)</td></tr>
        <tr><td class="py-2 text-slate-500">ADR</td><td class="py-2 text-right">{{ number_format($occupancy['adr'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">RevPAR</td><td class="py-2 text-right">{{ number_format($occupancy['revpar'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Total rooms</td><td class="py-2 text-right">{{ $occupancy['total_rooms'] }}</td></tr>
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Daily occupied rooms</p>
<table class="w-full text-sm">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Date</th><th class="py-1 text-right">Occupied</th></tr></thead>
    <tbody class="divide-y">
        @foreach ($occupancy['daily'] as $day)
            <tr><td class="py-1">{{ $day['date'] }}</td><td class="py-1 text-right">{{ $day['occupied'] }}</td></tr>
        @endforeach
    </tbody>
</table>
@endsection
