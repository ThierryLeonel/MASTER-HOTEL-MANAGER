@extends('layouts.print')
@section('title', 'Arrivals & Departures — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Arrivals & Departures')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<p class="text-xs font-medium mb-1">Arrivals ({{ $arrivals->count() }})</p>
<table class="w-full text-sm mb-6">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Guest</th><th class="py-1">Room</th><th class="py-1">Check-in</th></tr></thead>
    <tbody class="divide-y">
        @forelse ($arrivals as $stay)
            <tr><td class="py-1">{{ $stay->guest->full_name }}</td><td class="py-1">{{ $stay->room->room_number }}</td><td class="py-1">{{ $stay->check_in_at->format('d M H:i') }}</td></tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="3">No arrivals in this period.</td></tr>
        @endforelse
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Departures ({{ $departures->count() }})</p>
<table class="w-full text-sm">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Guest</th><th class="py-1">Room</th><th class="py-1">Check-out</th></tr></thead>
    <tbody class="divide-y">
        @forelse ($departures as $stay)
            <tr><td class="py-1">{{ $stay->guest->full_name }}</td><td class="py-1">{{ $stay->room->room_number }}</td><td class="py-1">{{ $stay->actual_check_out_at->format('d M H:i') }}</td></tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="3">No departures in this period.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
