@extends('layouts.app')
@section('title', 'Products')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="p-3">SKU</th><th class="p-3">Name</th><th class="p-3">Category</th>
                    <th class="p-3 text-right">On hand</th><th class="p-3 text-right">Reorder level</th>
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($products as $product)
                    @php $onHand = (float) $product->quantityOnHand(); @endphp
                    <tr class="{{ $product->isBelowReorderLevel() ? 'bg-red-50' : '' }}">
                        <td class="p-3 font-mono text-xs">{{ $product->sku }}</td>
                        <td class="p-3">{{ $product->name }}</td>
                        <td class="p-3 text-slate-500">{{ $product->category ?? '—' }}</td>
                        <td class="p-3 text-right">{{ rtrim(rtrim(number_format($onHand, 3), '0'), '.') }} {{ $product->unit->abbreviation }}</td>
                        <td class="p-3 text-right text-slate-500">{{ $product->reorder_level }} {{ $product->unit->abbreviation }}</td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="5">No products yet — add one to get started.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-span-1 space-y-4">
        <form method="POST" action="{{ route('inventory.products.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">New product</div>
            <input name="sku" placeholder="SKU" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="name" placeholder="Name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <select name="unit_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Unit…</option>
                @foreach ($units as $unit)
                    <option value="{{ $unit->id }}">{{ $unit->name }} ({{ $unit->abbreviation }})</option>
                @endforeach
            </select>
            <input name="category" placeholder="Category (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input type="number" step="0.001" min="0" name="reorder_level" placeholder="Reorder level" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add product</button>
        </form>

        <form method="POST" action="{{ route('inventory.units.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">New unit of measure</div>
            <input name="name" placeholder="e.g. Kilogram" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="abbreviation" placeholder="e.g. kg" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Add unit</button>
        </form>
    </div>
</div>
@endsection
