@extends('layouts.app')
@section('title', 'Shifts')
@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Shift history</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Shift #</th><th class="p-3">Department</th><th class="p-3">Opened</th>
                        <th class="p-3">Status</th><th class="p-3 text-right">Opening</th><th class="p-3 text-right">Expected</th><th class="p-3 text-right">Declared</th>
                        <th class="p-3 text-right">Variance</th><th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($shifts as $shift)
                        <tr>
                            <td class="p-3">{{ $shift->shift_number }}</td>
                            <td class="p-3">{{ $shift->department->name }}</td>
                            <td class="p-3 text-xs">{{ $shift->opened_at->format('d M H:i') }}<div class="text-slate-500">{{ $shift->openedBy->name }}</div></td>
                            <td class="p-3">
                                @if ($shift->isOpen())
                                    <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Open</span>
                                @else
                                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">Closed</span>
                                @endif
                            </td>
                            <td class="p-3 text-right">{{ $shift->opening_cash !== null ? number_format($shift->opening_cash, 0) : '—' }}</td>
                            <td class="p-3 text-right">
                                @if ($shift->isOpen())
                                    {{-- "Let the expected balance per the shift be visibly
                                         changing as the day and transactions are moving" —
                                         polled every 20s from ShiftController::expected(). --}}
                                    <span class="live-expected-cash" data-shift-id="{{ $shift->id }}">…</span>
                                @else
                                    {{ $shift->expected_cash !== null ? number_format($shift->expected_cash, 0) : '—' }}
                                @endif
                            </td>
                            <td class="p-3 text-right">{{ $shift->declared_cash !== null ? number_format($shift->declared_cash, 0) : '—' }}</td>
                            <td class="p-3 text-right {{ $shift->variance === null ? '' : ($shift->variance == 0 ? '' : ($shift->variance > 0 ? 'text-green-700' : 'text-red-600')) }}">
                                {{ $shift->variance !== null ? number_format($shift->variance, 0) : '—' }}
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                @if ($shift->isOpen())
                                    @can('manage-own-shift')
                                        <form method="POST" action="{{ route('finance.shifts.close', $shift) }}" onsubmit="return promptDeclaredCash(this)" class="inline">
                                            @csrf
                                            <input type="hidden" name="declared_cash" value="">
                                            <button class="text-xs text-green-700 hover:underline">Close &amp; declare cash</button>
                                        </form>
                                    @endcan
                                @else
                                    <a href="{{ route('finance.shifts.print', $shift) }}" target="_blank" class="text-xs text-blue-700 hover:underline">Print</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="9">No shifts recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $shifts->links() }}
        </div>
    </div>

    <div class="lg:col-span-1 hms-side-panel">
        @can('manage-own-shift')
            {{-- Post-9.8 third fix round: the department is no longer a
                 dropdown — it's always the logged-in user's own department
                 (PHASE9_AUDIT_AND_PLAN.md §21). --}}
            <div class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                <div class="text-xs font-medium">Open a shift</div>
                @if ($ownDepartment)
                    <div class="text-sm text-slate-700">For <span class="font-semibold">{{ $ownDepartment->name }}</span> — your own department.</div>
                    <form method="POST" action="{{ route('finance.shifts.store') }}" class="space-y-2">
                        @csrf
                        {{--
                            "When a shift is been open we should declare the
                            opening balance, so that the system can properly
                            calculate the expected closing and to know the
                            variance at the end" — the till's starting float,
                            counted before the first transaction of the shift.
                        --}}
                        <label class="block text-xs text-slate-600">
                            Opening cash (counted before the first sale)
                            <input type="number" step="0.01" min="0" name="opening_cash" placeholder="0" required class="mt-1 w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        </label>
                        <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                        <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Open shift</button>
                    </form>
                @else
                    <div class="text-sm text-red-600">Your account has no department assigned — ask an administrator to set one before you can open a shift.</div>
                @endif
            </div>
        @endcan
    </div>
</div>

<script>
function promptDeclaredCash(form) {
    const amount = prompt('Cash counted and being handed in for this shift (XAF):');
    if (amount === null || amount.trim() === '' || isNaN(parseFloat(amount))) return false;
    form.declared_cash.value = amount;
    return true;
}

// "Let the expected balance per the shift be visibly changing as the day
// and transactions are moving" — this app has no build step/Livewire (see
// layouts/app.blade.php), so a small plain-JS poll against
// ShiftController::expected() is the simplest way to keep an open shift's
// figure current without a full page reload. 20s is frequent enough to
// feel "live" without hammering the server on a busy shifts list.
(function () {
    const spans = document.querySelectorAll('.live-expected-cash');
    if (spans.length === 0) return;

    function refresh() {
        spans.forEach((span) => {
            fetch(`/finance/shifts/${span.dataset.shiftId}/expected`, { headers: { 'Accept': 'application/json' } })
                .then((r) => r.ok ? r.json() : Promise.reject())
                .then((data) => {
                    span.textContent = Number(data.expected_cash).toLocaleString();
                })
                .catch(() => { span.textContent = '—'; });
        });
    }

    refresh();
    setInterval(refresh, 20000);
})();
</script>
@endsection
