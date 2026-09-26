@extends('layouts.app')
@section('title', 'Expenses')
@section('content')
<div class="mb-4 flex justify-between items-center">
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (['pending', 'approved', 'rejected', 'paid', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
    <div class="text-xs text-slate-500">Approval threshold: <strong>{{ number_format($threshold, 0) }} XAF</strong> — at or above this needs GM/Owner/Accountant sign-off.</div>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Expense #</th><th class="p-3">Category</th><th class="p-3 text-right">Amount</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($expenses as $expense)
                    <tr class="{{ (float) $expense->amount >= $threshold ? 'bg-amber-50' : '' }}">
                        <td class="p-3">{{ $expense->expense_number }}</td>
                        <td class="p-3">{{ $expense->category }}<div class="text-xs text-slate-500">{{ $expense->description }}</div></td>
                        <td class="p-3 text-right">{{ number_format($expense->amount, 0) }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $expense->status }}</span></td>
                        <td class="p-3 space-x-2 whitespace-nowrap">
                            @if ($expense->status === 'pending')
                                @can('approve-expense')
                                    <form method="POST" action="{{ route('finance.expenses.approve', $expense) }}" class="inline">
                                        @csrf
                                        <button class="text-green-700 hover:underline text-xs">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('finance.expenses.reject', $expense) }}" onsubmit="return promptReason(this)" class="inline">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button class="text-red-600 hover:underline text-xs">Reject</button>
                                    </form>
                                @endcan
                            @elseif ($expense->status === 'approved')
                                @can('pay-expense')
                                    <form method="POST" action="{{ route('finance.expenses.pay', $expense) }}" class="inline flex gap-1">
                                        @csrf
                                        {{-- Post-9.8 third fix round: which specific account the money is
                                             leaving from, not just a broad method — PHASE9_AUDIT_AND_PLAN.md §21. --}}
                                        <select name="financial_account_id" required class="rounded border border-slate-300 px-1 py-1 text-xs">
                                            <option value="">Pay from…</option>
                                            @foreach ($financialAccounts as $account)
                                                <option value="{{ $account->id }}">{{ $account->name }}</option>
                                            @endforeach
                                        </select>
                                        <button class="text-green-700 hover:underline text-xs">Mark paid</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="5">No expenses recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $expenses->links() }}
    </div>

    <div class="col-span-1">
        @can('manage-expenses')
            <form method="POST" action="{{ route('finance.expenses.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Request an expense</div>
                {{--
                    "Correct the financial linking ... so we do not have
                    double records": the department set here is what later
                    lets this expense's eventual payment (ExpenseService::
                    markPaid()) attribute to that department's open shift,
                    same reasoning as the disbursement form. Pre-selected to
                    the requester's own department but still changeable.
                --}}
                <select name="department_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">No specific department…</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}" @selected(auth()->user()->department_id === $department->id)>{{ $department->name }}</option>
                    @endforeach
                </select>
                <input name="category" placeholder="Category (e.g. Utilities, Supplies)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <textarea name="description" placeholder="Description" required rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Submit for approval</button>
            </form>
        @endcan
    </div>
</div>

<script>
function promptReason(form) {
    const reason = prompt('Reason:');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
