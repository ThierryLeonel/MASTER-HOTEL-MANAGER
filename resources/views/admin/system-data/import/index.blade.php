@extends('layouts.app')
@section('title', 'Import Data')
@section('content')

<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Import data from a spreadsheet</h1>
    <a href="{{ route('admin.system-data.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to Master Admin</a>
</div>

<p class="mb-4 max-w-2xl text-sm text-slate-600">
    Bring existing guests, reservations, rooms, a menu, or staff into the system from a CSV spreadsheet — each has
    its own downloadable template and shows you exactly what would change before anything is saved. Import your
    guest list before your reservations, since each reservation is matched to a guest by phone number.
</p>

<div class="grid grid-cols-1 gap-4 sm:grid-cols-2 lg:grid-cols-3">
    <a href="{{ route('admin.system-data.import.guests') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
        <div class="text-sm font-medium">Guests</div>
        <p class="mt-1 text-xs text-slate-500">Your existing guest list — matched by phone to avoid duplicates.</p>
    </a>
    <a href="{{ route('admin.system-data.import.reservations') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
        <div class="text-sm font-medium">Reservations</div>
        <p class="mt-1 text-xs text-slate-500">Lands as pending, same as one entered by hand — import guests first.</p>
    </a>
    <a href="{{ route('admin.system-data.import.rooms') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
        <div class="text-sm font-medium">Rooms</div>
        <p class="mt-1 text-xs text-slate-500">Matched by room number — the room category must already exist.</p>
    </a>
    <a href="{{ route('admin.system-data.import.menu') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
        <div class="text-sm font-medium">Menu</div>
        <p class="mt-1 text-xs text-slate-500">One outlet (Bar, Restaurant, ...) at a time — categories are created automatically.</p>
    </a>
    <a href="{{ route('admin.system-data.import.staff') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
        <div class="text-sm font-medium">Staff</div>
        <p class="mt-1 text-xs text-slate-500">Creates new accounts only — a temporary login is generated for each one.</p>
    </a>
</div>

@endsection
