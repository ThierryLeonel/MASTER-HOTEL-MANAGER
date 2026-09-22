@extends('layouts.app')
@section('title', 'Import Rooms')
@section('content')

<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Import rooms from a spreadsheet</h1>
    <a href="{{ route('admin.system-data.import.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to Import Data</a>
</div>

@if (!$preview)
    <div class="max-w-2xl space-y-4 rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-slate-600">
            Upload a CSV spreadsheet of your rooms — a room number that already exists is updated in place, a new
            one is added. Each room's category (e.g. Standard, Deluxe, Suite) must already exist under
            <a href="{{ route('admin.room-categories.index') }}" class="underline">Room Categories</a> before you
            import — add any missing categories there first.
            <a href="{{ route('admin.system-data.import.rooms.template') }}" class="font-medium text-slate-800 underline">Download a template</a>
            to see the exact columns expected, fill it in, save as CSV, then upload it below.
        </p>
        <form method="POST" action="{{ route('admin.system-data.import.rooms.preview') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="file" name="file" accept=".csv,.txt" required class="block w-full text-sm">
            @error('file') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Preview import</button>
        </form>
    </div>
@else
    @include('imports._preview', ['commitRoute' => route('admin.system-data.import.rooms.commit'), 'backRoute' => route('admin.system-data.import.rooms')])
@endif

@endsection
