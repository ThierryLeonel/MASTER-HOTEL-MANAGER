@extends('layouts.print')
@section('title', 'Purchase Report — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Purchase Report')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2">Ordered value</td><td class="py-2 text-right">{{ number_format($totals['ordered_value'], 0) }}</td></tr>
        <tr><td class="py-2">Received value (against these POs)</td><td class="py-2 text-right">{{ number_format($totals['received_value_from_pos'], 0) }}</td></tr>
        <tr><td class="py-2">Outstanding value</td><td class="py-2 text-right">{{ number_format($totals['outstanding_value'], 0) }}</td></tr>
        <tr><td class="py-2">Ad-hoc receipts</td><td class="py-2 text-right">{{ number_format($totals['ad_hoc_value'], 0) }}</td></tr>
        <tr class="font-semibold"><td class="py-2">Total goods received value</td><td class="py-2 text-right">{{ number_format($totals['total_received_value'], 0) }}</td></tr>
    </tbody>
</table>

<table class="w-full text-sm mb-6">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
        <tr><th class="py-2">PO #</th><th class="py-2">Date</th><th class="py-2">Supplier</th><th class="py-2">Status</th><th class="py-2 text-right">Ordered</th><th class="py-2 text-right">Received</th><th class="py-2 text-right">Outstanding</th></tr>
    </thead>
    <tbody class="divide-y">
        @foreach ($purchase_orders as $row)
            <tr>
                <td class="py-2">{{ $row['po']->po_number }}</td>
                <td class="py-2">{{ $row['po']->created_at->format('d M Y') }}</td>
                <td class="py-2">{{ $row['po']->supplier->name ?? '—' }}</td>
                <td class="py-2">{{ $row['po']->status }}</td>
                <td class="py-2 text-right">{{ number_format($row['ordered_value'], 0) }}</td>
                <td class="py-2 text-right">{{ number_format($row['received_value'], 0) }}</td>
                <td class="py-2 text-right">{{ number_format($row['outstanding_value'], 0) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>

<table class="w-full text-sm">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
        <tr><th class="py-2">Ad-hoc receipt #</th><th class="py-2">Date</th><th class="py-2">Supplier</th><th class="py-2 text-right">Value</th></tr>
    </thead>
    <tbody class="divide-y">
        @foreach ($ad_hoc_receipts as $receipt)
            <tr>
                <td class="py-2">{{ $receipt->receipt_number }}</td>
                <td class="py-2">{{ $receipt->received_at->format('d M Y') }}</td>
                <td class="py-2">{{ $receipt->supplier->name ?? '—' }}</td>
                <td class="py-2 text-right">{{ number_format($receipt->items->sum('line_total'), 0) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
@endsection
