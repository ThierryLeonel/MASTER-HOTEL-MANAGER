@extends('layouts.app')
@section('title', 'New User')
@section('content')
<form method="POST" action="{{ route('admin.users.store') }}" class="max-w-xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Full name</label>
            <input name="name" value="{{ old('name') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Username</label>
            <input name="username" value="{{ old('username') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>
    <div>
        <label class="block text-sm font-medium mb-1">Email</label>
        <input type="email" name="email" value="{{ old('email') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Role</label>
            <select name="role" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @foreach ($roles as $role)
                    <option value="{{ $role }}" @selected(old('role') === $role)>{{ str_replace('_', ' ', $role) }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Department</label>
            <select name="department_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">—</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected((string) old('department_id') === (string) $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </div>
    </div>

    <div class="border-t border-slate-200 pt-4">
        <label class="block text-sm font-medium mb-1">Login method</label>
        <select name="auth_type" id="auth_type" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm" onchange="toggleCredentialFields()">
            <option value="pin" @selected(old('auth_type', 'pin') === 'pin')>PIN (Cashier, Front Desk, Bar/Restaurant Staff)</option>
            <option value="password_only" @selected(old('auth_type') === 'password_only')>Password only (Duty Manager)</option>
            <option value="password_mfa" @selected(old('auth_type') === 'password_mfa')>Password + TOTP (Owner, General Manager, Accountant)</option>
        </select>
        <p class="mt-1 text-xs text-slate-500">Password + TOTP accounts enroll their authenticator app on first login — nothing further to configure here.</p>
    </div>

    <div id="pin_field">
        <label class="block text-sm font-medium mb-1">Initial PIN (4–6 digits)</label>
        <input name="pin" value="{{ old('pin') }}" inputmode="numeric" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
    </div>
    <div id="password_field" class="hidden">
        <label class="block text-sm font-medium mb-1">Initial password (min 8 characters)</label>
        <input type="password" name="password" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        <p class="mt-1 text-xs text-slate-500">Share this with the staff member directly — it is never shown again after creation.</p>
    </div>

    <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Create user</button>
</form>

<script>
    function toggleCredentialFields() {
        const authType = document.getElementById('auth_type').value;
        document.getElementById('pin_field').classList.toggle('hidden', authType !== 'pin');
        document.getElementById('password_field').classList.toggle('hidden', authType === 'pin');
    }
    toggleCredentialFields();
</script>
@endsection
