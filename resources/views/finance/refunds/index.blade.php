@extends('layouts.app')
@section('title', 'Refund Requests')
@section('content')
<div class="mb-4">
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (['pending', 'approved', 'rejected'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
</div>

<div class="overflow-x-auto rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Refund #</th><th class="p-3">Guest</th><th class="p-3 text-right">Amount</th><th class="p-3">Reason</th><th class="p-3">Status</th><th class="p-3">Paid from</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($refunds as $refund)
                <tr>
                    <td class="p-3">{{ $refund->refund_number }}</td>
                    <td class="p-3">{{ $refund->folio->stay->guest->full_name ?? '—' }}</td>
                    <td class="p-3 text-right">{{ number_format($refund->amount, 0) }}</td>
                    <td class="p-3">{{ $refund->reason }}</td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $refund->status }}</span></td>
                    <td class="p-3">{{ $refund->financialAccount->name ?? '—' }}</td>
                    <td class="p-3 space-x-2 whitespace-nowrap">
                        @if ($refund->isPending())
                            @can('approve-refund')
                                <form method="POST" action="{{ route('finance.refunds.approve', $refund) }}" class="inline flex gap-1">
                                    @csrf
                                    {{-- Sixth post-9.8 fix round: which real account the refund is
                                         actually paid back out of, not just the requester's method
                                         guess — see RefundService::approve()'s docblock. --}}
                                    <select name="financial_account_id" required class="rounded border border-slate-300 px-1 py-1 text-xs">
                                        <option value="">Pay from…</option>
                                        @foreach ($financialAccounts as $account)
                                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="text-green-700 hover:underline text-xs">Approve</button>
                                </form>
                                <form method="POST" action="{{ route('finance.refunds.reject', $refund) }}" onsubmit="return promptReason(this)" class="inline">
                                    @csrf
                                    <input type="hidden" name="reason" value="">
                                    <button class="text-red-600 hover:underline text-xs">Reject</button>
                                </form>
                            @endcan
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="7">No refund requests yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $refunds->links() }}

<script>
function promptReason(form) {
    const reason = prompt('Reason:');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
