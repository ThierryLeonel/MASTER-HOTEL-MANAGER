@extends('layouts.print')
@section('title', 'Inventory Report — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Inventory Report')
@section('content')

<p class="text-xs text-slate-500 mb-4">Stock valuation and low stock as of today; wastage cost for {{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}.</p>

<p class="text-xs font-medium mb-1">Stock valuation (as of today)</p>
<table class="w-full text-sm mb-6">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Product</th><th class="py-1 text-right">Qty on hand</th><th class="py-1 text-right">Value</th></tr></thead>
    <tbody class="divide-y">
        @foreach ($valuation['lines'] as $line)
            <tr>
                <td class="py-1">{{ $line['product']->name }}</td>
                <td class="py-1 text-right">{{ $line['quantity_on_hand'] }} {{ $line['product']->unit->abbreviation }}</td>
                <td class="py-1 text-right">{{ number_format($line['value'], 0) }}</td>
            </tr>
        @endforeach
        <tr class="font-semibold border-t border-slate-300"><td colspan="2" class="py-1">Total value</td><td class="py-1 text-right">{{ number_format($valuation['total_value'], 0) }}</td></tr>
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Wastage cost (period)</p>
<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        @forelse ($wastage['by_product'] as $row)
            <tr><td class="py-1">{{ $row['product']->name }}</td><td class="py-1 text-right">{{ $row['quantity'] }}</td><td class="py-1 text-right">{{ number_format($row['cost'], 0) }}</td></tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="3">No approved wastage in this period.</td></tr>
        @endforelse
        <tr class="font-semibold border-t border-slate-300"><td colspan="2" class="py-1">Total cost</td><td class="py-1 text-right">{{ number_format($wastage['total_cost'], 0) }}</td></tr>
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Low stock (at or below reorder level)</p>
<table class="w-full text-sm">
    <tbody class="divide-y">
        @forelse ($lowStock as $product)
            <tr><td class="py-1">{{ $product->name }}</td><td class="py-1 text-right">{{ $product->quantityOnHand() }} / {{ $product->reorder_level }} {{ $product->unit->abbreviation }}</td></tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="2">Nothing below its reorder level.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
