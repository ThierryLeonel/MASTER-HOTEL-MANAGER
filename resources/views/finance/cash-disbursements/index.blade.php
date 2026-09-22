@extends('layouts.app')
@section('title', 'Cash Disbursements')
@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">

        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Disbursement history</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Voucher #</th><th class="p-3">Department</th><th class="p-3">Paid to</th>
                        <th class="p-3 text-right">Amount</th><th class="p-3">Authorization</th><th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($disbursements as $disbursement)
                        <tr>
                            <td class="p-3">{{ $disbursement->disbursement_number }}<div class="text-xs text-slate-500">{{ $disbursement->disbursed_at->format('d M Y H:i') }}</div></td>
                            <td class="p-3">{{ $disbursement->department->name ?? '—' }}</td>
                            <td class="p-3">{{ $disbursement->paid_to }}<div class="text-xs text-slate-500">{{ $disbursement->purpose }}</div></td>
                            <td class="p-3 text-right">{{ number_format($disbursement->amount, 0) }}</td>
                            <td class="p-3 text-xs">{{ $disbursement->authorizationLabel() }}</td>
                            <td class="p-3"><a href="{{ route('finance.cash-disbursements.print', $disbursement) }}" target="_blank" class="text-xs text-blue-700 hover:underline">Print</a></td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="6">No cash disbursements recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $disbursements->links() }}
        </div>

        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Disbursement tickets (authorizations)</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Ticket #</th><th class="p-3">Purpose</th><th class="p-3 text-right">Amount</th><th class="p-3">Status</th><th class="p-3">Authorized by</th><th class="p-3"></th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($tickets as $ticket)
                        <tr>
                            <td class="p-3">{{ $ticket->ticket_number }}</td>
                            <td class="p-3">{{ $ticket->purpose }}</td>
                            <td class="p-3 text-right">{{ number_format($ticket->amount, 0) }}</td>
                            <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $ticket->status }}</span></td>
                            <td class="p-3 text-xs">{{ $ticket->authorizedBy->name }}</td>
                            <td class="p-3">
                                @if ($ticket->isOpen())
                                    @can('authorize-cash-disbursement')
                                        <form method="POST" action="{{ route('finance.disbursement-tickets.cancel', $ticket) }}" onsubmit="return promptCancelReason(this)" class="inline">
                                            @csrf
                                            <input type="hidden" name="reason" value="">
                                            <button class="text-red-600 hover:underline text-xs">Cancel</button>
                                        </form>
                                    @endcan
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="6">No tickets raised yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="lg:col-span-1 space-y-6">
        @can('authorize-cash-disbursement')
            <form method="POST" action="{{ route('finance.disbursement-tickets.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Raise a disbursement ticket</div>
                <p class="text-xs text-slate-500">The normal path: authorize a cash-out in advance. Whoever records the disbursement then just picks this ticket.</p>
                <select name="department_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Department (optional)…</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
                <input name="purpose" placeholder="Purpose" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input type="number" step="0.01" min="0.01" name="amount" placeholder="Authorized amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create ticket</button>
            </form>
        @endcan

        @can('record-cash-disbursement')
            <form method="POST" action="{{ route('finance.cash-disbursements.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Record a cash disbursement</div>

                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" onchange="document.getElementById('ticket-field').classList.toggle('hidden', !this.checked); document.getElementById('no-ticket-fields').classList.toggle('hidden', this.checked);">
                    Against an existing ticket
                </label>

                <div id="ticket-field" class="hidden">
                    <select name="ticket_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">Select ticket…</option>
                        @foreach ($openTickets as $ticket)
                            <option value="{{ $ticket->id }}">{{ $ticket->ticket_number }} — {{ $ticket->purpose }} ({{ number_format($ticket->amount, 0) }})</option>
                        @endforeach
                    </select>
                </div>

                <div id="no-ticket-fields" class="space-y-2">
                    <p class="text-xs text-slate-500">No ticket: the authorizing superior's details are required instead.</p>
                    <select name="authorized_by_user_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">Authorizing manager (if a system user)…</option>
                        @foreach ($managers as $manager)
                            <option value="{{ $manager->id }}">{{ $manager->name }}</option>
                        @endforeach
                    </select>
                    <div class="text-center text-xs text-slate-400">— or —</div>
                    <input name="authorized_by_name" placeholder="Authorizing superior's name" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <input name="authorized_by_title" placeholder="Their title (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                </div>

                <select name="department_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Department (optional)…</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
                <input name="paid_to" placeholder="Paid to" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input name="purpose" placeholder="Purpose" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input type="number" step="0.01" min="0.01" name="amount" placeholder="Amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                {{-- Post-9.8 third fix round: which specific account the money is
                     leaving from — PHASE9_AUDIT_AND_PLAN.md §21. --}}
                <select name="financial_account_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Paid from…</option>
                    @foreach ($financialAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Record disbursement</button>
            </form>
        @endcan
    </div>
</div>

<script>
function promptCancelReason(form) {
    const reason = prompt('Reason for cancelling this ticket:');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
