@extends('layouts.app')
@section('title', 'Recipe — ' . $item->name)
@section('content')
<div class="mb-4">
    <a href="{{ route('pos.menu.index', $outlet) }}" class="text-sm text-slate-600 hover:underline">&larr; Back to menu</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <div class="border-b p-3 text-sm font-semibold">{{ $item->name }} — ingredients</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Product</th><th class="p-3 text-right">Qty per unit sold</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($item->recipeItems as $recipeItem)
                    <tr>
                        <td class="p-3">{{ $recipeItem->product->name }}</td>
                        <td class="p-3 text-right">{{ $recipeItem->quantity_per_unit }} {{ $recipeItem->product->unit->abbreviation }}</td>
                        <td class="p-3">
                            <form method="POST" action="{{ route('pos.menu.items.recipe.remove', [$outlet, $item, $recipeItem]) }}">
                                @csrf
                                <button class="text-red-600 hover:underline text-xs">Remove</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="3">No recipe configured yet — this item is not tracked against stock.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-span-1 hms-side-panel">
        <form method="POST" action="{{ route('pos.menu.items.recipe.store', [$outlet, $item]) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Add / update ingredient</div>
            <select name="product_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Product…</option>
                @foreach ($products as $product)
                    <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->unit->abbreviation }})</option>
                @endforeach
            </select>
            <input type="number" step="0.001" min="0.001" name="quantity_per_unit" placeholder="Quantity per unit sold" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Save</button>
        </form>
    </div>
</div>
@endsection
