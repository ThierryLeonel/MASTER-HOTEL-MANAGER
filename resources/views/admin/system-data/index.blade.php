@extends('layouts.app')
@section('title', 'Master Admin — Data Management')
@section('content')

<div class="mb-4 rounded-lg border-2 border-red-300 bg-red-50 p-4 text-sm text-red-800">
    <p class="font-semibold">This page can permanently delete real hotel data.</p>
    <p class="mt-1">Only the Owner account can see this page. Take a backup before you reset anything — there is no undo once data is cleared.</p>
</div>

@if ($hasActiveSyncDevices)
    <div class="mb-4 rounded-lg border border-amber-300 bg-amber-50 p-4 text-sm text-amber-800">
        <p class="font-semibold">This installation has an active sync connection to another one (a hybrid setup).</p>
        <p class="mt-1">Resetting data here only clears THIS installation. If the other one still has its own copy of the same guests/reservations/payments, the next sync could bring that data right back. Resetting will automatically switch off the sync connection so that can't happen quietly — you'll need to reset the other installation separately, then reconnect sync yourself once both sides are ready.</p>
    </div>
@endif

@if (session('importIntegrityResults'))
    <div class="mb-4 rounded-lg border-2 border-amber-400 bg-amber-50 p-4 text-sm text-amber-900">
        <p class="font-semibold">The import completed, but a data-integrity check found the following issue(s) afterward:</p>
        <ul class="mt-2 list-disc pl-5 space-y-1">
            @foreach (session('importIntegrityResults') as $check)
                @if (! $check['passed'])
                    @foreach ($check['issues'] as $issue)
                        <li>{{ $issue }}</li>
                    @endforeach
                @endif
            @endforeach
        </ul>
        <p class="mt-2">This usually means the backup/export file referred to rooms, staff, or financial accounts that don't exist with the same ids on this installation — see "Portability" in the Import section below. Nothing was deleted; review the issue(s) above before relying on this data.</p>
    </div>
@endif

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6">
    <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
        <div class="text-sm font-medium">1. Backup — download everything, for safekeeping</div>
        <p class="text-xs text-slate-500">A complete technical copy of every table in the system, as one file. Staff passwords/PINs are left out for safety. Keep this file somewhere safe (email it to yourself, save it to a cloud drive) — it's what you'd hand to a developer to restore the system exactly as it was.</p>
        <a href="{{ route('admin.system-data.backup') }}" class="inline-block rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Download full backup</a>
    </div>

    <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
        <div class="text-sm font-medium">2. Extract data — for your own records</div>
        <p class="text-xs text-slate-500">Guests, reservations, stays, payments, refunds, debt, expenses, POS orders, rooms, and financial accounts — as spreadsheet files (CSV) you can open in Excel or Google Sheets. Not a full technical backup — use option 1 for that.</p>
        <a href="{{ route('admin.system-data.export') }}" class="inline-block rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Download data as spreadsheets</a>
    </div>
</div>

<div class="mt-6 rounded-lg bg-white p-4 shadow-sm">
    <div class="text-sm font-medium mb-2">What's currently in the system (what a reset would clear)</div>
    <div class="grid grid-cols-2 md:grid-cols-4 gap-x-4 gap-y-1 text-xs text-slate-600">
        @foreach ($rowCounts as $table => $count)
            <div class="flex justify-between border-b border-slate-100 py-1">
                <span>{{ str_replace('_', ' ', $table) }}</span>
                <span class="font-medium {{ $count > 0 ? 'text-slate-800' : 'text-slate-300' }}">{{ number_format($count) }}</span>
            </div>
        @endforeach
    </div>
    <p class="mt-3 text-xs text-slate-500">Not cleared by a reset, ever: staff logins &amp; roles, departments, the room list, room categories, financial accounts (Cash/Bank/MTN/Orange), menu items, products/suppliers, and every setting. A reset is for starting the day-to-day history over, not for undoing your setup.</p>
</div>

<div class="mt-6 rounded-lg border-2 border-red-300 bg-white p-4 shadow-sm space-y-3">
    <div class="text-sm font-semibold text-red-700">3. Reset — permanently clear all of the above</div>
    <p class="text-xs text-slate-600">This deletes every guest, reservation, stay, payment, expense, POS order, and every other piece of day-to-day history listed above — permanently. It does not touch staff logins, roles, rooms, or financial accounts, so the system is ready to use again immediately afterward.</p>

    <form method="POST" action="{{ route('admin.system-data.reset') }}" class="space-y-3" onsubmit="return confirm('This permanently deletes all guest, reservation, and payment history. Are you absolutely sure?');">
        @csrf
        <label class="flex items-start gap-2 text-xs text-slate-700">
            <input type="checkbox" name="has_backup" value="1" required class="mt-0.5">
            <span>I have downloaded a backup (option 1 above) and I understand this cannot be undone.</span>
        </label>
        <div>
            <label class="block text-xs font-medium text-slate-600 mb-1">Type <code class="rounded bg-slate-100 px-1">{{ $confirmationPhrase }}</code> to confirm:</label>
            <input type="text" name="confirmation" required autocomplete="off" placeholder="{{ $confirmationPhrase }}" class="w-full max-w-sm rounded border border-red-300 px-3 py-2 text-sm">
        </div>
        <button class="rounded bg-red-700 px-4 py-2 text-sm text-white hover:bg-red-600">Permanently reset all data</button>
    </form>
</div>

<div class="mt-6 rounded-lg border-2 border-blue-300 bg-white p-4 shadow-sm space-y-4">
    <div>
        <div class="text-sm font-semibold text-blue-800">4. Import — restore from a previous backup</div>
        <p class="text-xs text-slate-600 mt-1">Restores guest/reservation/payment history from a file produced by option 1 or option 2 above. Only works into an empty system — use this right after Reset (option 3), or on a brand-new installation that hasn't taken any guests yet. It never touches staff logins, roles, rooms, or financial accounts.</p>
    </div>

    @if (! $operationalTablesAreEmpty)
        <div class="rounded border border-amber-300 bg-amber-50 p-3 text-xs text-amber-800">
            This system already has guest/reservation/payment data in it, so Import is disabled right now. Take a backup (option 1) if you haven't, use Reset (option 3), then come back here to Import.
        </div>
    @else
        <p class="text-xs text-slate-500">
            <span class="font-medium">Before you import onto a different server:</span> a restored row's room, staff, and financial-account references only resolve correctly if this installation's rooms/staff/financial accounts already have the exact same ids the backup was taken from — true automatically when you Backup → Reset → Import on this same installation, but not guaranteed on a different, independently set-up one. Every import runs a data-integrity check immediately afterward and shows you anything that doesn't line up.
        </p>

        <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
            <form method="POST" action="{{ route('admin.system-data.import-backup') }}" enctype="multipart/form-data" class="space-y-2 rounded border border-slate-200 p-3" onsubmit="return confirm('This will populate the system from the uploaded backup file. Continue?');">
                @csrf
                <div class="text-xs font-medium text-slate-700">From a full backup file (.json)</div>
                <input type="file" name="backup_file" accept=".json,application/json" required class="block w-full text-xs">
                <label class="flex items-start gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="confirm_import" value="1" required class="mt-0.5">
                    <span>I understand this will populate the system with the uploaded data.</span>
                </label>
                <button class="rounded bg-blue-700 px-3 py-1.5 text-sm text-white hover:bg-blue-600">Import from backup file</button>
            </form>

            <form method="POST" action="{{ route('admin.system-data.import-csv') }}" enctype="multipart/form-data" class="space-y-2 rounded border border-slate-200 p-3" onsubmit="return confirm('This will populate the system from the uploaded spreadsheet export. Continue?');">
                @csrf
                <div class="text-xs font-medium text-slate-700">From a spreadsheet export zip (.zip)</div>
                <input type="file" name="csv_zip" accept=".zip" required class="block w-full text-xs">
                <label class="flex items-start gap-2 text-xs text-slate-700">
                    <input type="checkbox" name="confirm_import" value="1" required class="mt-0.5">
                    <span>I understand this will populate the system with the uploaded data.</span>
                </label>
                <button class="rounded bg-blue-700 px-3 py-1.5 text-sm text-white hover:bg-blue-600">Import from spreadsheet export</button>
            </form>
        </div>
    @endif
</div>

@endsection
