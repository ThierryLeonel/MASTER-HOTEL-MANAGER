@extends('layouts.app')
@section('title', $guest->full_name)
@section('content')

{{-- Post-9.8 fix round ("if we start by creating a guest first, we
     should be able to see 2 buttons, check in and reservation"): shown
     only right after GuestController::store()'s redirect, via the `new=1`
     query flag — reopening this same page later never shows it again. --}}
@if (request()->boolean('new'))
    <div class="mb-4 flex flex-wrap items-center justify-between gap-3 rounded-lg border border-green-200 bg-green-50 p-4 text-sm text-green-800">
        <div><strong>{{ $guest->full_name }}</strong> was added to the guest list. What would you like to do next?</div>
        <div class="flex gap-2">
            @can('checkin-guest')
                <a href="{{ route('checkin.walk-in', ['guest_id' => $guest->id]) }}" class="btn-action rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Check In</a>
            @endcan
            @can('manage-reservations')
                <a href="{{ route('reservations.create', ['guest_id' => $guest->id]) }}" class="btn-action rounded bg-white border border-slate-300 px-3 py-1.5 hover:bg-slate-100">Reservation</a>
            @endcan
        </div>
    </div>
@endif

<div class="grid grid-cols-1 gap-6 md:grid-cols-3">
    <div class="col-span-1 rounded-lg bg-white p-4 shadow-sm text-sm space-y-2">
        <div><span class="text-slate-500">Phone:</span> {{ $guest->phone ?: '—' }}</div>
        <div><span class="text-slate-500">Email:</span> {{ $guest->email ?: '—' }}</div>
        <div><span class="text-slate-500">ID:</span> {{ $guest->id_document_type }} {{ $guest->id_document_number }}</div>
        @if ($guest->is_blacklisted)
            <div class="rounded bg-red-50 border border-red-200 text-red-700 p-2">Blacklisted: {{ $guest->blacklist_reason }}</div>
        @endif
        @can('manage-reservations')
            <a href="{{ route('reservations.create') }}" class="inline-block mt-2 rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">New reservation</a>
        @endcan
    </div>
    <div class="col-span-1 space-y-6 md:col-span-2">
        <div>
            <h2 class="font-semibold mb-2">Reservations</h2>
            <div class="rounded-lg bg-white shadow-sm divide-y text-sm">
                @forelse ($guest->reservations as $reservation)
                    <a href="{{ route('reservations.show', $reservation) }}" class="block p-3 hover:bg-slate-50">
                        {{ $reservation->reservation_number }} — {{ $reservation->check_in_date->format('d M Y') }} to {{ $reservation->check_out_date->format('d M Y') }}
                        <span class="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $reservation->status }}</span>
                    </a>
                @empty
                    <div class="p-3 text-slate-500">No reservations yet.</div>
                @endforelse
            </div>
        </div>
        <div>
            <h2 class="font-semibold mb-2">Stays</h2>
            <div class="rounded-lg bg-white shadow-sm divide-y text-sm">
                @forelse ($guest->stays as $stay)
                    <a href="{{ route('stays.show', $stay) }}" class="block p-3 hover:bg-slate-50">
                        Room {{ $stay->room->room_number ?? '—' }} — checked in {{ $stay->check_in_at->format('d M Y H:i') }}
                        <span class="ml-2 rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $stay->status }}</span>
                    </a>
                @empty
                    <div class="p-3 text-slate-500">No stays yet.</div>
                @endforelse
            </div>
        </div>
    </div>
</div>
@endsection
