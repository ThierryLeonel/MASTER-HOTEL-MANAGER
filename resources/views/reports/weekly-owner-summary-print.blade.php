@extends('layouts.print')
@section('title', 'Weekly Owner Summary — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Weekly Owner Summary')
@section('content')

<p class="text-xs text-slate-500 mb-4">
    Management estimate, not a formal accounting statement — see the Income Statement report for the full caveat.
</p>

<p class="text-sm leading-relaxed text-slate-700 mb-6">
    Week of {{ $start->format('M j') }} &ndash; {{ $end->format('M j, Y') }}: the hotel ran at
    <strong>{{ $occupancy['occupancy_rate'] }}% occupancy</strong>
    ({{ $occupancy['room_nights_occupied'] }} of {{ $occupancy['room_nights_available'] }} room-nights sold),
    with an ADR (Average Daily Rate) of {{ number_format($occupancy['adr'], 0) }} XAF and RevPAR (Revenue Per Available Room) of {{ number_format($occupancy['revpar'], 0) }} XAF.
    Total revenue (guest folio charges plus other recorded income) was <strong>{{ number_format($statement['total_revenue'], 0) }} XAF</strong>,
    against <strong>{{ number_format($statement['total_expenses'], 0) }} XAF</strong> in expenses,
    for a net operating result of
    <strong>{{ number_format($statement['net_operating_result'], 0) }} XAF</strong>.
    Cash actually collected was {{ number_format($cash_flow['cash_in'], 0) }} XAF against
    {{ number_format($cash_flow['total_cash_out'], 0) }} XAF paid out, a net cash flow of
    {{ number_format($cash_flow['net'], 0) }} XAF.
    Accounts receivable currently stands at
    <strong>{{ number_format($receivables['total_outstanding'], 0) }} XAF</strong> outstanding.
</p>

<table class="w-full text-sm mb-4">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
        <tr><th class="py-2">Revenue by type</th><th class="py-2 text-right">Amount</th></tr>
    </thead>
    <tbody class="divide-y">
        @foreach ($statement['revenue_by_type'] as $type => $amount)
            <tr><td class="py-1">{{ str_replace('_', ' ', ucfirst($type)) }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
        @endforeach
    </tbody>
</table>
@endsection
