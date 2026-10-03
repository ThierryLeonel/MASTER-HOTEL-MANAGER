@extends('layouts.app')
@section('title', $eventReservation->event_reference)
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white p-4 shadow-sm text-sm space-y-2">
        <div><span class="text-slate-500">Event:</span> {{ $eventReservation->event_name }} ({{ $eventReservation->event_type }})</div>
        <div><span class="text-slate-500">Contact:</span> {{ $eventReservation->contactDisplayName() }}
            @if ($eventReservation->contact_phone) — {{ $eventReservation->contact_phone }} @endif
            @if ($eventReservation->contact_email) — {{ $eventReservation->contact_email }} @endif
        </div>
        @if ($eventReservation->expected_guests)
            <div><span class="text-slate-500">Expected guests:</span> {{ number_format($eventReservation->expected_guests) }}</div>
        @endif
        <div><span class="text-slate-500">When:</span> {{ $eventReservation->start_at->format('d M Y H:i') }} – {{ $eventReservation->end_at->format('d M Y H:i') }}</div>
        <div>
            <span class="text-slate-500">Spaces:</span>
            {{ $eventReservation->eventSpaces->pluck('name')->implode(', ') ?: '—' }}
        </div>
        <div>
            <span class="text-slate-500">Rooms:</span>
            {{ $eventReservation->rooms->pluck('room_number')->implode(', ') ?: '—' }}
        </div>
        @if ($eventReservation->agreed_amount)
            <div><span class="text-slate-500">Agreed amount:</span> {{ number_format($eventReservation->agreed_amount, 0) }} XAF</div>
        @endif
        <div><span class="text-slate-500">Deposit paid:</span> {{ number_format($eventReservation->deposit_paid, 0) }} / {{ number_format($eventReservation->deposit_required, 0) }} XAF required</div>
        <div><span class="text-slate-500">Status:</span> <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $eventReservation->status }}</span></div>
        <div><span class="text-slate-500">Requested by:</span> {{ $eventReservation->requestedBy->name }}</div>
        @if ($eventReservation->approvedBy)
            <div><span class="text-slate-500">Approved by:</span> {{ $eventReservation->approvedBy->name }} at {{ $eventReservation->approved_at->format('d M Y H:i') }}</div>
        @endif
        @if ($eventReservation->rejection_reason)
            <div><span class="text-slate-500">Rejection reason:</span> {{ $eventReservation->rejection_reason }}</div>
        @endif
        @if ($eventReservation->cancellation_reason)
            <div><span class="text-slate-500">Cancellation reason:</span> {{ $eventReservation->cancellation_reason }}</div>
        @endif
        @if ($eventReservation->notes)
            <div><span class="text-slate-500">Notes:</span> {{ $eventReservation->notes }}</div>
        @endif
    </div>

    <div class="col-span-1 space-y-3 hms-side-panel">
        @if (! in_array($eventReservation->status, ['cancelled', 'rejected']))
            @can('manage-event-reservations')
                <form method="POST" action="{{ route('event-reservations.deposit', $eventReservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <input type="hidden" name="idempotency_key" value="{{ \Illuminate\Support\Str::uuid() }}">
                    <div class="text-xs font-medium">Record deposit</div>
                    <select name="financial_account_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="">Received into…</option>
                        @foreach ($financialAccounts as $account)
                            <option value="{{ $account->id }}">{{ $account->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.01" name="amount" placeholder="Amount (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Record deposit</button>
                </form>
            @endcan
        @endif

        @if ($eventReservation->status === 'pending')
            @can('approve-event-reservation')
                <form method="POST" action="{{ route('event-reservations.approve', $eventReservation) }}" class="rounded-lg bg-white p-4 shadow-sm">
                    @csrf
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Approve &amp; confirm</button>
                </form>
                <form method="POST" action="{{ route('event-reservations.reject', $eventReservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <input name="reason" placeholder="Rejection reason" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <button class="w-full rounded bg-red-700 px-3 py-1.5 text-sm text-white hover:bg-red-600">Reject</button>
                </form>
            @endcan
        @endif

        @if (in_array($eventReservation->status, ['pending', 'confirmed']))
            @can('manage-event-reservations')
                <form method="POST" action="{{ route('event-reservations.cancel', $eventReservation) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                    @csrf
                    <input name="reason" placeholder="Cancellation reason" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Cancel event reservation</button>
                </form>
            @endcan
        @endif
    </div>
</div>
@endsection
