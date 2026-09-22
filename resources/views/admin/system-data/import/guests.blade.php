@extends('layouts.app')
@section('title', 'Import Guests')
@section('content')

<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Import guests from a spreadsheet</h1>
    <a href="{{ route('admin.system-data.import.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to Import Data</a>
</div>

@if (!$preview)
    <div class="max-w-2xl space-y-4 rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-slate-600">
            Upload a CSV spreadsheet of your existing guests — phone number is used to avoid creating the same
            guest twice, so include it whenever you have it.
            <a href="{{ route('admin.system-data.import.guests.template') }}" class="font-medium text-slate-800 underline">Download a template</a>
            to see the exact columns expected: fill it in with Excel or Google Sheets, save it as CSV, then upload
            it below. You'll see exactly what will be added or changed before anything is saved.
        </p>
        <form method="POST" action="{{ route('admin.system-data.import.guests.preview') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="file" name="file" accept=".csv,.txt" required class="block w-full text-sm">
            @error('file') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Preview import</button>
        </form>
    </div>
@else
    @include('imports._preview', ['commitRoute' => route('admin.system-data.import.guests.commit'), 'backRoute' => route('admin.system-data.import.guests')])
@endif

@endsection
