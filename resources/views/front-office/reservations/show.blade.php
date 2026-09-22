@extends('layouts.app')
@section('title', $reservation->reservation_number)
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white p-4 shadow-sm text-sm space-y-2">
        <div class="flex justify-end">
            <a href="{{ route('reservations.print', $reservation) }}" class="text-xs text-slate-500 underline">Print confirmation</a>
        </div>
        <div><span class="text-slate-500">Guest:</span> {{ $reservation->guest->full_name }}</div>
        <div><span class="text-slate-500">Category:</span> {{ $reservation->roomCategory->name }}</div>
        <div><span class="text-slate-500">Room:</span> {{ $reservation->room->room_number ?? 'Not yet assigned' }}</div>
        <div><span class="text-slate-500">Dates:</span> {{ $reservation->check_in_date->format('d M Y') }} – {{ $reservation->check_out_date->format('d M Y') }} ({{ $reservation->nights() }} nights)</div>
        <div><span class="text-slate-500">Rate:</span> {{ number_format($reservation->rate_quoted, 0) }} XAF/night &times; {{ $reservation->nights() }} {{ \Illuminate\Support\Str::plural('night', $reservation->nights()) }} = {{ number_format($reservation->rate_quoted * $reservation->nights(), 0) }} XAF total lodging cost</div>
        @php
            // Feature 6 ("Approved discounts on check-in"): standard vs
            // agreed rate, shown here regardless of status so staff see
            // it while deciding, not just at the moment check-in refuses.
            $standardRate = (float) $reservation->roomCategory->base_rate;
            $needsDiscountApproval = bccomp((string) $reservation->rate_quoted, (string) $standardRate, 2) < 0;
            $activeDiscountApproval = $reservation->discountApprovals()->where('status', 'approved')->whereNull('superseded_at')->latest('approved_at')->first();
        @endphp
        @if ($needsDiscountApproval)
            <div class="rounded border border-amber-200 bg-amber-50 p-3">
                <p class="text-xs font-medium uppercase tracking-wide text-amber-700">Discounted rate — approval required before check-in</p>
                <p class="mt-1 text-sm text-slate-700">Standard rate for {{ $reservation->roomCategory->name }}: {{ number_format($standardRate, 0) }} XAF/night. Quoted: {{ number_format($reservation->rate_quoted, 0) }} XAF/night{{ (float) $reservation->rate_quoted === 0.0 ? ' (complimentary)' : '' }}.</p>
                @if ($activeDiscountApproval)
                    <p class="mt-1 text-sm text-green-700">Approved by {{ $activeDiscountApproval->approver->name ?? '—' }} ({{ $activeDiscountApproval->approver_role_snapshot }}) at {{ $activeDiscountApproval->approved_at->format('d M Y H:i') }}. Reason: {{ $activeDiscountApproval->reason }}</p>
                @else
                    <div class="mt-2 grid grid-cols-1 sm:grid-cols-2 gap-2">
                        <form method="POST" action="{{ route('reservations.discount.self-approve', $reservation) }}" class="space-y-1 rounded border border-slate-200 bg-white p-2">
                            @csrf
                            <label class="block text-xs font-medium">I am an eligible approver — approve now</label>
                            <input name="reason" placeholder="Reason for this rate" required class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
                            <button class="w-full rounded bg-green-700 px-2 py-1 text-xs text-white hover:bg-green-600">Approve this rate</button>
                        </form>
                        <form method="POST" action="{{ route('reservations.discount.request', $reservation) }}" class="space-y-1 rounded border border-slate-200 bg-white p-2">
                            @csrf
                            <label class="block text-xs font-medium">Discount approved by</label>
                            <select name="requested_approver_id" required class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
                                <option value="">Select an eligible approver…</option>
                                @foreach ($eligibleApprovers as $approverOption)
                                    <option value="{{ $approverOption->id }}">{{ $approverOption->name }}</option>
                                @endforeach
                            </select>
                            <input name="reason" placeholder="Reason for this rate" required class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
                            <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Send for approval</button>
                            <p class="text-xs text-slate-500">Selecting a name here is only a request — that person must log in and approve it themselves.</p>
                        </form>
                    </div>
                @endif
            </div>
        @endif
        <div><span class="text-slate-500">Deposit paid:</span> {{ number_format($reservation->deposit_paid, 0) }} / {{ number_format($reservation->deposit_required, 0) }} XAF required — this deposit is a real payment and will be credited against the folio automatically at check-in.</div>
        <div><span class="text-slate-500">Status:</span> <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $reservation->status }}</span></div>
        <div>
            <span class="text-slate-500">Source:</span>
            @if ($reservation->isFromWebsite())
                <span class="rounded bg-indigo-100 px-2 py-0.5 text-xs text-indigo-700">Submitted from website</span>
            @else
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs text-slate-600">Staff-entered</span>
            @endif
        </div>
        <div><span class="text-slate-500">Requested by:</span> {{ $reservation->requestedBy->name }}</div>
        @if ($reservation->approvedBy)
            <div><span class="text-slate-500">Approved by:</span> {{ $reservation->approvedBy->name }} at {{ $reservation->approved_at->format('d M Y H:i') }}</div>
        @endif

        @if ($reservation->isFromWebsite() && ($reservation->deposit_proof_path || $reservation->deposit_claimed_amount || $reservation->deposit_reference))
            @php
                // Correction round (audited defect #3): a claimed deposit
                // is now only ever credited by the explicit
                // verifyWebsiteDeposit() action below (guarded by
                // deposit_claim_verified_at) — never automatically at
                // approval, which used to risk double-recording it
                // against a separately, manually-recorded deposit.
                $depositWasCredited = (bool) $reservation->deposit_claim_verified_at;
            @endphp
            <div class="mt-3 rounded border {{ $depositWasCredited ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' }} p-3">
                <p class="text-xs font-medium uppercase tracking-wide {{ $depositWasCredited ? 'text-green-700' : 'text-amber-700' }}">
                    {{ $depositWasCredited ? 'Guest-claimed deposit — verified &amp; credited' : 'Guest-claimed deposit (not yet verified)' }}
                </p>
                <p class="mt-1 text-sm text-slate-700">
                    @if ($depositWasCredited)
                        This was submitted by the guest through the website booking form and was verified and
                        recorded as a real payment by staff — it's already included in "Deposit paid" above.
                    @else
                        This was submitted by the guest through the website booking form. Review the proof below,
                        then use "Verify &amp; post this claimed deposit" — approving the reservation on its own no
                        longer records any money.
                        @if ($reservation->deposit_claimed_amount && ! $reservation->deposit_claimed_method)
                            No payment method was captured with this claim, so it cannot be verified through that
                            form — use "Record deposit / advance payment" below instead once you've confirmed it.
                        @endif
                    @endif
                </p>
                <div class="mt-2 space-y-1 text-sm">
                    @if ($reservation->deposit_claimed_amount)
                        <div><span class="text-slate-500">Amount claimed:</span> {{ number_format($reservation->deposit_claimed_amount, 0) }} XAF</div>
                    @endif
                    @if ($reservation->deposit_claimed_method)
                        <div><span class="text-slate-500">Method claimed:</span> {{ ucfirst(str_replace('_', ' ', $reservation->deposit_claimed_method)) }}</div>
                    @endif
                    @if ($reservation->deposit_reference)
                        <div><span class="text-slate-500">Reference / note:</span> {{ $reservation->deposit_reference }}</div>
                    @endif
                    @if ($reservation->deposit_proof_path)
                        <div>
                            <span class="text-slate-500">Proof:</span>
                            {{-- Correction round (audited defect #33): this file lives on the
                                 private disk now and is only ever served through this
                                 authenticated, permission-gated route — never a public URL. --}}
                            <a href="{{ route('reservations.deposit-proof', $reservation) }}" target="_blank" rel="noopener"
                               class="text-blue-600 underline">View uploaded file</a>
                        </div>
                    @endif
                    @if ($reservation->deposit_claim_verified_at)
                        <div class="text-green-700"><span class="text-slate-500">Verified:</span> by {{ $reservation->depositClaimVerifiedBy->name ?? '—' }} at {{ $reservation->deposit_claim_verified_at->format('d M Y H:i') }} — posted to the ledger.</div>
                    @elseif ($reservation->deposit_claimed_amount > 0 && $reservation->deposit_claimed_method && in_array($reservation->status, ['pending','approved','confirmed']))
                        @can('verifyDeposit', $reservation)
                            <form method="POST" action="{{ route('reservations.verify-deposit', $reservation) }}" class="mt-2 space-y-1 rounded border border-slate-200 bg-white p-2">
                                @csrf
                                <label class="block text-xs font-medium">Verify &amp; post this claimed deposit</label>
                                <select name="financial_account_id" required class="w-full rounded border border-slate-300 px-2 py-1 text-sm">
                                    <option value="">Received into…</option>
                                    @foreach ($financialAccounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                                <button class="w-full rounded bg-green-700 px-2 py-1 text-xs text-white hover:bg-green-600">Verify deposit &amp; post to ledger</button>
                                <p class="text-xs text-slate-500">This is now the only way this claimed deposit becomes a real payment — approving the reservation no longer does this automatically.</p>
                            </form>
                        @endcan
                    @endif
                </div>
            </div>
        @endif

        @if (in_array($reservation->status, ['cancelled','rejected']) && bccomp((string) $reservation->deposit_paid, '0.00', 2) > 0)
            <div class="mt-3 rounded border {{ $reservation->deposit_disposition ? 'border-slate-200 bg-slate-50' : 'border-amber-200 bg-amber-50' }} p-3">
                <p class="text-xs font-medium uppercase tracking-wide {{ $reservation->deposit_disposition ? 'text-slate-600' : 'text-amber-700' }}">
                    {{ $reservation->deposit_disposition ? 'Deposit disposition recorded' : 'Deposit disposition required' }}
                </p>
                @if ($reservation->deposit_disposition)
                    <p class="mt-1 text-sm text-slate-700">
                        {{ ucfirst($reservation->deposit_disposition) }} by {{ $reservation->deposit_disposition_by ? $reservation->depositDispositionBy->name : '—' }}
                        at {{ $reservation->deposit_disposition_at?->format('d M Y H:i') }}.
                        @if ($reservation->deposit_disposition_notes)
                            <br>{{ $reservation->deposit_disposition_notes }}
                        @endif
                    </p>
                @else
                    <p class="mt-1 text-sm text-slate-700">This {{ $reservation->status }} reservation collected {{ number_format($reservation->deposit_paid, 0) }} XAF — record what happened to it.</p>
                    @can('disposeDeposit', $reservation)
                        <form method="POST" action="{{ route('reservations.dispose-deposit', $reservation) }}" class="mt-2 space-y-1">
                            @csrf
                            <select name="disposition" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm" onchange="document.getElementById('disposeAccountRow').classList.toggle('hidden', this.value !== 'refunded'); document.getElementById('disposeTargetRow').classList.toggle('hidden', this.value !== 'transferred');">
                                <option value="">Choose disposition…</option>
                                <option value="refunded">Refund to guest</option>
                                <option value="forfeited">Forfeit (hotel keeps it)</option>
                                <option value="transferred">Transfer to another reservation</option>
                            </select>
                            <div id="disposeAccountRow" class="hidden">
                                <select name="financial_account_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                                    <option value="">Refund from…</option>
                                    @foreach ($financialAccounts as $account)
                                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                                    @endforeach
                                </select>
                            </div>
                            <div id="disposeTargetRow" class="hidden">
                                <input type="number" name="target_reservation_id" placeholder="Target reservation ID" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            </div>
                            <input name="notes" placeholder="Notes (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Record disposition</button>
                        </form>
                    @endcan
                @endif
            </div>
        @endif
    </div>

    <div class="col-span-1 space-y-3">
        @if (in_array($reservation->status, ['pending', 'approved', 'confirmed']))
            @can('post-folio-payment')
                <form method="POST" action="{{ route('reservations.deposit', $reservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    {{-- Correction round (audited defect #32): a UUID generated once
                         per page load — a double-click or a retried submission (e.g.
                         after a network timeout) resubmits the exact same key, which
                         ReservationService::recordDeposit() recognizes and refuses to
                         double-record, rather than posting the deposit twice. --}}
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="text-xs font-medium">Record deposit / advance payment</div>
                    <select name="financial_account_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">Received into…</option>
                        @foreach ($financialAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" name="amount" placeholder="Amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Record deposit</button>
                    <p class="text-xs text-slate-500">Recorded as a real payment now; automatically credited against the guest's folio balance at check-in.</p>
                </form>
            @endcan
        @endif

        @if ($reservation->status === 'pending')
            @can('approve', $reservation)
                <form method="POST" action="{{ route('reservations.approve', $reservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <label class="block text-xs font-medium">Assign room (optional now, required by check-in)</label>
                    <select name="room_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">— unassigned —</option>
                        @foreach ($availableRooms as $room)
                            <option value="{{ $room->id }}">{{ $room->room_number }} ({{ $room->category->name ?? '—' }})</option>
                        @endforeach
                    </select>
                    {{-- Correction round (audited defect #5): a room outside this
                         reservation's booked category is rejected server-side unless
                         this box is checked — never inferred silently. --}}
                    <label class="flex items-center gap-1.5 text-xs text-slate-600">
                        <input type="checkbox" name="confirm_category_override" value="1">
                        Confirm reassigning to a different room category than booked (e.g. an upgrade)
                    </label>
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Approve &amp; confirm</button>
                    @if ($reservation->deposit_claimed_amount > 0 && $reservation->deposit_claimed_method)
                        <p class="text-xs text-slate-500">
                            This reservation has a guest-claimed {{ number_format($reservation->deposit_claimed_amount, 0) }} XAF deposit — approving does not post it; use "Verify &amp; post this claimed deposit" above once approved.
                        </p>
                    @endif
                </form>
                <form method="POST" action="{{ route('reservations.reject', $reservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <input name="reason" placeholder="Rejection reason" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <button class="w-full rounded bg-red-700 px-3 py-1.5 text-sm text-white hover:bg-red-600">Reject</button>
                </form>
            @endcan
        @endif

        @if ($reservation->status === 'confirmed')
            @can('checkin-guest')
                <form method="POST" action="{{ route('reservations.checkin', $reservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    @if (! $reservation->room_id)
                        <label class="block text-xs font-medium">Assign room</label>
                        <select name="room_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            @foreach ($availableRooms as $room)
                                <option value="{{ $room->id }}">{{ $room->room_number }}</option>
                            @endforeach
                        </select>
                    @endif
                    <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Check in now</button>
                </form>
            @endcan
            <form method="POST" action="{{ route('reservations.no-show', $reservation) }}">
                @csrf
                <button class="w-full rounded bg-amber-600 px-3 py-1.5 text-sm text-white hover:bg-amber-500">Mark no-show</button>
            </form>
        @endif

        @if (in_array($reservation->status, ['pending','approved','confirmed']))
            <form method="POST" action="{{ route('reservations.cancel', $reservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <input name="reason" placeholder="Cancellation reason" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Cancel reservation</button>
            </form>
        @endif
    </div>
</div>
@endsection
