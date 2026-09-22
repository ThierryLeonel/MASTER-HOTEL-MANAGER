@extends('layouts.app')
@section('title', 'Import Reservations')
@section('content')

<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Import reservations from a spreadsheet</h1>
    <a href="{{ route('admin.system-data.import.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to Import Data</a>
</div>

@if (!$preview)
    <div class="max-w-2xl space-y-4 rounded-lg bg-white p-4 shadow-sm">
        <div class="rounded border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
            Import your guest list first (<a href="{{ route('admin.system-data.import.guests') }}" class="underline">Import &rarr; Guests</a>) — each
            reservation row is matched to an existing guest by phone number, and a phone number this system
            doesn't already recognize will be rejected rather than creating a guest automatically.
        </div>
        <p class="text-sm text-slate-600">
            Every imported reservation lands as <strong>pending</strong>, exactly like one entered by hand on the
            "New Reservation" screen — it still needs to be approved from the Reservations list afterward.
            <a href="{{ route('admin.system-data.import.reservations.template') }}" class="font-medium text-slate-800 underline">Download a template</a>
            to see the exact columns expected, fill it in, save as CSV, then upload it below.
        </p>
        <form method="POST" action="{{ route('admin.system-data.import.reservations.preview') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="file" name="file" accept=".csv,.txt" required class="block w-full text-sm">
            @error('file') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Preview import</button>
        </form>
    </div>
@else
    @include('imports._preview', ['commitRoute' => route('admin.system-data.import.reservations.commit'), 'backRoute' => route('admin.system-data.import.reservations')])
@endif

@endsection
