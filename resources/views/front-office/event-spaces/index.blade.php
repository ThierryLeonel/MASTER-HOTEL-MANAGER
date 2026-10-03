@extends('layouts.app')
@section('title', 'Event Spaces')
@section('content')

{{--
    Clarified via AskUserQuestion ("Main Hall, Mini HALL, Conference halls,
    etc you know better") — an admin-editable list of bookable event
    venues, mirroring the Charge Types screen's own "edit in place, delete
    only if unused" pattern.
--}}
<div class="space-y-6">
    <div class="rounded-lg bg-white shadow-sm">
        <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Event spaces</div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4">
            @forelse ($eventSpaces as $eventSpace)
                <div class="rounded-lg border border-slate-200 p-4 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="font-medium">{{ $eventSpace->name }}</div>
                        <div class="flex items-center gap-1">
                            @unless ($eventSpace->is_active)
                                <span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">Inactive</span>
                            @endunless
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $eventSpace->event_reservations_count }} event(s)</span>
                        </div>
                    </div>

                    @if ($eventSpace->max_capacity)
                        <p class="text-xs text-slate-500">Max capacity: {{ number_format($eventSpace->max_capacity) }}</p>
                    @endif

                    @if ($eventSpace->description)
                        <p class="text-xs text-slate-500">{{ $eventSpace->description }}</p>
                    @endif

                    @can('manage-event-spaces')
                        <details class="text-xs">
                            <summary class="cursor-pointer text-slate-600 underline">Edit</summary>
                            <form method="POST" action="{{ route('event-spaces.update', $eventSpace) }}" class="mt-2 space-y-1">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $eventSpace->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                <textarea name="description" rows="2" placeholder="Description (optional)" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">{{ $eventSpace->description }}</textarea>
                                <input type="number" min="1" name="max_capacity" value="{{ $eventSpace->max_capacity }}" placeholder="Max capacity (optional)" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                <label class="flex items-center gap-1"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($eventSpace->is_active)> Active</label>
                                <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                            </form>
                            <form method="POST" action="{{ route('event-spaces.destroy', $eventSpace) }}" class="mt-1"
                                  onsubmit="return confirm(@js('Delete \"'.$eventSpace->name.'\" permanently? Only possible if it has never been booked for an event — deactivate it instead if you just want to stop offering it.'));">
                                @csrf @method('DELETE')
                                <button class="text-red-700 hover:underline">Delete</button>
                            </form>
                        </details>
                    @endcan
                </div>
            @empty
                <p class="text-sm text-slate-500 p-2">No event spaces set up yet.</p>
            @endforelse
        </div>
    </div>

    @can('manage-event-spaces')
        <div class="rounded-lg bg-white shadow-sm p-4 space-y-2 max-w-lg">
            <div class="text-xs font-medium">Add an event space</div>
            <form method="POST" action="{{ route('event-spaces.store') }}" class="space-y-2">
                @csrf
                <input name="name" placeholder="Name (e.g. Garden Terrace)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <textarea name="description" placeholder="Description (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                <input type="number" min="1" name="max_capacity" placeholder="Max capacity (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create event space</button>
            </form>
        </div>
    @endcan
</div>
@endsection
