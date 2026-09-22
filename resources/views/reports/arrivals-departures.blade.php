@extends('layouts.app')
@section('title', 'Arrivals & Departures')
@section('content')
@include('reports._date-filter')

<div class="mb-4 text-xs">
    <a href="{{ route('reports.arrivals-departures.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
</div>

<div class="grid grid-cols-2 gap-6">
    <div class="rounded-lg bg-white shadow-sm">
        <div class="p-3 font-medium border-b">Arrivals ({{ $arrivals->count() }})</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Guest</th><th class="p-3">Room</th><th class="p-3">Check-in</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($arrivals as $stay)
                    <tr><td class="p-3">{{ $stay->guest->full_name }}</td><td class="p-3">{{ $stay->room->room_number }}</td><td class="p-3">{{ $stay->check_in_at->format('d M H:i') }}</td></tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="3">No arrivals in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-lg bg-white shadow-sm">
        <div class="p-3 font-medium border-b">Departures ({{ $departures->count() }})</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Guest</th><th class="p-3">Room</th><th class="p-3">Check-out</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($departures as $stay)
                    <tr><td class="p-3">{{ $stay->guest->full_name }}</td><td class="p-3">{{ $stay->room->room_number }}</td><td class="p-3">{{ $stay->actual_check_out_at->format('d M H:i') }}</td></tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="3">No departures in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
