@extends('layouts.app')
@section('title', 'Leave')
@section('content')
<div class="mb-4">
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Employee</th><th class="p-3">Type</th><th class="p-3">Dates</th><th class="p-3 text-right">Days</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($requests as $leave)
                    <tr>
                        <td class="p-3">{{ $leave->user->name }}</td>
                        <td class="p-3">{{ $leave->leaveType->name }}</td>
                        <td class="p-3">{{ $leave->start_date->format('d M') }} – {{ $leave->end_date->format('d M Y') }}</td>
                        <td class="p-3 text-right">{{ $leave->days_requested }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $leave->status }}</span></td>
                        <td class="p-3 space-x-2 whitespace-nowrap">
                            @if ($leave->isPending())
                                @can('approve-leave')
                                    <form method="POST" action="{{ route('hr.leave-requests.approve', $leave) }}" class="inline">
                                        @csrf
                                        <button class="text-green-700 hover:underline text-xs">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('hr.leave-requests.reject', $leave) }}" onsubmit="return promptReason(this)" class="inline">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button class="text-red-600 hover:underline text-xs">Reject</button>
                                    </form>
                                @endcan
                                @if ($leave->user_id === auth()->id())
                                    <form method="POST" action="{{ route('hr.leave-requests.cancel', $leave) }}" class="inline">
                                        @csrf
                                        <button class="text-slate-500 hover:underline text-xs">Cancel</button>
                                    </form>
                                @endif
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="6">No leave requests yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $requests->links() }}
    </div>

    <div class="col-span-1 space-y-3">
        @can('request-leave')
            <form method="POST" action="{{ route('hr.leave-requests.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Request leave</div>
                <select name="leave_type_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Leave type…</option>
                    @foreach ($leaveTypes as $type)
                        <option value="{{ $type->id }}">{{ $type->name }}</option>
                    @endforeach
                </select>
                <input type="date" name="start_date" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input type="date" name="end_date" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input name="reason" placeholder="Reason" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Submit request</button>
            </form>
        @endcan

        @can('manage-employees')
            <form method="POST" action="{{ route('hr.leave-types.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Add leave type</div>
                <input name="name" placeholder="Name (e.g. Annual Leave)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input name="code" placeholder="Code (e.g. ANNUAL)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input type="number" min="0" name="max_days_per_year" placeholder="Days per year (0 = unlimited)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="is_paid" value="1" checked> Paid</label>
                <label class="flex items-center gap-2 text-xs"><input type="checkbox" name="requires_approval" value="1" checked> Requires approval</label>
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Save</button>
            </form>

            <div class="rounded-lg bg-white p-4 shadow-sm">
                <div class="text-xs font-medium mb-2">Leave types</div>
                <ul class="space-y-2 text-xs">
                    @foreach ($allLeaveTypes as $type)
                        <li class="border-b border-slate-100 pb-2 last:border-0">
                            <div class="flex items-center justify-between">
                                <span>{{ $type->name }} ({{ $type->code }})</span>
                                <details class="inline-block">
                                    <summary class="cursor-pointer text-slate-600 underline">Edit</summary>
                                    <form method="POST" action="{{ route('hr.leave-types.update', $type) }}" class="mt-2 space-y-1">
                                        @csrf @method('PUT')
                                        <input name="name" value="{{ $type->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <input name="code" value="{{ $type->code }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <input type="number" min="0" name="max_days_per_year" value="{{ $type->max_days_per_year }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <label class="flex items-center gap-1"><input type="checkbox" name="is_paid" value="1" @checked($type->is_paid)> Paid</label>
                                        <label class="flex items-center gap-1"><input type="checkbox" name="requires_approval" value="1" @checked($type->requires_approval)> Requires approval</label>
                                        <label class="flex items-center gap-1"><input type="checkbox" name="is_active" value="1" @checked($type->is_active)> Active</label>
                                        <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('hr.leave-types.destroy', $type) }}" class="mt-1"
                                          onsubmit="return confirm(@js('Delete leave type '.$type->name.' permanently? Only a type with no request/balance history can be deleted — otherwise deactivate it instead.'));">
                                        @csrf @method('DELETE')
                                        <button class="text-red-700 hover:underline">Delete</button>
                                    </form>
                                </details>
                            </div>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endcan
    </div>
</div>

<script>
function promptReason(form) {
    const reason = prompt('Rejection reason:');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
