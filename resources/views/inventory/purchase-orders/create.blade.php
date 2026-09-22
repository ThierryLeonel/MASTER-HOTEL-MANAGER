@extends('layouts.app')
@section('title', 'New Purchase Order')
@section('content')
<div class="mb-4">
    <a href="{{ route('inventory.purchase-orders.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to purchase orders</a>
</div>

<form method="POST" action="{{ route('inventory.purchase-orders.store') }}" class="max-w-3xl space-y-4">
    @csrf
    <div class="rounded-lg bg-white p-4 shadow-sm space-y-2">
        <select name="supplier_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">Supplier…</option>
            @foreach ($suppliers as $supplier)
                <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
            @endforeach
        </select>
        <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
    </div>

    <div class="rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Product</th><th class="p-3">Quantity</th><th class="p-3">Unit cost (XAF)</th></tr>
            </thead>
            <tbody class="divide-y">
                @for ($i = 0; $i < 8; $i++)
                    <tr>
                        <td class="p-2">
                            <select name="product_id[]" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                                <option value="">—</option>
                                @foreach ($products as $product)
                                    <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->unit->abbreviation }})</option>
                                @endforeach
                            </select>
                        </td>
                        <td class="p-2"><input type="number" step="0.001" min="0" name="quantity_ordered[]" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></td>
                        <td class="p-2"><input type="number" step="0.01" min="0" name="unit_cost[]" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></td>
                    </tr>
                @endfor
            </tbody>
        </table>
    </div>

    <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Create draft purchase order</button>
</form>
@endsection
