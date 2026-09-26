@extends('layouts.app')
@section('title', 'Edit User')
@section('content')

<div class="max-w-xl space-y-6">
    <div class="rounded-lg bg-white p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between">
            <h2 class="text-sm font-semibold">Account details</h2>
            @if ($targetUser->is_active)
                <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Active</span>
            @else
                <span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">Deactivated</span>
            @endif
        </div>

        <form method="POST" action="{{ route('admin.users.update', $targetUser) }}" class="space-y-4">
            @csrf
            @method('PUT')
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Full name</label>
                    <input name="name" value="{{ old('name', $targetUser->name) }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Username</label>
                    <input name="username" value="{{ old('username', $targetUser->username) }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Email</label>
                <input type="email" name="email" value="{{ old('email', $targetUser->email) }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Role</label>
                    <select name="role" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        @foreach ($roles as $role)
                            <option value="{{ $role }}" @selected(old('role', $targetUser->roles->first()?->name) === $role)>{{ str_replace('_', ' ', $role) }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Department</label>
                    <select name="department_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">—</option>
                        @foreach ($departments as $department)
                            <option value="{{ $department->id }}" @selected((string) old('department_id', $targetUser->department_id) === (string) $department->id)>{{ $department->name }}</option>
                        @endforeach
                    </select>
                </div>
            </div>
            <p class="text-xs text-slate-500">Login method ({{ str_replace('_', ' ', $targetUser->auth_type) }}) cannot be changed here — create a new account if a staff member needs a different login method.</p>
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Save changes</button>
        </form>
    </div>

    <div class="rounded-lg bg-white p-6 shadow-sm">
        <h2 class="mb-3 text-sm font-semibold">Account status</h2>
        @if ($targetUser->is_active)
            <form method="POST" action="{{ route('admin.users.deactivate', $targetUser) }}" onsubmit="return confirm('Deactivate this account? They will no longer be able to log in.');">
                @csrf
                <button class="rounded bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-500">Deactivate account</button>
            </form>
        @else
            <form method="POST" action="{{ route('admin.users.activate', $targetUser) }}">
                @csrf
                <button class="rounded bg-green-600 px-3 py-1.5 text-sm text-white hover:bg-green-500">Reactivate account</button>
            </form>
        @endif
    </div>

    @can('delete-users')
        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="mb-2 text-sm font-semibold">Delete account</h2>
            <p class="mb-3 text-xs text-slate-500">
                This removes {{ $targetUser->name }} from the active user list and blocks their login. Every
                shift, folio, payment, and audit-log entry tied to this account is kept exactly as-is — nothing
                historical is erased. Reversible from "Removed users" by an Owner.
            </p>
            <form method="POST" action="{{ route('admin.users.destroy', $targetUser) }}"
                  onsubmit="return confirm(@js('Delete '.$targetUser->name.'\'s account? They will no longer be able to log in. This can be undone from Removed users.'));">
                @csrf
                @method('DELETE')
                <button class="rounded bg-red-600 px-3 py-1.5 text-sm text-white hover:bg-red-500">Delete account</button>
            </form>
        </div>
    @endcan

    @if ($targetUser->auth_type === 'pin')
        <div class="rounded-lg bg-white p-6 shadow-sm space-y-4">
            <h2 class="text-sm font-semibold">PIN</h2>
            @if ($targetUser->isPinLocked())
                <div class="rounded border border-amber-200 bg-amber-50 p-3 text-sm text-amber-800">
                    Locked out after repeated failed attempts (until {{ $targetUser->pin_locked_until->format('Y-m-d H:i') }}).
                    <form method="POST" action="{{ route('admin.users.unlock-pin', $targetUser) }}" class="mt-2">
                        @csrf
                        <button class="rounded bg-slate-800 px-3 py-1.5 text-xs text-white hover:bg-slate-700">Clear lockout now</button>
                    </form>
                </div>
            @endif
            <form method="POST" action="{{ route('admin.users.reset-pin', $targetUser) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="block text-sm font-medium mb-1">New PIN (4–6 digits)</label>
                    <input name="pin" inputmode="numeric" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <button class="rounded bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700">Reset PIN</button>
            </form>
        </div>
    @else
        <div class="rounded-lg bg-white p-6 shadow-sm">
            <h2 class="mb-3 text-sm font-semibold">Password</h2>
            <form method="POST" action="{{ route('admin.users.reset-password', $targetUser) }}" class="flex items-end gap-2">
                @csrf
                <div class="flex-1">
                    <label class="block text-sm font-medium mb-1">New password (min 8 characters)</label>
                    <input type="password" name="password" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <button class="rounded bg-slate-800 px-3 py-2 text-sm text-white hover:bg-slate-700">Reset password</button>
            </form>
            <p class="mt-2 text-xs text-slate-500">Share the new password with the staff member directly — it is never shown again after this.</p>
        </div>
    @endif
</div>
@endsection
