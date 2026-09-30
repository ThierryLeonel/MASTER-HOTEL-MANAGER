@extends('layouts.app')
@section('title', 'Occupancy Report')
@section('content')
@include('reports._date-filter')

<div class="mb-4 text-xs">
    <a href="{{ route('reports.occupancy.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
</div>

<div class="grid grid-cols-4 gap-4 mb-6">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Occupancy rate</div>
        <div class="text-2xl font-semibold">{{ $occupancy['occupancy_rate'] }}%</div>
        <div class="text-xs text-slate-500">{{ $occupancy['room_nights_occupied'] }} / {{ $occupancy['room_nights_available'] }} room-nights</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500" title="Average Daily Rate — total room revenue divided by rooms actually sold">ADR</div>
        <div class="text-2xl font-semibold">{{ number_format($occupancy['adr'], 0) }}</div>
        <div class="text-xs text-slate-500">Average Daily Rate</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500" title="Revenue Per Available Room — total room revenue divided by every room available, occupied or not">RevPAR</div>
        <div class="text-2xl font-semibold">{{ number_format($occupancy['revpar'], 0) }}</div>
        <div class="text-xs text-slate-500">Revenue Per Available Room</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Total rooms</div>
        <div class="text-2xl font-semibold">{{ $occupancy['total_rooms'] }}</div>
    </div>
</div>

<div class="rounded-lg bg-white shadow-sm p-4">
    <div class="font-medium mb-2">Daily occupied rooms</div>
    <table class="w-full text-sm">
        <thead class="text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Date</th><th class="py-1 text-right">Occupied</th></tr></thead>
        <tbody class="divide-y">
            @foreach ($occupancy['daily'] as $day)
                <tr><td class="py-1">{{ $day['date'] }}</td><td class="py-1 text-right">{{ $day['occupied'] }}</td></tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
