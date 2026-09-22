@extends('layouts.app')
@section('title', 'Client Debt (Accounts Receivable)')
@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <form method="GET" class="flex gap-2">
            <select name="status" class="rounded border border-slate-300 px-3 py-2 text-sm">
                <option value="">Open + partially paid (default)</option>
                <option value="open" @selected(request('status') === 'open')>Open only</option>
                <option value="partially_paid" @selected(request('status') === 'partially_paid')>Partially paid only</option>
                <option value="paid" @selected(request('status') === 'paid')>Paid (settled)</option>
            </select>
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Filter</button>
        </form>

        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Outstanding debt</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Who owes</th><th class="p-3">Due</th>
                        <th class="p-3 text-right">Original</th><th class="p-3 text-right">Outstanding</th>
                        <th class="p-3">Status</th><th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($receivables as $ar)
                        <tr>
                            <td class="p-3">
                                {{ $ar->guest?->full_name ?? '—' }}
                                @if ($ar->company)
                                    <div class="text-xs text-slate-500">Billed to: {{ $ar->company->name }}</div>
                                @endif
                            </td>
                            <td class="p-3 text-xs">{{ $ar->due_date?->format('d M Y') ?? '—' }}</td>
                            <td class="p-3 text-right">{{ number_format($ar->original_amount, 0) }}</td>
                            <td class="p-3 text-right font-medium">{{ number_format($ar->amount_outstanding, 0) }}</td>
                            <td class="p-3">
                                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs capitalize">{{ str_replace('_', ' ', $ar->status) }}</span>
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                @can('manage-accounts-receivable')
                                    @if ($ar->isSettleable())
                                        <form method="POST" action="{{ route('finance.accounts-receivable.settle', $ar) }}" onsubmit="return promptSettle(this, {{ $ar->amount_outstanding }})" class="inline flex gap-1">
                                            @csrf
                                            <input type="hidden" name="amount" value="">
                                            <select name="financial_account_id" required class="rounded border border-slate-300 px-1 py-1 text-xs">
                                                <option value="">Into…</option>
                                                @foreach ($financialAccounts as $account)
                                                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                                                @endforeach
                                            </select>
                                            <button class="text-xs text-green-700 hover:underline">Record payment</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="6">No outstanding debt.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $receivables->links() }}
        </div>
    </div>

    <div class="lg:col-span-1">
        <div class="rounded-lg bg-white p-4 shadow-sm">
            <div class="text-xs font-medium uppercase tracking-wide text-slate-500 mb-2">Companies owing money</div>
            <ul class="space-y-2 text-sm">
                @forelse ($companies->where('outstanding_sum', '>', 0) as $company)
                    <li class="flex justify-between">
                        <span>{{ $company->name }}</span>
                        <span class="font-medium">{{ number_format($company->outstanding_sum, 0) }}</span>
                    </li>
                @empty
                    <li class="text-slate-500">No company currently owes money.</li>
                @endforelse
            </ul>
            <a href="{{ route('companies.index') }}" class="mt-3 block text-xs text-blue-700 hover:underline">Manage companies</a>
        </div>
    </div>
</div>

<script>
function promptSettle(form, outstanding) {
    const amount = prompt('Amount being paid now (XAF) — outstanding balance is ' + outstanding + ':');
    if (amount === null || amount.trim() === '' || isNaN(parseFloat(amount))) return false;
    form.amount.value = amount;
    return true;
}
</script>
@endsection
