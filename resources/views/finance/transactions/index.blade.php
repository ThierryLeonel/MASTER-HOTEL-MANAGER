@extends('layouts.app')
@section('title', 'All Transactions')
@section('content')

<div class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
    Owner-only. Every payment, refund, expense payout, disbursement, and payroll payout in the system flows
    through this one ledger. "Voiding" a transaction here never rewrites its original amount or any other
    field — it marks the row so it stops counting toward account balances, reconciliation, and every report,
    while staying visible here for the audit trail. It does not automatically reopen or revert whatever
    business record it came from (an Expense, a POS order, a payslip) — that stays a separate, module-specific
    action if one is needed.
</div>

<div class="mb-4 flex flex-wrap items-end gap-2">
    <form method="GET" class="flex flex-wrap gap-2">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Payment #, reference, notes, income source…"
               class="rounded border border-slate-300 px-3 py-1.5 text-sm w-72">
        <select name="source_module" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">All modules</option>
            @foreach ($sourceModules as $module)
                <option value="{{ $module }}" @selected(request('source_module') === $module)>{{ str_replace('_', ' ', ucfirst($module)) }}</option>
            @endforeach
        </select>
        <label class="flex items-center gap-1 text-xs text-slate-600">
            <input type="checkbox" name="voided_only" value="1" onchange="this.form.submit()" @checked(request()->boolean('voided_only'))>
            Voided only
        </label>
        <button class="rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Filter</button>
    </form>
</div>

<div class="overflow-x-auto rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Date</th>
                <th class="p-3">Payment #</th>
                <th class="p-3">Module</th>
                <th class="p-3">Guest / reference</th>
                <th class="p-3">Account</th>
                <th class="p-3 text-right">Amount</th>
                <th class="p-3">By</th>
                <th class="p-3">Status</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($transactions as $payment)
                <tr class="{{ $payment->isVoided() ? 'opacity-50' : '' }}">
                    <td class="p-3">{{ $payment->recorded_at->format('d M Y H:i') }}</td>
                    <td class="p-3">{{ $payment->payment_number }}</td>
                    <td class="p-3">
                        {{ str_replace('_', ' ', ucfirst($payment->source_module)) }}
                        <span class="rounded {{ $payment->direction === 'in' ? 'bg-green-100 text-green-700' : 'bg-red-100 text-red-700' }} px-1.5 py-0.5 text-[10px] ml-1">{{ $payment->direction }}</span>
                    </td>
                    <td class="p-3">{{ $payment->guest->full_name ?? $payment->source_reference ?? $payment->income_source ?? '—' }}</td>
                    <td class="p-3">{{ $payment->financialAccount->name ?? str_replace('_', ' ', ucfirst($payment->method)) }}</td>
                    <td class="p-3 text-right">{{ number_format($payment->amount, 0) }} XAF</td>
                    <td class="p-3">{{ $payment->recordedBy->name ?? '—' }}</td>
                    <td class="p-3">
                        @if ($payment->isVoided())
                            <span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600" title="{{ $payment->void_reason }}">
                                Voided {{ $payment->voided_at->format('d M Y') }}@if ($payment->voidedBy) by {{ $payment->voidedBy->name }}@endif
                            </span>
                        @else
                            <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Active</span>
                        @endif
                    </td>
                    <td class="p-3">
                        @if (! $payment->isVoided())
                            <form method="POST" action="{{ route('transactions.void', $payment) }}" onsubmit="return promptVoidReason(this)" class="inline">
                                @csrf
                                <input type="hidden" name="reason" value="">
                                <button type="submit" class="text-red-600 hover:underline text-xs">Void</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="9">No transactions found.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $transactions->links() }}</div>

<script>
function promptVoidReason(form) {
    const reason = prompt('Reason for voiding this transaction? This does not delete or change the original record — it stops it from counting toward balances/reports and can be seen in the audit log.');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
