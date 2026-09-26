@extends('layouts.app')
@section('title', 'Departments')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Departments</h1>
    <a href="{{ route('admin.departments.create') }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New department</a>
</div>

<div class="overflow-x-auto rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Name</th>
                <th class="p-3">Code</th>
                <th class="p-3">Description</th>
                <th class="p-3">Staff</th>
                <th class="p-3">Status</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach ($departments as $department)
                <tr class="{{ $department->is_active ? '' : 'opacity-50' }}">
                    <td class="p-3">{{ $department->name }}</td>
                    <td class="p-3">{{ $department->code }}</td>
                    <td class="p-3 text-slate-500">{{ $department->description }}</td>
                    <td class="p-3">{{ $department->users_count }}</td>
                    <td class="p-3">
                        @if ($department->is_active)
                            <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Active</span>
                        @else
                            <span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">Deactivated</span>
                        @endif
                    </td>
                    <td class="p-3 text-right space-x-2">
                        <a href="{{ route('admin.departments.edit', $department) }}" class="text-slate-600 hover:underline">Edit</a>
                        @if ($department->is_active)
                            <form method="POST" action="{{ route('admin.departments.deactivate', $department) }}" class="inline">
                                @csrf
                                <button class="text-red-600 hover:underline">Deactivate</button>
                            </form>
                        @else
                            <form method="POST" action="{{ route('admin.departments.activate', $department) }}" class="inline">
                                @csrf
                                <button class="text-green-600 hover:underline">Reactivate</button>
                            </form>
                        @endif
                        <form method="POST" action="{{ route('admin.departments.destroy', $department) }}" class="inline"
                              onsubmit="return confirm(@js('Delete department '.$department->name.' permanently? Only a department with no history at all can be deleted — otherwise deactivate it instead.'));">
                            @csrf
                            @method('DELETE')
                            <button class="text-red-700 hover:underline">Delete</button>
                        </form>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
@endsection
