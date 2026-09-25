@extends('layouts.app')
@section('title', 'Room Board')
@section('content')

@can('manage-rooms')
<div class="mb-6 max-w-md rounded-lg bg-white p-4 shadow-sm">
    <h2 class="font-semibold mb-3 text-sm">Add room</h2>
    <form method="POST" action="{{ route('rooms.store') }}" class="flex flex-wrap gap-2">
        @csrf
        <input name="room_number" placeholder="Room #" required class="rounded border border-slate-300 px-3 py-1.5 text-sm w-24">
        <select name="room_category_id" required class="rounded border border-slate-300 px-3 py-1.5 text-sm flex-1 min-w-[10rem]">
            @foreach ($categories as $category)
                <option value="{{ $category->id }}">{{ $category->name }}</option>
            @endforeach
        </select>
        <input name="floor" placeholder="Floor" class="rounded border border-slate-300 px-3 py-1.5 text-sm w-20">
        <button class="btn-action rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add</button>
    </form>
</div>
@endcan

<div class="grid grid-cols-2 gap-4 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6">
    @foreach ($rooms as $room)
        @php
            $color = match($room->status) {
                'available' => 'bg-green-50 border-green-200',
                'occupied' => 'bg-blue-50 border-blue-200',
                'maintenance', 'out_of_service' => 'bg-red-50 border-red-200',
                default => 'bg-slate-50 border-slate-200',
            };
        @endphp
        <div class="rounded-lg border {{ $color }} p-3 text-sm {{ $room->is_active ? '' : 'opacity-60' }}">
            <div class="font-semibold">
                {{ $room->room_number }}
                @unless ($room->is_active)
                    <span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px] font-medium text-slate-600">Inactive</span>
                @endunless
            </div>
            <div class="text-xs text-slate-500">{{ $room->category->name }}@if ($room->floor) · Floor {{ $room->floor }}@endif</div>
            @if ($room->notes)
                <div class="mt-1 text-xs text-slate-600">{{ $room->notes }}</div>
            @endif
            <div class="mt-1 text-xs">{{ ucfirst($room->status) }}</div>
            @if ($room->currentStay)
                <div class="mt-1 text-xs text-slate-600">{{ $room->currentStay->guest->full_name ?? '' }}</div>
            @endif

            @can('update-room-status')
                @if ($room->status !== 'occupied')
                    <form method="POST" action="{{ route('rooms.update-status', $room) }}" class="mt-2 flex gap-1">
                        @csrf
                        <select name="status" class="btn-action flex-1 rounded border border-slate-300 bg-white px-1.5 py-1 text-xs">
                            <option value="available" @selected($room->status === 'available')>Available</option>
                            <option value="maintenance" @selected($room->status === 'maintenance')>Maintenance</option>
                            <option value="out_of_service" @selected($room->status === 'out_of_service')>Out of service</option>
                        </select>
                        <button class="btn-action rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Set</button>
                    </form>
                @endif
            @endcan

            {{-- Owner-only: edit or delete this room ('edit-room-details'). --}}
            @can('edit-room-details')
                <details class="mt-2">
                    <summary class="btn-action cursor-pointer text-xs text-slate-600 underline">Edit room</summary>
                    <form method="POST" action="{{ route('rooms.update', $room) }}" class="mt-2 space-y-1.5">
                        @csrf
                        @method('PUT')
                        <label class="block text-[11px] text-slate-500">Room number</label>
                        <input name="room_number" value="{{ $room->room_number }}" required maxlength="20" class="w-full rounded border border-slate-300 bg-white px-1.5 py-1 text-xs">
                        <label class="block text-[11px] text-slate-500">Category</label>
                        <select name="room_category_id" required class="w-full rounded border border-slate-300 bg-white px-1.5 py-1 text-xs">
                            @foreach ($allCategories as $category)
                                <option value="{{ $category->id }}" @selected((int) $category->id === (int) $room->room_category_id)>{{ $category->name }}{{ $category->is_active ? '' : ' (inactive)' }}</option>
                            @endforeach
                        </select>
                        <label class="block text-[11px] text-slate-500">Floor</label>
                        <input name="floor" value="{{ $room->floor }}" maxlength="20" class="w-full rounded border border-slate-300 bg-white px-1.5 py-1 text-xs">
                        <label class="block text-[11px] text-slate-500">Description</label>
                        <textarea name="notes" rows="2" maxlength="1000" class="w-full rounded border border-slate-300 bg-white px-1.5 py-1 text-xs">{{ $room->notes }}</textarea>
                        <label class="flex items-center gap-1 text-xs">
                            <input type="checkbox" name="is_active" value="1" @checked($room->is_active)> Active (bookable)
                        </label>
                        <button class="btn-action w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save changes</button>
                    </form>
                    <form method="POST" action="{{ route('rooms.destroy', $room) }}" class="mt-1.5"
                          onsubmit="return confirm(@js('Delete room '.$room->room_number.' permanently? Only a room with no history can be deleted — otherwise deactivate it instead.'));">
                        @csrf
                        @method('DELETE')
                        <button class="btn-action w-full rounded border border-red-300 bg-white px-2 py-1 text-xs text-red-700 hover:bg-red-50">Delete room</button>
                    </form>
                </details>
            @endcan
        </div>
    @endforeach
</div>
@endsection
