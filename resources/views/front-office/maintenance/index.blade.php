@extends('layouts.app')
@section('title', 'Maintenance')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Room</th><th class="p-3">Title</th><th class="p-3">Priority</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($tickets as $ticket)
                    <tr>
                        <td class="p-3">{{ $ticket->room->room_number ?? 'Common area' }}</td>
                        <td class="p-3">{{ $ticket->title }}</td>
                        <td class="p-3">{{ ucfirst($ticket->priority) }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $ticket->status }}</span></td>
                        <td class="p-3 space-x-2">
                            @if ($ticket->status === 'open')
                                @can('approve', $ticket)
                                    <form method="POST" action="{{ route('maintenance.approve', $ticket) }}" class="inline">
                                        @csrf
                                        <button class="text-green-700 hover:underline">Approve</button>
                                    </form>
                                @endcan
                            @elseif (in_array($ticket->status, ['approved','in_progress']))
                                @can('resolve', $ticket)
                                    <form method="POST" action="{{ route('maintenance.resolve', $ticket) }}" class="inline" onsubmit="return setNotes(this)">
                                        @csrf
                                        <input type="hidden" name="notes" value="">
                                        <button class="text-slate-600 hover:underline">Resolve</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="5">No open maintenance tickets.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="col-span-1">
        <form method="POST" action="{{ route('maintenance.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Report an issue</div>
            <select name="room_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Common area (no room)</option>
                @foreach ($rooms ?? [] as $room)
                    <option value="{{ $room->id }}">{{ $room->room_number }}</option>
                @endforeach
            </select>
            <input name="title" placeholder="Short title" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <textarea name="description" placeholder="Description" required rows="3" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
            <select name="priority" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="low">Low</option>
                <option value="medium" selected>Medium</option>
                <option value="high">High</option>
                <option value="urgent">Urgent</option>
            </select>
            <label class="flex items-center gap-2 text-xs">
                <input type="checkbox" name="takes_room_out_of_service" value="1"> Requires taking room out of service
            </label>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Report</button>
        </form>
    </div>
</div>

<script>
function setNotes(form) {
    const notes = prompt('Resolution notes:');
    if (!notes) return false;
    form.notes.value = notes;
    return true;
}
</script>
@endsection
