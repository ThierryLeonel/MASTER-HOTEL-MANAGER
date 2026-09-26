@extends('layouts.app')
@section('title', 'Products')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 overflow-x-auto rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr>
                    <th class="p-3">SKU</th><th class="p-3">Name</th><th class="p-3">Category</th>
                    <th class="p-3 text-right">On hand</th><th class="p-3 text-right">Reorder level</th>
                    @can('manage-products')<th class="p-3"></th>@endcan
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($products as $product)
                    @php $onHand = (float) $product->quantityOnHand(); @endphp
                    <tr class="{{ $product->isBelowReorderLevel() ? 'bg-red-50' : '' }} {{ $product->is_active ? '' : 'opacity-50' }}">
                        <td class="p-3 font-mono text-xs">{{ $product->sku }}</td>
                        <td class="p-3">{{ $product->name }}@unless ($product->is_active)<span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px]">Inactive</span>@endunless</td>
                        <td class="p-3 text-slate-500">{{ $product->category ?? '—' }}</td>
                        <td class="p-3 text-right">{{ rtrim(rtrim(number_format($onHand, 3), '0'), '.') }} {{ $product->unit->abbreviation }}</td>
                        <td class="p-3 text-right text-slate-500">{{ $product->reorder_level }} {{ $product->unit->abbreviation }}</td>
                        @can('manage-products')
                            <td class="p-3">
                                <details>
                                    <summary class="cursor-pointer text-xs text-slate-600 underline">Edit</summary>
                                    <form method="POST" action="{{ route('inventory.products.update', $product) }}" class="mt-2 w-56 space-y-1">
                                        @csrf @method('PUT')
                                        <input name="sku" value="{{ $product->sku }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <input name="name" value="{{ $product->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <select name="unit_id" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                            @foreach ($units as $unit)
                                                <option value="{{ $unit->id }}" @selected($unit->id === $product->unit_id)>{{ $unit->name }}</option>
                                            @endforeach
                                        </select>
                                        <input name="category" value="{{ $product->category }}" placeholder="Category" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <input type="number" step="0.001" min="0" name="reorder_level" value="{{ $product->reorder_level }}" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" value="1" @checked($product->is_active)> Active</label>
                                        <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('inventory.products.destroy', $product) }}" class="mt-1"
                                          onsubmit="return confirm(@js('Delete product '.$product->name.' permanently? Only a product with no purchasing/usage history can be deleted — otherwise deactivate it instead.'));">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-700 hover:underline">Delete</button>
                                    </form>
                                </details>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="6">No products yet — add one to get started.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-span-1 space-y-4 hms-side-panel">
        <form method="POST" action="{{ route('inventory.products.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">New product</div>
            <input name="name" placeholder="Name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <p class="text-[11px] text-slate-400">A SKU is generated automatically once you save (editable afterwards).</p>
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

        @can('manage-products')
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <div class="text-xs font-medium mb-2">Units of measure</div>
                <ul class="space-y-1.5 text-xs">
                    @foreach ($units as $unit)
                        <li class="flex items-center justify-between">
                            <span>{{ $unit->name }} ({{ $unit->abbreviation }})</span>
                            <details class="inline-block">
                                <summary class="cursor-pointer text-slate-600 underline">Edit</summary>
                                <form method="POST" action="{{ route('inventory.units.update', $unit) }}" class="mt-1 flex gap-1">
                                    @csrf @method('PUT')
                                    <input name="name" value="{{ $unit->name }}" required class="w-24 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                    <input name="abbreviation" value="{{ $unit->abbreviation }}" required class="w-14 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                    <button class="rounded bg-slate-800 px-2 py-0.5 text-xs text-white">Save</button>
                                </form>
                                <form method="POST" action="{{ route('inventory.units.destroy', $unit) }}" class="mt-1"
                                      onsubmit="return confirm('Delete this unit? Only a unit with no products can be deleted.');">
                                    @csrf @method('DELETE')
                                    <button class="text-red-700 hover:underline">Delete</button>
                                </form>
                            </details>
                        </li>
                    @endforeach
                </ul>
            </div>
        @endcan
    </div>
</div>
@endsection
