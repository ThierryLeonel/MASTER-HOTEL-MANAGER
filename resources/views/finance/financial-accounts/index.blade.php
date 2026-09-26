@extends('layouts.app')
@section('title', 'Financial Accounts')
@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Accounts</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Name</th><th class="p-3">Type</th>
                        <th class="p-3 text-right">Current balance</th>
                        <th class="p-3">Last reconciled</th><th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($accounts as $account)
                        <tr class="{{ $account->is_active ? '' : 'opacity-50' }}">
                            <td class="p-3">
                                {{ $account->name }}
                                <div class="text-xs text-slate-500">{{ $account->code }}</div>
                            </td>
                            <td class="p-3 capitalize">{{ str_replace('_', ' ', $account->type) }}</td>
                            <td class="p-3 text-right font-medium">{{ number_format($balances[$account->id], 0) }}</td>
                            <td class="p-3 text-xs">
                                @if ($account->latestReconciliation)
                                    {{ $account->latestReconciliation->reconciled_at->format('d M H:i') }}
                                    <div class="{{ $account->latestReconciliation->variance == 0 ? 'text-slate-500' : ($account->latestReconciliation->variance > 0 ? 'text-green-700' : 'text-red-600') }}">
                                        Variance: {{ number_format($account->latestReconciliation->variance, 0) }}
                                    </div>
                                    <a href="{{ route('finance.financial-accounts.reconciliation.print', $account->latestReconciliation) }}" target="_blank" class="text-blue-700 hover:underline">Print</a>
                                @else
                                    <span class="text-slate-400">Never</span>
                                @endif
                            </td>
                            <td class="p-3 whitespace-nowrap">
                                @can('manage-financial-accounts')
                                    <a href="{{ route('finance.financial-accounts.edit', $account) }}" class="text-xs text-slate-600 hover:underline">Edit</a>
                                    @if ($account->is_active)
                                        <form method="POST" action="{{ route('finance.financial-accounts.reconcile', $account) }}" onsubmit="return promptReconcile(this)" class="inline ml-2">
                                            @csrf
                                            <input type="hidden" name="declared_balance" value="">
                                            <button class="text-xs text-green-700 hover:underline">Reconcile</button>
                                        </form>
                                        <form method="POST" action="{{ route('finance.financial-accounts.deactivate', $account) }}" onsubmit="return confirm('Deactivate {{ $account->name }}? It stays visible in reports but can no longer be picked for new transactions.')" class="inline ml-2">
                                            @csrf
                                            <button class="text-xs text-red-600 hover:underline">Deactivate</button>
                                        </form>
                                    @else
                                        <form method="POST" action="{{ route('finance.financial-accounts.activate', $account) }}" class="inline ml-2">
                                            @csrf
                                            <button class="text-xs text-green-700 hover:underline">Reactivate</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="5">No financial accounts yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="lg:col-span-1 hms-side-panel">
        @can('manage-financial-accounts')
            <form method="POST" action="{{ route('finance.financial-accounts.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Add a financial account</div>
                <input type="text" name="name" required placeholder="Name (e.g. Second Bank Account)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input type="text" name="code" required placeholder="Short code (e.g. BANK2)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <select name="type" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Type…</option>
                    <option value="cash">Cash</option>
                    <option value="bank">Bank</option>
                    <option value="mobile_money">Mobile Money</option>
                    <option value="card">Card</option>
                </select>
                <input type="number" step="0.01" min="0" name="opening_balance" placeholder="Opening balance (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add account</button>
            </form>
        @endcan
    </div>
</div>

<script>
function promptReconcile(form) {
    const amount = prompt('What does the statement / mobile money app actually show right now (XAF)?');
    if (amount === null || amount.trim() === '' || isNaN(parseFloat(amount))) return false;
    form.declared_balance.value = amount;
    return true;
}
</script>
@endsection
