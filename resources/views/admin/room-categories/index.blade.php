@extends('layouts.app')
@section('title', 'Room Categories')
@section('content')
<div class="mb-4">
    <h1 class="text-lg font-semibold">Room Categories</h1>
    <p class="text-xs text-slate-500">Nightly rates and occupancy limits used by Reservations and the Room board. Deactivating a category does not affect existing rooms already assigned to it.</p>
</div>

<div class="space-y-4">
    @foreach ($categories as $category)
        <div class="rounded-lg bg-white p-4 shadow-sm {{ $category->is_active ? '' : 'opacity-50' }}">
            <form method="POST" action="{{ route('admin.room-categories.update', $category) }}">
                @csrf
                @method('PUT')
                <div class="flex flex-wrap items-end gap-3">
                    <div class="w-48">
                        <label class="block text-xs font-medium mb-1">Name</label>
                        <input name="name" value="{{ $category->name }}" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="w-24">
                        <label class="block text-xs font-medium mb-1">Max occupancy</label>
                        <input type="number" min="1" max="20" name="max_occupancy" value="{{ $category->max_occupancy }}" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="w-32">
                        <label class="block text-xs font-medium mb-1">Base rate (XAF/night)</label>
                        <input type="number" min="0" step="0.01" name="base_rate" value="{{ $category->base_rate }}" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="flex-1 min-w-[12rem]">
                        <label class="block text-xs font-medium mb-1">Description</label>
                        <input name="description" value="{{ $category->description }}" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    </div>
                    <div class="text-xs text-slate-500 pb-2">{{ $category->rooms_count }} room(s)</div>
                    <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Save</button>
                </div>
            </form>
            <div class="mt-2 flex items-center justify-between border-t border-slate-100 pt-2">
                <span class="text-xs text-slate-500">{{ $category->is_active ? 'Active' : 'Deactivated' }}</span>
                <div class="space-x-3">
                    @if ($category->is_active)
                        <form method="POST" action="{{ route('admin.room-categories.deactivate', $category) }}" class="inline">
                            @csrf
                            <button class="text-xs text-red-600 hover:underline">Deactivate</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('admin.room-categories.activate', $category) }}" class="inline">
                            @csrf
                            <button class="text-xs text-green-600 hover:underline">Reactivate</button>
                        </form>
                    @endif
                    <form method="POST" action="{{ route('admin.room-categories.destroy', $category) }}" class="inline"
                          onsubmit="return confirm(@js('Delete category '.$category->name.' permanently? Only a category with no rooms or history can be deleted — otherwise deactivate it instead.'));">
                        @csrf
                        @method('DELETE')
                        <button class="text-xs text-red-700 hover:underline">Delete</button>
                    </form>
                </div>
            </div>
        </div>
    @endforeach
</div>

<div class="mt-6 max-w-2xl rounded-lg bg-white p-4 shadow-sm">
    <h2 class="font-semibold mb-3 text-sm">Add room category</h2>
    <form method="POST" action="{{ route('admin.room-categories.store') }}" class="flex flex-wrap items-end gap-3">
        @csrf
        <div class="w-48">
            <label class="block text-xs font-medium mb-1">Name</label>
            <input name="name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div class="w-24">
            <label class="block text-xs font-medium mb-1">Max occupancy</label>
            <input type="number" min="1" max="20" name="max_occupancy" value="2" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div class="w-32">
            <label class="block text-xs font-medium mb-1">Base rate (XAF/night)</label>
            <input type="number" min="0" step="0.01" name="base_rate" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <div class="flex-1 min-w-[12rem]">
            <label class="block text-xs font-medium mb-1">Description</label>
            <input name="description" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
        </div>
        <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add</button>
    </form>
</div>
@endsection
