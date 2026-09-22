@extends('layouts.app')
@section('title', $guest->full_name . ' — Transaction History')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <div>
        <div class="text-lg font-semibold">{{ $guest->full_name }}</div>
        <div class="text-sm text-slate-500">{{ $guest->phone }} · {{ $guest->email }}</div>
    </div>
    <div class="flex gap-2 text-xs">
        <a href="{{ route('reports.guest-transaction-history.print', $guest) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
        <a href="{{ route('reports.guest-transaction-history.csv', $guest) }}" class="rounded bg-slate-200 px-3 py-1.5 hover:bg-slate-300">Download CSV</a>
    </div>
</div>

<div class="mb-6 grid grid-cols-2 gap-3 sm:grid-cols-5">
    <div class="rounded-lg bg-white p-3 text-center shadow-sm">
        <div class="text-xs text-slate-500">Stays</div>
        <div class="text-lg font-semibold">{{ $consolidated['stays_count'] }}</div>
    </div>
    <div class="rounded-lg bg-white p-3 text-center shadow-sm">
        <div class="text-xs text-slate-500">Total charged</div>
        <div class="text-lg font-semibold">{{ number_format($consolidated['total_charges'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-3 text-center shadow-sm">
        <div class="text-xs text-slate-500">Total paid</div>
        <div class="text-lg font-semibold">{{ number_format($consolidated['total_payments'] * -1, 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-3 text-center shadow-sm">
        <div class="text-xs text-slate-500">Total refunded</div>
        <div class="text-lg font-semibold">{{ number_format($consolidated['total_refunds'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-3 text-center shadow-sm">
        <div class="text-xs text-slate-500">Net outstanding</div>
        <div class="text-lg font-semibold {{ $consolidated['net_outstanding'] > 0 ? 'text-red-600' : ($consolidated['net_outstanding'] < 0 ? 'text-amber-600' : '') }}">{{ number_format($consolidated['net_outstanding'], 0) }}</div>
    </div>
</div>

@if ($deposit_payments->isNotEmpty())
    @php
        $depositReceiptsTotal = $deposit_payments->where('source_module', 'reservation_deposit')->sum('amount');
        $depositRefundsTotal = $deposit_payments->where('source_module', 'reservation_deposit_refund')->sum('amount');
    @endphp
    <div class="mb-6 rounded-lg bg-white shadow-sm">
        <div class="border-b p-3 text-sm font-semibold">Reservation deposit activity</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Amount</th><th class="p-3">Method</th><th class="p-3">Account</th><th class="p-3">Recorded by</th></tr>
            </thead>
            <tbody class="divide-y">
                @foreach ($deposit_payments as $payment)
                    @php($isRefund = $payment->source_module === 'reservation_deposit_refund')
                    <tr>
                        <td class="p-3">{{ $payment->recorded_at->format('d M Y') }}</td>
                        <td class="p-3">
                            @if ($isRefund)
                                <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700">Refunded</span>
                            @else
                                <span class="rounded bg-emerald-100 px-2 py-0.5 text-xs text-emerald-700">Received</span>
                            @endif
                        </td>
                        <td class="p-3 {{ $isRefund ? 'text-red-600' : '' }}">{{ $isRefund ? '-' : '' }}{{ number_format($payment->amount, 0) }}</td>
                        <td class="p-3">{{ $payment->method }}</td>
                        <td class="p-3">{{ $payment->financialAccount->name ?? '—' }}</td>
                        <td class="p-3">{{ $payment->recordedBy->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
            <tfoot class="border-t bg-slate-50 text-xs text-slate-600">
                <tr>
                    <td class="p-3" colspan="2">Net deposits held</td>
                    <td class="p-3 font-semibold" colspan="4">{{ number_format($depositReceiptsTotal - $depositRefundsTotal, 0) }}
                        <span class="font-normal text-slate-400">({{ number_format($depositReceiptsTotal, 0) }} received − {{ number_format($depositRefundsTotal, 0) }} refunded)</span>
                    </td>
                </tr>
            </tfoot>
        </table>
    </div>
@endif

@forelse ($stays as $stayReport)
    @php($stay = $stayReport['stay'])
    <div class="mb-6 rounded-lg bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b p-3">
            <div class="text-sm">
                <strong>Room {{ $stay->room->room_number ?? '—' }}</strong> ({{ $stay->room->category->name ?? '—' }})
                · {{ $stay->check_in_at->format('d M Y') }} — {{ optional($stay->actual_check_out_at)->format('d M Y') ?? 'still in-house' }}
                · Rate {{ number_format($stay->agreed_rate, 0) }} XAF/night
            </div>
            <div class="text-xs">
                <span class="rounded bg-slate-100 px-2 py-0.5">{{ $stay->status }}</span>
                @if ($stayReport['accounts_receivable'])
                    <span class="rounded bg-amber-100 px-2 py-0.5 text-amber-700">AR: {{ $stayReport['accounts_receivable']->status }} ({{ number_format($stayReport['accounts_receivable']->amount_outstanding, 0) }})</span>
                @endif
            </div>
        </div>

        @if ($stayReport['discount_approval'])
            <div class="border-b bg-amber-50 p-3 text-xs text-amber-800">
                Discounted rate approved by {{ $stayReport['discount_approval']->approver->name ?? '—' }}
                on {{ $stayReport['discount_approval']->approved_at?->format('d M Y') }} —
                {{ $stayReport['discount_approval']->reason ?? 'no reason recorded' }}
            </div>
        @endif

        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="p-3">Date</th><th class="p-3">Type</th><th class="p-3">Description</th>
                    <th class="p-3 text-right">Amount</th><th class="p-3">Method / Account</th><th class="p-3">Staff</th><th class="p-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($stayReport['transactions'] as $tx)
                    <tr class="{{ $tx['is_voided'] ? 'opacity-50 line-through' : '' }}">
                        <td class="p-3">{{ $tx['transaction_date']?->format('d M Y H:i') }}</td>
                        <td class="p-3">{{ $tx['type'] }}</td>
                        <td class="p-3">{{ $tx['description'] }}</td>
                        <td class="p-3 text-right">{{ number_format($tx['amount'], 0) }}</td>
                        <td class="p-3">{{ $tx['payment_method'] ?? '—' }} @if ($tx['financial_account']) ({{ $tx['financial_account']->name }}) @endif</td>
                        <td class="p-3">{{ $tx['staff']->name ?? '—' }}</td>
                        <td class="p-3">
                            @if ($tx['receipt_transaction_id'])
                                <a href="{{ route('folios.receipt.print', $tx['receipt_transaction_id']) }}" class="text-xs text-slate-500 underline">Receipt</a>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="7">No transactions on this stay's folio.</td></tr>
                @endforelse
            </tbody>
        </table>

        <div class="flex flex-wrap gap-4 border-t bg-slate-50 p-3 text-xs">
            <div>Charges: <strong>{{ number_format($stayReport['totals']['charges'], 0) }}</strong></div>
            <div>Payments: <strong>{{ number_format($stayReport['totals']['payments'] * -1, 0) }}</strong></div>
            <div>Refunds: <strong>{{ number_format($stayReport['totals']['refunds'], 0) }}</strong></div>
            <div>Credit settlements: <strong>{{ number_format($stayReport['totals']['credit_settlements'] * -1, 0) }}</strong></div>
            @if ($stay->actual_check_out_at)
                <div>Balance at checkout: <strong>{{ number_format($stayReport['balance_at_checkout'], 0) }}</strong></div>
                @if ($stayReport['subsequent_settlements']->isNotEmpty())
                    <div class="text-amber-700">Settled after checkout: <strong>{{ number_format($stayReport['subsequent_settlements']->sum('amount') * -1, 0) }}</strong></div>
                @endif
            @endif
            <div>Current folio balance: <strong>{{ number_format($stayReport['current_balance'], 0) }}</strong></div>
        </div>
    </div>
@empty
    <p class="text-sm text-slate-500">This guest has no recorded stays.</p>
@endforelse
@endsection
