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

        @can('manage-guests')
            <details class="mt-3 border-t border-slate-100 pt-3 text-xs">
                <summary class="cursor-pointer text-slate-600 underline">Edit guest details</summary>
                <form method="POST" action="{{ route('guests.update', $guest) }}" class="mt-2 space-y-1.5">
                    @csrf @method('PUT')
                    <label class="block text-[11px] text-slate-500">First name</label>
                    <input name="first_name" value="{{ $guest->first_name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <label class="block text-[11px] text-slate-500">Last name</label>
                    <input name="last_name" value="{{ $guest->last_name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <label class="block text-[11px] text-slate-500">Phone</label>
                    <input name="phone" value="{{ $guest->phone }}" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <label class="block text-[11px] text-slate-500">Email</label>
                    <input name="email" value="{{ $guest->email }}" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <label class="block text-[11px] text-slate-500">ID type</label>
                    <input name="id_document_type" value="{{ $guest->id_document_type }}" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <label class="block text-[11px] text-slate-500">ID number</label>
                    <input name="id_document_number" value="{{ $guest->id_document_number }}" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <label class="block text-[11px] text-slate-500">Nationality</label>
                    <input name="nationality" value="{{ $guest->nationality }}" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <label class="block text-[11px] text-slate-500">Address</label>
                    <textarea name="address" rows="2" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">{{ $guest->address }}</textarea>
                    <label class="block text-[11px] text-slate-500">Notes</label>
                    <textarea name="notes" rows="2" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">{{ $guest->notes }}</textarea>
                    <label class="flex items-center gap-1"><input type="checkbox" name="is_blacklisted" value="1" @checked($guest->is_blacklisted)> Blacklisted</label>
                    <input name="blacklist_reason" value="{{ $guest->blacklist_reason }}" placeholder="Blacklist reason" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                    <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save changes</button>
                </form>
                <form method="POST" action="{{ route('guests.destroy', $guest) }}" class="mt-1.5"
                      onsubmit="return confirm(@js('Remove '.$guest->full_name.' from the active guest list? Their reservation/stay/payment history is kept, and this can be undone from Guests → Removed.'));">
                    @csrf @method('DELETE')
                    <button class="w-full rounded border border-red-300 bg-white px-2 py-1 text-xs text-red-700 hover:bg-red-50">Remove guest</button>
                </form>
            </details>
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
