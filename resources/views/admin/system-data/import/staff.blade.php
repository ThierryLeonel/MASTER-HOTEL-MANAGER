@extends('layouts.app')
@section('title', 'Import Staff')
@section('content')

<div class="mb-4 flex items-center justify-between">
    <h1 class="text-lg font-semibold">Import staff from a spreadsheet</h1>
    <a href="{{ route('admin.system-data.import.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to Import Data</a>
</div>

@if ($credentials)
    <div class="max-w-3xl space-y-4">
        <div class="rounded border border-green-300 bg-green-50 p-3 text-sm text-green-800">
            Staff import complete: {{ $credentials['created'] }} account(s) created, {{ $credentials['skipped'] }} skipped
            (already existed or had errors).
        </div>

        @if (count($credentials['credentials']))
            <div class="rounded-lg border-2 border-amber-300 bg-amber-50 p-4">
                <p class="mb-3 text-sm font-semibold text-amber-800">
                    Write these down or share them now — each login is shown here only once and cannot be
                    retrieved again. Whoever it belongs to should change it after their first sign-in.
                </p>
                <div class="overflow-x-auto rounded bg-white">
                    <table class="w-full text-sm">
                        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                            <tr>
                                <th class="p-2">Name</th>
                                <th class="p-2">Username</th>
                                <th class="p-2">Role</th>
                                <th class="p-2">Login type</th>
                                <th class="p-2">Credential</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($credentials['credentials'] as $cred)
                                <tr>
                                    <td class="p-2">{{ $cred['name'] }}</td>
                                    <td class="p-2">{{ $cred['username'] }}</td>
                                    <td class="p-2">{{ $cred['role'] }}</td>
                                    <td class="p-2">{{ $cred['credential_type'] }}</td>
                                    <td class="p-2 font-mono font-semibold">{{ $cred['credential'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <a href="{{ route('admin.system-data.import.staff') }}" class="inline-block text-sm text-slate-600 hover:underline">&larr; Import more staff</a>
    </div>
@elseif (!$preview)
    <div class="max-w-2xl space-y-4 rounded-lg bg-white p-4 shadow-sm">
        <p class="text-sm text-slate-600">
            Upload a CSV spreadsheet of new staff accounts — this only creates accounts, it never edits an existing
            one, and a username or email already in use is rejected rather than overwritten. Never include a
            password or PIN in the file: a login is generated automatically for each new account and shown to you
            once, right after import.
            <a href="{{ route('admin.system-data.import.staff.template') }}" class="font-medium text-slate-800 underline">Download a template</a>
            to see the exact columns expected, fill it in, save as CSV, then upload it below.
        </p>
        <form method="POST" action="{{ route('admin.system-data.import.staff.preview') }}" enctype="multipart/form-data" class="space-y-3">
            @csrf
            <input type="file" name="file" accept=".csv,.txt" required class="block w-full text-sm">
            @error('file') <p class="text-xs text-red-600">{{ $message }}</p> @enderror
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Preview import</button>
        </form>
    </div>
@else
    @include('imports._preview', ['commitRoute' => route('admin.system-data.import.staff.commit'), 'backRoute' => route('admin.system-data.import.staff')])
@endif

@endsection
