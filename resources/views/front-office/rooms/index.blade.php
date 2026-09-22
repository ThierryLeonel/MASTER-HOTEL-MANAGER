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
        <div class="rounded-lg border {{ $color }} p-3 text-sm">
            <div class="font-semibold">{{ $room->room_number }}</div>
            <div class="text-xs text-slate-500">{{ $room->category->name }}</div>
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
        </div>
    @endforeach
</div>
@endsection
