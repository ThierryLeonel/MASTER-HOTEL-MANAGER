@extends('layouts.app')
@section('title', 'Edit Department')
@section('content')
<form method="POST" action="{{ route('admin.departments.update', $department) }}" class="max-w-lg space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    @method('PUT')
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input name="name" value="{{ old('name', $department->name) }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Code</label>
            <input name="code" value="{{ old('code', $department->code) }}" required maxlength="20" class="w-full rounded border border-slate-300 px-3 py-2 text-sm uppercase">
        </div>
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Description</label>
        <textarea name="description" rows="3" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">{{ old('description', $department->description) }}</textarea>
    </div>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Save changes</button>
</form>
@endsection
