@extends('layouts.app')
@section('title', 'Payments')
@section('content')

<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    <div class="lg:col-span-1">
        <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
            <h2 class="text-sm font-semibold">Record a payment</h2>
            <p class="text-xs text-slate-500">For a guest's room or POS bill, use that guest's folio or the POS instead — this is for anything else (rent, a one-off visitor payment, etc.).</p>
            <form method="POST" action="{{ route('payments.store') }}" class="space-y-3">
                @csrf
                <input type="hidden" name="guest_id" id="guest_id">
                <div class="relative">
                    <label class="block text-sm font-medium mb-1">Guest (optional)</label>
                    <input type="text" id="guest_search" autocomplete="off" placeholder="Search an existing guest…" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <div id="guest_results" class="absolute z-10 mt-1 hidden w-full rounded border border-slate-300 bg-white text-sm shadow-lg"></div>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Payer name (if not a guest)</label>
                    <input type="text" name="payer_name" id="payer_name" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Amount (XAF)</label>
                    <input type="number" step="0.01" name="amount" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Received into</label>
                    <select name="financial_account_id" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Choose an account…</option>
                        @foreach ($financialAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">What is this for?</label>
                    <input type="text" name="notes" required placeholder="e.g. Room rent, event hall hire…" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">This is sales handed over by (optional)</label>
                    {{--
                        Direct request ("the income payment recorded by the
                        accountant is not completely recognised as income...
                        because the bar and the restaurant are not having
                        their own device at the moment so they pay their
                        total amount sold to the accountant and he
                        reconciles them"): choosing a department here is
                        what tells the reports this money is that
                        department's own sales, not a generic "other
                        income" line — see FinancialReportService::
                        revenueByChargeType()'s docblock. Leave this on
                        "None" for anything that really is general/rent/
                        visitor income; it is NOT required.
                    --}}
                    <select name="department_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">None — general income (default)</option>
                        @foreach ($attributableDepartments as $department)
                            <option value="{{ $department->id }}">{{ $department->name }} sales handover</option>
                        @endforeach
                    </select>
                    <p class="mt-1 text-xs text-slate-500">Choose this when the amount is a department's total takings handed over because it has no payment device of its own — it will then show up as that department's real revenue in the reports, not as generic income.</p>
                    {{--
                        Direct request ("the accountant recorded a payment
                        which was an income under the bar and restaurant
                        and it affected the expected amount of the active
                        front desk shift which is not suppose to be so"):
                        picking "Bar Sales"/"Restaurant Sales" etc. below,
                        under Income source, is ONLY a report label — it
                        does NOT attribute this money to that department's
                        shift by itself. This dropdown right here is the
                        ONLY field that does that. Spelled out explicitly
                        because this exact confusion is what caused a
                        Bar/Restaurant handover to wrongly count toward the
                        Front Desk's shift.
                    --}}
                    <p class="mt-1 text-xs font-medium text-amber-700">If you're recording Bar or Restaurant's handed-over sales, select the matching department above — picking "Bar Sales" etc. under Income source below is not enough on its own, and leaving this on "None" means the money won't count toward any shift's reconciliation.</p>
                    {{--
                        Direct request ("the place in the payment form
                        that request on who handed the sales should
                        either be the department or a textbox that
                        receive the name of the person handing"): a
                        separate, purely informational note of WHO
                        physically brought the cash in — for when there
                        is no clean department to pick, or simply to
                        record the individual alongside whichever
                        department was chosen above. Independent of the
                        dropdown: fill in either, both, or neither. Never
                        affects revenue recognition or any report total —
                        see `payments.handed_over_by`'s migration.
                    --}}
                    <label class="mt-2 block text-xs font-medium mb-1">Or note who personally handed this in (optional)</label>
                    <input type="text" name="handed_over_by" placeholder="e.g. Ngozi, bar attendant" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <div class="mb-1 flex items-center justify-between">
                        <label class="block text-sm font-medium">Income source</label>
                        {{--
                            Follow-up request ("the list should not be
                            fixed... the accountant, or finance officer
                            [create/update], and [the owner should be able
                            to] update, create and delete it"): a link
                            straight to the managed list, shown only to
                            whoever can actually change it.
                        --}}
                        @can('manage-income-sources')
                            <a href="{{ route('finance.income-sources.index') }}" class="btn-action text-xs text-blue-700 hover:underline">Manage list</a>
                        @endcan
                    </div>
                    {{--
                        Direct request ("all in payment must be attached to
                        an income source that is to be represented ... in
                        the income statement"), refined by a follow-up
                        request ("let the income source be a dropdown of
                        the income sources if other then state"): a
                        managed list (see the IncomeSource model) keeps
                        this consistent for reporting, with an "Other"
                        option that reveals a free-text field for anything
                        not listed — that text is what actually gets saved
                        to the `income_source` column, same as before.
                        Shows up on the Income Statement under Revenue,
                        grouped by whatever ends up stored here.
                    --}}
                    <select name="income_source" id="income_source" required onchange="document.getElementById('income_source_other_wrap').classList.toggle('hidden', this.value !== '__other__')" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        <option value="">Choose an income source…</option>
                        @foreach ($incomeSources as $source)
                            <option value="{{ $source->name }}">{{ $source->name }}</option>
                        @endforeach
                        <option value="__other__">Other (please state)</option>
                    </select>
                    <div id="income_source_other_wrap" class="hidden mt-2">
                        <label class="block text-xs font-medium mb-1">State the income source</label>
                        <input type="text" name="income_source_other" id="income_source_other" placeholder="e.g. Vending machine income" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <div>
                    <label class="flex items-center gap-2 text-xs text-slate-600">
                        <input type="checkbox" id="has_discount" onchange="document.getElementById('discount_fields').classList.toggle('hidden', !this.checked)">
                        A discount was granted on this payment
                    </label>
                    {{--
                        Direct request ("payments can involve discount if
                        granted"): informational only — the Amount above
                        is always the real cash actually received. No
                        approval workflow here (unlike the separate,
                        pre-existing room-rate Discount Approval workflow
                        at check-in) — any staff recording the payment can
                        note one.
                    --}}
                    <div id="discount_fields" class="hidden mt-2 space-y-2 rounded border border-slate-200 p-2">
                        <div>
                            <label class="block text-xs font-medium mb-1">Discount amount (XAF)</label>
                            <input type="number" step="0.01" min="0.01" name="discount_amount" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        </div>
                        <div>
                            <label class="block text-xs font-medium mb-1">Reason for the discount</label>
                            <input type="text" name="discount_reason" placeholder="e.g. Loyal customer, negotiated rate" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                        </div>
                    </div>
                </div>
                <button class="btn-action w-full rounded bg-green-700 px-3 py-2 text-sm text-white hover:bg-green-600">Record payment</button>
            </form>
        </div>
    </div>

    <div class="lg:col-span-2">
        <div class="overflow-x-auto rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Date</th><th class="p-3">Payment #</th><th class="p-3">Guest / payer</th><th class="p-3">Income source</th><th class="p-3">Department</th><th class="p-3">Account</th><th class="p-3 text-right">Amount</th><th class="p-3">By</th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="p-3">{{ $payment->recorded_at->format('d M Y H:i') }}</td>
                            <td class="p-3">{{ $payment->payment_number }}</td>
                            <td class="p-3">{{ $payment->guest->full_name ?? $payment->notes }}</td>
                            <td class="p-3">{{ $payment->income_source ?? '—' }}</td>
                            @php
                                $showsDepartment = $payment->department && $payment->department->code !== 'FO';
                            @endphp
                            <td class="p-3">
                                @if ($showsDepartment)
                                    <span class="rounded bg-blue-50 px-1.5 py-0.5 text-xs text-blue-800">{{ $payment->department->name }}</span>
                                @endif
                                @if ($payment->handed_over_by)
                                    <div class="text-[10px] text-slate-500">Handed in by {{ $payment->handed_over_by }}</div>
                                @endif
                                @if (! $showsDepartment && ! $payment->handed_over_by)
                                    <span class="text-slate-400">—</span>
                                @endif
                            </td>
                            <td class="p-3">{{ $payment->financialAccount->name ?? str_replace('_', ' ', ucfirst($payment->method)) }}</td>
                            <td class="p-3 text-right">
                                {{ number_format($payment->amount, 0) }} XAF
                                @if ($payment->hasDiscount())
                                    <div class="text-[10px] text-amber-700" title="{{ $payment->discount_reason }}">
                                        Gross {{ number_format($payment->grossAmount(), 0) }} &minus; discount {{ number_format($payment->discount_amount, 0) }}
                                    </div>
                                @endif
                            </td>
                            <td class="p-3">{{ $payment->recordedBy->name }}</td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="8">No payments recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        <div class="mt-3">{{ $payments->links() }}</div>
    </div>
</div>

<script>
(function () {
    const searchInput = document.getElementById('guest_search');
    const resultsBox = document.getElementById('guest_results');
    const guestIdField = document.getElementById('guest_id');
    const payerName = document.getElementById('payer_name');
    let debounceTimer = null;

    searchInput.addEventListener('input', function () {
        guestIdField.value = '';
        const q = searchInput.value.trim();
        clearTimeout(debounceTimer);
        if (q.length < 2) {
            resultsBox.classList.add('hidden');
            return;
        }
        debounceTimer = setTimeout(function () {
            fetch('{{ route('guests.search') }}?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(function (guests) {
                    resultsBox.innerHTML = '';
                    if (!guests.length) {
                        resultsBox.classList.add('hidden');
                        return;
                    }
                    guests.forEach(function (guest) {
                        const row = document.createElement('div');
                        row.className = 'btn-action cursor-pointer px-3 py-2 hover:bg-slate-100';
                        row.textContent = guest.full_name + (guest.phone ? ' — ' + guest.phone : '');
                        row.addEventListener('click', function () {
                            guestIdField.value = guest.id;
                            searchInput.value = guest.full_name;
                            payerName.value = '';
                            payerName.disabled = true;
                            resultsBox.classList.add('hidden');
                        });
                        resultsBox.appendChild(row);
                    });
                    resultsBox.classList.remove('hidden');
                })
                .catch(function () { resultsBox.classList.add('hidden'); });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!resultsBox.contains(e.target) && e.target !== searchInput) {
            resultsBox.classList.add('hidden');
        }
    });
})();
</script>
@endsection
