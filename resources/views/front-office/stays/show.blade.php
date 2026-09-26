@extends('layouts.app')
@section('title', 'Room ' . $stay->room->room_number . ' — ' . $stay->guest->full_name)
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 space-y-6">
        <div class="rounded-lg bg-white p-4 shadow-sm text-sm">
            <div class="flex justify-between">
                <div>
                    <div class="font-semibold">
                        {{ $stay->guest->full_name }}
                        @if ($stay->is_complimentary)
                            <span class="ml-1 rounded bg-purple-100 px-1.5 py-0.5 text-xs text-purple-800">Complimentary stay</span>
                        @endif
                    </div>
                    <div class="text-slate-500">Room {{ $stay->room->room_number }} ({{ $stay->room->category->name }})</div>
                    <div class="text-xs text-slate-500">{{ number_format($stay->agreed_rate, 0) }} XAF / night</div>
                    @if ($stay->is_complimentary)
                        <div class="mt-1 text-xs text-purple-700">Room charge waived — {{ $stay->complimentary_reason }} (by {{ $stay->markedComplimentaryBy->name ?? '—' }}, {{ $stay->marked_complimentary_at?->format('d M Y') }}). Other charges still apply normally.</div>
                    @endif
                </div>
                <div class="text-right">
                    <div class="text-2xl font-semibold">{{ number_format($stay->folio->balance, 0) }} XAF</div>
                    <div class="text-xs text-slate-500">Folio {{ $stay->folio->status }}</div>
                    <div class="mt-1 text-xs">
                        <a href="{{ route('stays.invoice.print', $stay) }}" class="text-slate-500 underline">Print invoice</a>
                        @if ($stay->status === 'closed')
                            · <a href="{{ route('stays.final-bill.print', $stay) }}" class="text-slate-500 underline">Print final bill</a>
                        @endif
                    </div>
                </div>
            </div>
        </div>

        @php
            // Feature request ("a place ... to identify who approved the
            // discount"): the walk-in exception lets check-in proceed
            // with a still-PENDING discount approval attached (see
            // DiscountApprovalService's class docblock) — surfaced here
            // so anyone opening this stay later can see it hasn't
            // actually been confirmed yet, not just at the moment of
            // check-in (the flash message on that page disappears after
            // one reload).
            $latestDiscountApproval = $stay->discountApprovals()->whereNull('superseded_at')->latest()->first();
        @endphp
        @if ($latestDiscountApproval && $latestDiscountApproval->status !== 'approved')
            <div class="rounded-lg border-2 {{ $latestDiscountApproval->status === 'rejected' ? 'border-red-300 bg-red-50' : 'border-amber-300 bg-amber-50' }} p-4 text-sm">
                @if ($latestDiscountApproval->status === 'pending')
                    <p class="font-semibold text-amber-800">Discounted rate pending approval</p>
                    <p class="mt-1 text-slate-700">Agreed {{ number_format($latestDiscountApproval->agreed_rate, 0) }} XAF/night vs. standard {{ number_format($latestDiscountApproval->standard_rate, 0) }} XAF/night. Requested by {{ $latestDiscountApproval->requestedBy->name ?? '—' }}, routed to {{ $latestDiscountApproval->requestedApprover->name ?? '—' }} for confirmation. Reason: {{ $latestDiscountApproval->reason }}</p>
                @else
                    <p class="font-semibold text-red-800">Discounted rate was rejected</p>
                    <p class="mt-1 text-slate-700">{{ $latestDiscountApproval->requestedApprover->name ?? 'The approver' }} rejected this rate: {{ $latestDiscountApproval->rejection_reason }}</p>
                @endif
            </div>
        @endif

        {{-- Post-9.8 fix round ("in the guest folio it should carry the
             summaries of the bills to be paid to the different
             departments") — see Folio::departmentSummary()'s docblock. --}}
        @if ($summary = $stay->folio->departmentSummary())
            <div class="rounded-lg bg-white p-4 shadow-sm text-sm">
                <div class="mb-2 font-semibold text-xs uppercase tracking-wide text-slate-500">Bill summary by department</div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3">
                    @foreach ($summary as $label => $amount)
                        <div class="rounded border border-slate-200 p-2">
                            <div class="text-xs text-slate-500">{{ $label }}</div>
                            <div class="font-medium">{{ number_format($amount, 0) }} XAF</div>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Description</th><th class="p-3 text-right">Amount</th><th class="p-3">By</th><th class="p-3"></th></tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($stay->folio->transactions as $tx)
                        <tr class="{{ $tx->isVoided() ? 'opacity-50 line-through' : '' }}">
                            <td class="p-3">{{ $tx->transaction_date->format('d M H:i') }}</td>
                            <td class="p-3">{{ $tx->type }}</td>
                            <td class="p-3">{{ $tx->description }}</td>
                            <td class="p-3 text-right">{{ number_format($tx->amount, 0) }}</td>
                            <td class="p-3">{{ $tx->user->name }}</td>
                            <td class="p-3 space-x-2">
                                @if ($tx->type === 'payment')
                                    <a href="{{ route('folios.receipt.print', $tx) }}" class="text-xs text-slate-500 underline">Receipt</a>
                                @endif
                                @can('void-folio-transaction')
                                    @if (! $tx->isVoided() && $stay->folio->status === 'open')
                                        <form method="POST" action="{{ route('folios.void', $tx) }}" onsubmit="return promptVoidReason(this)" class="inline">
                                            @csrf
                                            <input type="hidden" name="reason" value="">
                                            <button type="submit" class="text-red-600 hover:underline text-xs">Void</button>
                                        </form>
                                    @endif
                                @endcan
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($stay->folio->accountsReceivable)
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-4 text-sm text-amber-800">
                Outstanding balance of {{ number_format($stay->folio->accountsReceivable->amount_outstanding, 0) }} XAF
                moved to Accounts Receivable (status: {{ $stay->folio->accountsReceivable->status }}).
            </div>
        @endif
    </div>

    <div class="col-span-1 space-y-3 hms-side-panel">
        @if ($stay->folio->status === 'open')
            @can('post-folio-charge')
                <form method="POST" action="{{ route('folios.charge', $stay->folio) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <div class="text-xs font-medium">Post charge</div>
                    <select name="type" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="room_charge" @disabled($stay->is_complimentary)>Room charge @if ($stay->is_complimentary) (waived — stay is complimentary) @endif</option>
                        <option value="bar_charge">Bar charge</option>
                        <option value="restaurant_charge">Restaurant charge</option>
                        <option value="front_desk_charge">Front desk charge</option>
                        <option value="bar_outside_charge">Bar Outside charge</option>
                        <option value="bucareaux_charge">Bucareaux charge</option>
                        <option value="other_service">Other service</option>
                    </select>
                    <input name="description" placeholder="Description" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <input type="number" step="0.01" name="amount" placeholder="Amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Post charge</button>
                </form>
            @endcan

            @can('post-folio-payment')
                <form method="POST" action="{{ route('folios.payment', $stay->folio) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    {{-- Correction round (audited defect #32): see the matching
                         comment on the reservation-deposit form — a UUID generated
                         once per page load, resubmitted unchanged on a double-click
                         or retried submit, so FolioService::postPayment() can
                         recognize and refuse to double-post it. --}}
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="text-xs font-medium">Post payment</div>
                    <select name="financial_account_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">Received into…</option>
                        @foreach ($financialAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" name="amount" placeholder="Amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Post payment</button>
                </form>
            @endcan

            @can('request-refund')
                <form method="POST" action="{{ route('finance.refunds.store', $stay->folio) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <div class="text-xs font-medium">Request refund</div>
                    <select name="method" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="cash">Cash</option>
                        <option value="mobile_money">Mobile money</option>
                        <option value="card">Card</option>
                        <option value="bank_transfer">Bank transfer</option>
                    </select>
                    <input type="number" step="0.01" name="amount" placeholder="Amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <input name="reason" placeholder="Reason" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    {{-- Correction round (audited defect #20): explicit, separate
                         checkbox for a goodwill compensation — exempt from the
                         "available refundable funds" cap at approval. --}}
                    <label class="flex items-center gap-1.5 text-xs text-slate-600">
                        <input type="checkbox" name="is_goodwill" value="1">
                        Goodwill compensation (may exceed what the guest actually paid)
                    </label>
                    <button class="w-full rounded bg-amber-600 px-3 py-1.5 text-sm text-white hover:bg-amber-500">Request refund</button>
                    <p class="text-xs text-slate-500">Requires separate approval — see Finance → Refunds. An ordinary refund is capped to what's still available to refund on this folio.</p>
                </form>
            @endcan

            @if ($stay->status === 'active')
                @can('mark-stay-complimentary')
                    @if ($stay->is_complimentary)
                        <form method="POST" action="{{ route('stays.unmark-complimentary', $stay) }}" class="rounded-lg border border-purple-200 bg-purple-50 p-4 shadow-sm space-y-2">
                            @csrf
                            <div class="text-xs font-medium text-purple-800">This stay is complimentary</div>
                            <button class="w-full rounded bg-purple-700 px-3 py-1.5 text-sm text-white hover:bg-purple-600">Remove complimentary status</button>
                        </form>
                    @else
                        <form method="POST" action="{{ route('stays.mark-complimentary', $stay) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                            @csrf
                            <div class="text-xs font-medium">Mark complimentary</div>
                            <input name="reason" placeholder="Reason (e.g. VIP guest, familiarization stay)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <button class="w-full rounded bg-purple-700 px-3 py-1.5 text-sm text-white hover:bg-purple-600">Mark room complimentary</button>
                            <p class="text-xs text-slate-500">Waives this stay's room charge only — bar, restaurant, and other charges still apply.</p>
                        </form>
                    @endif
                @endcan

                @can('checkout-guest')
                    <form method="POST" action="{{ route('stays.checkout', $stay) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                        @csrf
                        <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Check out</button>
                        <p class="text-xs text-slate-500">
                            @if ($stay->is_complimentary)
                                Room charge waived (complimentary stay).
                            @elseif ($stay->actualNights() > $stay->nights_charged)
                                The full stay cost was already charged at check-in — checking out today will additionally charge {{ $stay->actualNights() - $stay->nights_charged }} extra night(s) beyond the original {{ $stay->nights_charged }}.
                            @else
                                The full stay cost was already charged at check-in — no further room charge is needed to check out today.
                            @endif
                            Any remaining balance becomes Accounts Receivable — checkout is never blocked by an unpaid balance.
                        </p>
                    </form>
                @endcan
            @endif
        @endif
    </div>
</div>

<script>
function promptVoidReason(form) {
    const reason = prompt('Reason for voiding this transaction:');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
