@extends('layouts.app')
@section('title', 'New Guest')
@section('content')
<form method="POST" action="{{ route('guests.store') }}" class="max-w-xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">First name</label>
            <input name="first_name" value="{{ old('first_name') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Last name</label>
            <input name="last_name" value="{{ old('last_name') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Phone</label>
            <input name="phone" value="{{ old('phone') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Email</label>
            <input type="email" name="email" value="{{ old('email') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">ID document type</label>
            <input name="id_document_type" value="{{ old('id_document_type') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">ID document number</label>
            <input name="id_document_number" value="{{ old('id_document_number') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Notes</label>
        <textarea name="notes" rows="3" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">{{ old('notes') }}</textarea>
    </div>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Save guest</button>
</form>
@endsection
