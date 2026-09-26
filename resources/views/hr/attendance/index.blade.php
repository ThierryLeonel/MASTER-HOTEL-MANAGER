@extends('layouts.app')
@section('title', 'Attendance')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 space-y-4">
        <form method="GET" class="flex gap-2">
            <input type="date" name="date" value="{{ $date->toDateString() }}" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
        </form>

        @can('record-attendance')
            <div class="rounded-lg bg-white shadow-sm">
                <table class="w-full text-sm">
                    <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                        <tr><th class="p-3">Employee</th><th class="p-3">Clock in</th><th class="p-3">Clock out</th><th class="p-3">Status</th><th class="p-3">Recorded by</th></tr>
                    </thead>
                    <tbody class="divide-y">
                        @forelse ($records as $record)
                            <tr>
                                <td class="p-3">{{ $record->user->name }}</td>
                                <td class="p-3">{{ $record->clock_in_at?->format('H:i') ?? '—' }}</td>
                                <td class="p-3">{{ $record->clock_out_at?->format('H:i') ?? '—' }}</td>
                                <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ str_replace('_', ' ', $record->status) }}</span></td>
                                <td class="p-3">{{ $record->recordedBy->name ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td class="p-3 text-slate-500" colspan="5">No attendance records for {{ $date->toDateString() }}.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @else
            <div class="rounded-lg bg-white p-4 shadow-sm text-sm text-slate-500">
                Only a manager can view the full attendance roster. Use "My attendance today" to clock yourself in or out.
            </div>
        @endcan
    </div>

    <div class="col-span-1 space-y-3 hms-side-panel">
        <div class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            <div class="text-xs font-medium">My attendance today</div>
            @if (! $myToday || ! $myToday->clock_in_at)
                <form method="POST" action="{{ route('hr.attendance.clock-in') }}">
                    @csrf
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Clock in</button>
                </form>
            @elseif (! $myToday->clock_out_at)
                <p class="text-xs text-slate-500">Clocked in at {{ $myToday->clock_in_at->format('H:i') }}.</p>
                <form method="POST" action="{{ route('hr.attendance.clock-out') }}">
                    @csrf
                    <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Clock out</button>
                </form>
            @else
                <p class="text-xs text-slate-500">Done for today: {{ $myToday->clock_in_at->format('H:i') }} – {{ $myToday->clock_out_at->format('H:i') }}.</p>
            @endif
        </div>

        @can('record-attendance')
            <form method="POST" action="{{ route('hr.attendance.manual.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Record attendance manually</div>
                <select name="user_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Staff…</option>
                    @foreach ($staff as $user)
                        <option value="{{ $user->id }}">{{ $user->name }}</option>
                    @endforeach
                </select>
                <input type="date" name="work_date" value="{{ $date->toDateString() }}" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <select name="status" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="present">Present</option>
                    <option value="absent">Absent</option>
                    <option value="late">Late</option>
                    <option value="holiday">Holiday</option>
                </select>
                <input name="notes" placeholder="Notes (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Record</button>
            </form>
        @endcan
    </div>
</div>
@endsection
