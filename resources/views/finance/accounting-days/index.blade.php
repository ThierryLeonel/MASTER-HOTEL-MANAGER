@extends('layouts.app')
@section('title', 'Day Close & Cash Reconciliation')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 space-y-6">
        <div class="rounded-lg bg-white p-4 shadow-sm text-sm flex justify-between items-center">
            <div>
                <div class="font-semibold">{{ $today->business_date->format('l, d M Y') }}</div>
                <div class="text-slate-500">Today's accounting day</div>
            </div>
            <span class="rounded px-2 py-0.5 text-xs {{ $today->isLocked() ? 'bg-red-100 text-red-700' : 'bg-green-100 text-green-700' }}">{{ $today->status }}</span>
        </div>

        {{-- Fourth post-9.8 fix round (day-close rebuild): the real
             reconciliation — every active financial account (Cash, Bank,
             MTN, Orange…) declared against what the ledger itself expects
             for today, per account. This is what AccountingDayService::
             lock() actually checks now. --}}
        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-sm font-semibold flex items-center justify-between">
                <span>Today's account reconciliation</span>
                <span class="text-xs font-normal text-slate-500">Every active account must be reconciled here before the day can be locked</span>
            </div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Account</th>
                        <th class="p-3 text-right">Expected (from ledger)</th>
                        <th class="p-3">Status</th>
                        <th class="p-3">Declare today's count</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($accounts as $account)
                        <tr>
                            <td class="p-3">
                                {{ $account->name }}
                                <div class="text-xs text-slate-500 capitalize">{{ str_replace('_', ' ', $account->type) }}</div>
                            </td>
                            <td class="p-3 text-right font-medium">{{ number_format($balances[$account->id], 0) }} XAF</td>
                            <td class="p-3">
                                @if ($account->reconciledToday)
                                    <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Reconciled today</span>
                                @else
                                    <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">Outstanding</span>
                                @endif
                            </td>
                            <td class="p-3">
                                @can('record-cash-reconciliation')
                                    @unless ($account->reconciledToday)
                                        <form method="POST" action="{{ route('finance.accounting-days.reconcile-account', $account) }}" class="flex flex-wrap items-center gap-1">
                                            @csrf
                                            <input type="number" step="0.01" min="0" name="declared_balance" placeholder="Counted (XAF)" required class="w-32 rounded border border-slate-300 px-2 py-1 text-xs">
                                            <input type="text" name="notes" placeholder="Notes (optional)" class="w-32 rounded border border-slate-300 px-2 py-1 text-xs">
                                            <button class="rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Reconcile</button>
                                        </form>
                                    @else
                                        <span class="text-xs text-slate-400">Done for today</span>
                                    @endunless
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="4">No active financial accounts — add one under Finance → Financial Accounts first.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-sm font-semibold">Recent days</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Date</th><th class="p-3">Status</th><th class="p-3">Locked by</th></tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($recentDays as $day)
                        <tr>
                            <td class="p-3">{{ $day->business_date->format('Y-m-d') }}</td>
                            <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $day->status }}</span></td>
                            <td class="p-3">{{ $day->lockedBy->name ?? '—' }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        <details class="rounded-lg bg-white shadow-sm">
            <summary class="cursor-pointer border-b p-3 text-sm font-semibold text-slate-500">Legacy whole-hotel cash count (kept for history — no longer required to lock the day)</summary>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Date</th><th class="p-3 text-right">Expected</th><th class="p-3 text-right">Counted</th><th class="p-3 text-right">Variance</th><th class="p-3">By</th><th class="p-3"></th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($recentReconciliations as $r)
                        <tr>
                            <td class="p-3">{{ $r->business_date->format('Y-m-d') }}</td>
                            <td class="p-3 text-right">{{ number_format($r->expected_amount, 0) }}</td>
                            <td class="p-3 text-right">{{ number_format($r->counted_amount, 0) }}</td>
                            <td class="p-3 text-right {{ (float) $r->variance != 0 ? 'text-red-600' : 'text-green-700' }}">{{ number_format($r->variance, 0) }}</td>
                            <td class="p-3">{{ $r->recordedBy->name }}</td>
                            <td class="p-3"><a href="{{ route('finance.accounting-days.cash-reconciliation.print', $r) }}" class="text-xs text-slate-500 underline">Print</a></td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="6">No cash reconciliations recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            @if (! $todayReconciliation)
                @can('record-cash-reconciliation')
                    <form method="POST" action="{{ route('finance.accounting-days.cash-reconciliation.store') }}" class="p-3 space-y-2 border-t">
                        @csrf
                        <div class="text-xs text-slate-500">Optional — expected from ledger: <strong>{{ number_format($expectedCashToday, 0) }} XAF</strong></div>
                        <input type="number" step="0.01" min="0" name="counted_amount" placeholder="Amount actually counted (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                        <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Record legacy cash count</button>
                    </form>
                @endcan
            @endif
        </details>
    </div>

    <div class="col-span-1 space-y-3 hms-side-panel">
        <div class="rounded-lg bg-white p-4 shadow-sm text-sm space-y-1">
            <div class="text-xs font-medium">Reconciliation progress</div>
            @php $outstanding = $accounts->where('reconciledToday', false); @endphp
            @if ($outstanding->isEmpty())
                <p class="text-green-700">Every active account is reconciled for today.</p>
            @else
                <p class="text-amber-700">Still outstanding: {{ $outstanding->pluck('name')->implode(', ') }}.</p>
            @endif
        </div>

        @can('lock-accounting-day')
            @if (! $today->isLocked())
                <form method="POST" action="{{ route('finance.accounting-days.lock') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                    <button class="w-full rounded bg-red-700 px-3 py-1.5 text-sm text-white hover:bg-red-600">Close &amp; lock today's day</button>
                    <p class="text-xs text-slate-500">Requires every active financial account reconciled above. Once locked, no further payments can be recorded today — this cannot be undone; corrections go on the next open day.</p>
                </form>
            @endif
        @endcan
    </div>
</div>
@endsection
