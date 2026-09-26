@extends('layouts.app')
@section('title', 'Purchase Report')
@section('content')
@include('reports._date-filter')

<div class="mb-4 flex gap-3 text-xs">
    <a href="{{ route('reports.purchases.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
    <a href="{{ route('reports.purchases.csv', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-200 px-3 py-1.5 hover:bg-slate-300">Download CSV</a>
</div>

<div class="rounded-lg bg-white p-4 shadow-sm mb-4">
    <div class="font-medium mb-2">Summary — {{ $totals['po_count'] }} purchase order(s) in this period</div>
    <table class="w-full text-sm">
        <tbody class="divide-y">
            <tr><td class="py-1">Ordered value</td><td class="py-1 text-right">{{ number_format($totals['ordered_value'], 0) }}</td></tr>
            <tr><td class="py-1">Received value (against these POs)</td><td class="py-1 text-right">{{ number_format($totals['received_value_from_pos'], 0) }}</td></tr>
            <tr><td class="py-1">Outstanding value (ordered, not yet received)</td><td class="py-1 text-right">{{ number_format($totals['outstanding_value'], 0) }}</td></tr>
            <tr><td class="py-1">Ad-hoc receipts (no purchase order)</td><td class="py-1 text-right">{{ number_format($totals['ad_hoc_value'], 0) }}</td></tr>
            <tr class="font-semibold"><td class="py-1">Total goods received value</td><td class="py-1 text-right">{{ number_format($totals['total_received_value'], 0) }}</td></tr>
        </tbody>
    </table>
</div>

<div class="rounded-lg bg-white shadow-sm mb-6 overflow-x-auto">
    <div class="p-3 font-medium border-b">Purchase orders</div>
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">PO #</th><th class="p-3">Date</th><th class="p-3">Supplier</th><th class="p-3">Status</th><th class="p-3 text-right">Ordered</th><th class="p-3 text-right">Received</th><th class="p-3 text-right">Outstanding</th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($purchase_orders as $row)
                <tr>
                    <td class="p-3">
                        @can('manage-purchase-orders')
                            <a href="{{ route('inventory.purchase-orders.show', $row['po']) }}" class="text-slate-700 underline">{{ $row['po']->po_number }}</a>
                        @else
                            {{ $row['po']->po_number }}
                        @endcan
                    </td>
                    <td class="p-3">{{ $row['po']->created_at->format('d M Y') }}</td>
                    <td class="p-3">{{ $row['po']->supplier->name ?? '—' }}</td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $row['po']->status }}</span></td>
                    <td class="p-3 text-right">{{ number_format($row['ordered_value'], 0) }}</td>
                    <td class="p-3 text-right">{{ number_format($row['received_value'], 0) }}</td>
                    <td class="p-3 text-right">{{ number_format($row['outstanding_value'], 0) }}</td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="7">No purchase orders raised in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>

<div class="rounded-lg bg-white shadow-sm overflow-x-auto">
    <div class="p-3 font-medium border-b">Ad-hoc receipts (no purchase order)</div>
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Receipt #</th><th class="p-3">Date</th><th class="p-3">Supplier</th><th class="p-3">Received by</th><th class="p-3 text-right">Value</th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($ad_hoc_receipts as $receipt)
                <tr>
                    <td class="p-3">{{ $receipt->receipt_number }}</td>
                    <td class="p-3">{{ $receipt->received_at->format('d M Y') }}</td>
                    <td class="p-3">{{ $receipt->supplier->name ?? '—' }}</td>
                    <td class="p-3">{{ $receipt->receivedBy->name ?? '—' }}</td>
                    <td class="p-3 text-right">{{ number_format($receipt->items->sum('line_total'), 0) }}</td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="5">No ad-hoc receipts in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
