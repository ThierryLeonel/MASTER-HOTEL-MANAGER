@extends('layouts.app')
@section('title', 'Users')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <form method="GET" class="flex gap-2">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name, username, or email"
               class="rounded border border-slate-300 px-3 py-1.5 text-sm w-72">
        <button class="rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Search</button>
    </form>
    <a href="{{ route('admin.users.create') }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New user</a>
</div>

<div class="overflow-x-auto rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Name</th>
                <th class="p-3">Username</th>
                <th class="p-3">Role</th>
                <th class="p-3">Department</th>
                <th class="p-3">Auth</th>
                <th class="p-3">Status</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach ($users as $user)
                <tr class="{{ $user->is_active ? '' : 'opacity-50' }}">
                    <td class="p-3">{{ $user->name }}</td>
                    <td class="p-3">{{ $user->username }}</td>
                    <td class="p-3">{{ $user->roles->first()?->name ?? '—' }}</td>
                    <td class="p-3">{{ $user->department?->name ?? '—' }}</td>
                    <td class="p-3">{{ str_replace('_', ' ', $user->auth_type) }}</td>
                    <td class="p-3">
                        @if ($user->is_active)
                            <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Active</span>
                        @else
                            <span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">Deactivated</span>
                        @endif
                        @if ($user->isPinLocked())
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">PIN locked</span>
                        @endif
                    </td>
                    <td class="p-3 text-right">
                        <a href="{{ route('admin.users.edit', $user) }}" class="text-slate-600 hover:underline">Edit</a>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $users->links() }}</div>
@endsection
