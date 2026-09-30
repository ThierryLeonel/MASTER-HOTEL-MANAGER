@extends('layouts.app')
@section('title', 'Reservations')
@section('content')

@can('manage-reservations')
    <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 p-3 text-sm text-blue-800">
        <p class="font-medium">Taking bookings from your website</p>
        <p class="mt-1">
            Share this link on your website (a "Book Now" button works well). Requests submitted there land here as
            <span class="rounded bg-white px-1.5 py-0.5 text-xs font-medium">pending</span> for you to review, exactly
            like a phone or walk-in booking.
        </p>
        <div class="mt-2 flex items-center gap-2">
            <input type="text" readonly value="{{ url('/book') }}" onclick="this.select()"
                   class="w-full max-w-md rounded border border-blue-200 bg-white px-2 py-1 text-xs text-slate-700">
            <button type="button" onclick="navigator.clipboard.writeText('{{ url('/book') }}')"
                    class="rounded bg-blue-600 px-2 py-1 text-xs text-white hover:bg-blue-700">Copy</button>
        </div>
    </div>
@endcan

<div class="mb-4 flex items-center justify-between">
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (['pending','confirmed','checked_in','checked_out','rejected','cancelled','no_show'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst(str_replace('_',' ',$status)) }}</option>
            @endforeach
        </select>
    </form>
    <div class="flex gap-2">
        <a href="{{ route('reservations.create-multi') }}" class="rounded border border-slate-300 bg-white px-3 py-1.5 text-sm text-slate-700 hover:bg-slate-50">+ Book Multiple Rooms</a>
        <a href="{{ route('reservations.create') }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New Reservation</a>
    </div>
</div>

<div class="overflow-x-auto rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Number</th><th class="p-3">Guest</th><th class="p-3">Dates</th><th class="p-3">Source</th><th class="p-3">Status</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @foreach ($reservations as $reservation)
                <tr>
                    <td class="p-3">
                        {{ $reservation->reservation_number }}
                        @if ($reservation->isGrouped())
                            <span class="ml-1 rounded bg-blue-50 px-1.5 py-0.5 text-[10px] text-blue-700" title="Booked together with other rooms in the same submission">Grouped</span>
                        @endif
                    </td>
                    <td class="p-3">{{ $reservation->guest->full_name }}</td>
                    <td class="p-3">{{ $reservation->check_in_date->format('d M') }} – {{ $reservation->check_out_date->format('d M Y') }}</td>
                    <td class="p-3">
                        @if ($reservation->isFromWebsite())
                            <span class="rounded bg-indigo-100 px-2 py-0.5 text-xs text-indigo-700">Website</span>
                        @else
                            <span class="text-xs text-slate-400">Staff</span>
                        @endif
                    </td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $reservation->status }}</span></td>
                    <td class="p-3"><a href="{{ route('reservations.show', $reservation) }}" class="text-slate-600 hover:underline">View</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $reservations->links() }}</div>
@endsection
