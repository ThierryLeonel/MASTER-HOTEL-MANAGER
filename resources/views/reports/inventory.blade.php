@extends('layouts.app')
@section('title', 'Inventory Report')
@section('content')
@include('reports._date-filter')

<div class="mb-4 text-xs">
    <a href="{{ route('reports.inventory.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
</div>

<div class="grid grid-cols-2 gap-6">
    <div class="rounded-lg bg-white shadow-sm">
        <div class="p-3 font-medium border-b">Stock valuation (as of today)</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Product</th><th class="p-3 text-right">Qty on hand</th><th class="p-3 text-right">Value</th></tr></thead>
            <tbody class="divide-y">
                @foreach ($valuation['lines'] as $line)
                    <tr>
                        <td class="p-3">{{ $line['product']->name }}</td>
                        <td class="p-3 text-right">{{ $line['quantity_on_hand'] }} {{ $line['product']->unit->abbreviation }}</td>
                        <td class="p-3 text-right">{{ number_format($line['value'], 0) }}</td>
                    </tr>
                @endforeach
                <tr class="font-semibold"><td class="p-3" colspan="2">Total value</td><td class="p-3 text-right">{{ number_format($valuation['total_value'], 0) }}</td></tr>
            </tbody>
        </table>
    </div>

    <div class="space-y-6">
        <div class="rounded-lg bg-white shadow-sm">
            <div class="p-3 font-medium border-b">Wastage cost (period)</div>
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    @forelse ($wastage['by_product'] as $row)
                        <tr><td class="p-3">{{ $row['product']->name }}</td><td class="p-3 text-right">{{ $row['quantity'] }}</td><td class="p-3 text-right">{{ number_format($row['cost'], 0) }}</td></tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="3">No approved wastage in this period.</td></tr>
                    @endforelse
                    <tr class="font-semibold"><td class="p-3" colspan="2">Total cost</td><td class="p-3 text-right">{{ number_format($wastage['total_cost'], 0) }}</td></tr>
                </tbody>
            </table>
        </div>

        <div class="rounded-lg bg-white shadow-sm">
            <div class="p-3 font-medium border-b">Low stock (at or below reorder level)</div>
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    @forelse ($lowStock as $product)
                        <tr><td class="p-3">{{ $product->name }}</td><td class="p-3 text-right">{{ $product->quantityOnHand() }} / {{ $product->reorder_level }} {{ $product->unit->abbreviation }}</td></tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="2">Nothing below its reorder level.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>
@endsection
