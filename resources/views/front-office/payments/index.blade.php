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
                    <label class="block text-sm font-medium mb-1">Income source</label>
                    {{--
                        Direct request ("all in payment must be attached to
                        an income source that is to be represented ... in
                        the income statement"): every payment recorded on
                        this screen is incoming money, so it must say what
                        kind of income it is. Free text, same convention as
                        an expense's category — shows up on the Income
                        Statement under Revenue, grouped by exactly what's
                        typed here.
                    --}}
                    <input type="text" name="income_source" required placeholder="e.g. Rent, Event hall hire, Commission" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
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
                    <tr><th class="p-3">Date</th><th class="p-3">Payment #</th><th class="p-3">Guest / payer</th><th class="p-3">Income source</th><th class="p-3">Account</th><th class="p-3 text-right">Amount</th><th class="p-3">By</th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($payments as $payment)
                        <tr>
                            <td class="p-3">{{ $payment->recorded_at->format('d M Y H:i') }}</td>
                            <td class="p-3">{{ $payment->payment_number }}</td>
                            <td class="p-3">{{ $payment->guest->full_name ?? $payment->notes }}</td>
                            <td class="p-3">{{ $payment->income_source ?? '—' }}</td>
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
                        <tr><td class="p-3 text-slate-500" colspan="7">No payments recorded yet.</td></tr>
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
