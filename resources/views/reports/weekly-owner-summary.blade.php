@extends('layouts.app')
@section('title', 'Weekly Owner Summary')
@section('content')

<form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
    <div>
        <label class="block text-xs text-slate-500 mb-1">Week ending</label>
        <input type="date" name="week_ending" value="{{ $end->toDateString() }}" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
    </div>
    <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Update</button>
    <a href="{{ route('reports.weekly-owner-summary.print', ['week_ending' => $end->toDateString()]) }}" class="rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Print</a>
</form>

<div class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
    Management estimate, not a formal accounting statement — see the Income Statement report for the full caveat.
    The net operating result below combines accrual revenue and requested (not necessarily yet paid) expenses.
</div>

<div class="max-w-2xl rounded-lg bg-white p-4 shadow-sm mb-4">
    <div class="text-xs uppercase text-slate-500 mb-2">
        Week of {{ $start->format('M j') }} &ndash; {{ $end->format('M j, Y') }}
    </div>
    <p class="text-sm leading-relaxed text-slate-700">
        This week the hotel ran at
        <strong>{{ $occupancy['occupancy_rate'] }}% occupancy</strong>
        ({{ $occupancy['room_nights_occupied'] }} of {{ $occupancy['room_nights_available'] }} room-nights sold),
        with an ADR of {{ number_format($occupancy['adr'], 0) }} XAF and RevPAR of {{ number_format($occupancy['revpar'], 0) }} XAF.
        Total revenue charged to guest folios was <strong>{{ number_format($statement['total_revenue'], 0) }} XAF</strong>,
        against <strong>{{ number_format($statement['total_expenses'], 0) }} XAF</strong> in expenses requested,
        for a net operating result of
        <strong class="{{ $statement['net_operating_result'] >= 0 ? 'text-green-700' : 'text-red-600' }}">
            {{ number_format($statement['net_operating_result'], 0) }} XAF
        </strong>.
        Cash actually collected was {{ number_format($cash_flow['cash_in'], 0) }} XAF against
        {{ number_format($cash_flow['total_cash_out'], 0) }} XAF paid out, a net cash flow of
        {{ number_format($cash_flow['net'], 0) }} XAF.
        Accounts receivable currently stands at
        <strong>{{ number_format($receivables['total_outstanding'], 0) }} XAF</strong> outstanding across
        {{ array_sum(array_column($receivables['buckets'], 'count')) }} open accounts.
    </p>
</div>

<div class="grid grid-cols-2 gap-4 md:grid-cols-4 mb-6">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Occupancy</div>
        <div class="text-2xl font-semibold">{{ $occupancy['occupancy_rate'] }}%</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Revenue</div>
        <div class="text-2xl font-semibold">{{ number_format($statement['total_revenue'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Net operating result</div>
        <div class="text-2xl font-semibold {{ $statement['net_operating_result'] >= 0 ? 'text-green-700' : 'text-red-600' }}">
            {{ number_format($statement['net_operating_result'], 0) }}
        </div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs uppercase text-slate-500">Net cash flow</div>
        <div class="text-2xl font-semibold {{ $cash_flow['net'] >= 0 ? 'text-green-700' : 'text-red-600' }}">
            {{ number_format($cash_flow['net'], 0) }}
        </div>
    </div>
</div>

<div class="grid grid-cols-1 gap-4 md:grid-cols-2">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="font-medium mb-2">Revenue by type</div>
        <table class="w-full text-sm">
            <tbody class="divide-y">
                @foreach ($statement['revenue_by_type'] as $type => $amount)
                    <tr><td class="py-1">{{ str_replace('_', ' ', ucfirst($type)) }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="font-medium mb-2">Receivables aging</div>
        <table class="w-full text-sm">
            <tbody class="divide-y">
                @foreach ($receivables['buckets'] as $label => $bucket)
                    <tr><td class="py-1">{{ $label }}</td><td class="py-1 text-right">{{ $bucket['count'] }} / {{ number_format($bucket['amount'], 0) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

<p class="mt-4 text-xs text-slate-500">
    See <a href="{{ route('reports.income-statement', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="underline">Income Statement</a>,
    <a href="{{ route('reports.occupancy', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="underline">Occupancy</a>, and
    <a href="{{ route('reports.financial', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="underline">Financial</a>
    for the full detail behind this rollup.
</p>
@endsection
