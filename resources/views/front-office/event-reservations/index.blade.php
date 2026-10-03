@extends('layouts.app')
@section('title', 'Event Reservations')
@section('content')

<div class="mb-4 flex items-center justify-between">
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="rounded border border-slate-300 px-3 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (['pending','confirmed','completed','cancelled','rejected'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
    <a href="{{ route('event-reservations.create') }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New Event Reservation</a>
</div>

<div class="overflow-x-auto rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Reference</th><th class="p-3">Event</th><th class="p-3">Contact</th><th class="p-3">Spaces / Rooms</th><th class="p-3">When</th><th class="p-3">Status</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($events as $event)
                <tr>
                    <td class="p-3">{{ $event->event_reference }}</td>
                    <td class="p-3">{{ $event->event_name }}<div class="text-xs text-slate-400">{{ $event->event_type }}</div></td>
                    <td class="p-3">{{ $event->contactDisplayName() }}</td>
                    <td class="p-3 text-xs">
                        {{ $event->eventSpaces->pluck('name')->implode(', ') }}
                        @if ($event->eventSpaces->isNotEmpty() && $event->rooms->isNotEmpty()), @endif
                        {{ $event->rooms->pluck('room_number')->map(fn ($n) => "Room {$n}")->implode(', ') }}
                    </td>
                    <td class="p-3">{{ $event->start_at->format('d M Y H:i') }} – {{ $event->end_at->format('d M Y H:i') }}</td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $event->status }}</span></td>
                    <td class="p-3"><a href="{{ route('event-reservations.show', $event) }}" class="text-slate-600 hover:underline">View</a></td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="7">No event reservations yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $events->links() }}</div>
@endsection
