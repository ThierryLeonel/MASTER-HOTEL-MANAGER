@extends('layouts.app')
@section('title', 'Sync Status')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <div>
        <h1 class="text-lg font-semibold">Sync Status</h1>
        <p class="text-xs text-slate-500">Registered counterpart installations (see <code>php artisan sync:register-device</code>), when each last successfully sent/received a batch, and any conflicts waiting on a decision. This page is read-only — the actual push/pull run is <code>php artisan sync:run</code>, normally on a schedule.</p>
    </div>
    @if ($totalPendingConflicts > 0)
        <a href="{{ route('admin.sync.conflicts') }}" class="rounded bg-amber-600 px-3 py-1.5 text-sm text-white hover:bg-amber-700">{{ $totalPendingConflicts }} conflict(s) need attention</a>
    @endif
</div>

<div class="overflow-hidden rounded-lg bg-white shadow-sm mb-6">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Device</th>
                <th class="p-3">Endpoint</th>
                <th class="p-3">Status</th>
                <th class="p-3">Last seen</th>
                <th class="p-3">Last sent (outbound)</th>
                <th class="p-3">Last received (inbound)</th>
                <th class="p-3">Pending conflicts</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($devices as $row)
                <tr>
                    <td class="p-3 font-medium">{{ $row['device']->name }}</td>
                    <td class="p-3 text-slate-500">{{ $row['device']->endpoint_url ?? '— (receive-only from here)' }}</td>
                    <td class="p-3">
                        @if ($row['device']->is_active)
                            <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-800">Active</span>
                        @else
                            <span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">Revoked</span>
                        @endif
                    </td>
                    <td class="p-3 text-slate-500">{{ $row['device']->last_seen_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="p-3 text-slate-500">{{ $row['outbound']?->last_synced_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="p-3 text-slate-500">{{ $row['inbound']?->last_synced_at?->diffForHumans() ?? 'never' }}</td>
                    <td class="p-3">
                        @if ($row['pending_conflicts'] > 0)
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">{{ $row['pending_conflicts'] }}</span>
                        @else
                            <span class="text-slate-400">0</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="7">No sync devices registered yet — run <code>php artisan sync:register-device</code> to set one up.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="overflow-hidden rounded-lg bg-white shadow-sm">
    <div class="border-b border-slate-100 p-3 text-xs font-semibold uppercase text-slate-500">Recent activity</div>
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">When</th><th class="p-3">Device</th><th class="p-3">Level</th><th class="p-3">Event</th><th class="p-3">Message</th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($recentLogs as $log)
                <tr>
                    <td class="p-3 text-slate-500">{{ $log->created_at->diffForHumans() }}</td>
                    <td class="p-3">{{ $log->device->name ?? '—' }}</td>
                    <td class="p-3">
                        <span @class([
                            'rounded px-2 py-0.5 text-xs',
                            'bg-red-100 text-red-800' => $log->level === 'error',
                            'bg-amber-100 text-amber-800' => $log->level === 'warning',
                            'bg-slate-100 text-slate-600' => $log->level === 'info',
                        ])>{{ $log->level }}</span>
                    </td>
                    <td class="p-3 text-slate-500">{{ $log->event }}</td>
                    <td class="p-3">{{ $log->message }}</td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="5">No sync activity recorded yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
