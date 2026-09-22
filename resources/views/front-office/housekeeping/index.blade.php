@extends('layouts.app')
@section('title', 'Housekeeping')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Room</th><th class="p-3">Type</th><th class="p-3">Status</th><th class="p-3">Approval</th><th class="p-3">Assigned</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($tasks as $task)
                    <tr>
                        <td class="p-3">{{ $task->room->room_number }}</td>
                        <td class="p-3">{{ $task->type }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $task->status }}</span></td>
                        <td class="p-3">
                            @if ($task->approval_status !== 'not_required')
                                <span class="rounded px-2 py-0.5 text-xs {{ $task->approval_status === 'approved' ? 'bg-green-100 text-green-700' : ($task->approval_status === 'rejected' ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') }}">{{ $task->approval_status }}</span>
                            @else
                                <span class="text-xs text-slate-400">—</span>
                            @endif
                        </td>
                        <td class="p-3">{{ $task->assignedTo->name ?? '—' }}</td>
                        <td class="p-3 space-x-2">
                            @can('approve-housekeeping')
                                @if ($task->approval_status === 'pending')
                                    <form method="POST" action="{{ route('housekeeping.approve', $task) }}" class="inline">
                                        @csrf
                                        <button class="text-green-700 hover:underline">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('housekeeping.reject', $task) }}" class="inline" onsubmit="return confirm('Reject this task?');">
                                        @csrf
                                        <input type="hidden" name="reason" value="Rejected by approver">
                                        <button class="text-red-700 hover:underline">Reject</button>
                                    </form>
                                @endif
                            @endcan
                            @if ($task->status === 'pending' && $task->approval_status !== 'pending' && $task->approval_status !== 'rejected')
                                <form method="POST" action="{{ route('housekeeping.start', $task) }}" class="inline">
                                    @csrf
                                    <button class="text-slate-600 hover:underline">Start</button>
                                </form>
                            @elseif ($task->status === 'in_progress')
                                <form method="POST" action="{{ route('housekeeping.complete', $task) }}" class="inline">
                                    @csrf
                                    <button class="text-green-700 hover:underline">Complete</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="6">No pending housekeeping tasks.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    <div class="col-span-1">
        <form method="POST" action="{{ route('housekeeping.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">New task</div>
            <select name="room_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Select room…</option>
                @foreach ($rooms as $room)
                    <option value="{{ $room->id }}">{{ $room->room_number }}</option>
                @endforeach
            </select>
            <select name="type" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="cleaning">Cleaning</option>
                <option value="turnover">Turnover</option>
                <option value="inspection">Inspection</option>
            </select>
            <label class="flex items-center gap-1.5 text-xs text-slate-600">
                <input type="checkbox" name="requires_approval" value="1">
                Requires approval before staff can start it
            </label>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create task</button>
        </form>
    </div>
</div>
@endsection
