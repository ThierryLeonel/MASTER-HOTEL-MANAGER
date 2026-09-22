@extends('layouts.print')
@section('title', $guest->full_name . ' — Transaction History')
@section('document-title', 'Guest Transaction History')
@section('content')

<p class="text-xs text-slate-500 mb-1">{{ $guest->full_name }} — {{ $guest->phone }} — {{ $guest->email }}</p>
<p class="text-xs text-slate-500 mb-4">
    {{ $consolidated['stays_count'] }} stay(s) · Charged {{ number_format($consolidated['total_charges'], 0) }}
    · Paid {{ number_format($consolidated['total_payments'] * -1, 0) }}
    · Refunded {{ number_format($consolidated['total_refunds'], 0) }}
    · Net outstanding {{ number_format($consolidated['net_outstanding'], 0) }}
</p>

{{-- Correction (found live, "don't forget the inbuilt guest transaction
     history"): reservation deposits — and their refunds, if the
     reservation was later rejected/cancelled — sit outside any stay's
     folio (a reservation that never reached check-in never gets one), so
     the per-stay tables below never included them. Same omission existed
     in the CSV export; both are fixed together with the on-screen view. --}}
@if ($deposit_payments->isNotEmpty())
    <div class="mb-4">
        <p class="text-xs font-semibold mb-1">Reservation deposit activity</p>
        <table class="w-full text-sm">
            <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="py-1">Date</th><th class="py-1">Type</th>
                    <th class="py-1 text-right">Amount</th><th class="py-1">Method</th><th class="py-1">Staff</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @foreach ($deposit_payments as $payment)
                    <tr>
                        <td class="py-1">{{ $payment->recorded_at->format('d M Y') }}</td>
                        <td class="py-1">{{ $payment->source_module === 'reservation_deposit_refund' ? 'Refunded' : 'Received' }}</td>
                        <td class="py-1 text-right">{{ $payment->source_module === 'reservation_deposit_refund' ? '-' : '' }}{{ number_format($payment->amount, 0) }}</td>
                        <td class="py-1">{{ $payment->method }}</td>
                        <td class="py-1">{{ $payment->recordedBy->name ?? '—' }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif

@forelse ($stays as $stayReport)
    @php($stay = $stayReport['stay'])
    <div class="mb-4">
        <p class="text-xs font-semibold mb-1">
            Room {{ $stay->room->room_number ?? '—' }} · {{ $stay->check_in_at->format('d M Y') }}
            &ndash; {{ optional($stay->actual_check_out_at)->format('d M Y') ?? 'in-house' }}
        </p>
        <table class="w-full text-sm">
            <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="py-1">Date</th><th class="py-1">Type</th><th class="py-1">Description</th>
                    <th class="py-1 text-right">Amount</th><th class="py-1">Method</th><th class="py-1">Staff</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($stayReport['transactions'] as $tx)
                    <tr>
                        <td class="py-1">{{ $tx['transaction_date']?->format('d M Y H:i') }}</td>
                        <td class="py-1">{{ $tx['type'] }}{{ $tx['is_voided'] ? ' (voided)' : '' }}</td>
                        <td class="py-1">{{ $tx['description'] }}</td>
                        <td class="py-1 text-right">{{ number_format($tx['amount'], 0) }}</td>
                        <td class="py-1">{{ $tx['payment_method'] ?? '—' }}</td>
                        <td class="py-1">{{ $tx['staff']->name ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="py-1 text-slate-500" colspan="6">No transactions.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@empty
    <p class="text-sm text-slate-500">No recorded stays.</p>
@endforelse
@endsection
