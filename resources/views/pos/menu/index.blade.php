@extends('layouts.app')
@section('title', \Illuminate\Support\Str::headline($outlet) . ' Menu')
@section('content')
<div class="mb-4">
    <a href="{{ route('pos.index', $outlet) }}" class="text-sm text-slate-600 hover:underline">&larr; Back to orders</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 space-y-4">
        @forelse ($categories as $category)
            <div class="rounded-lg bg-white shadow-sm">
                <div class="border-b p-3 text-sm font-semibold">{{ $category->name }}</div>
                <table class="w-full text-sm">
                    <tbody class="divide-y">
                        @forelse ($category->items as $item)
                            <tr>
                                <td class="p-3">{{ $item->name }}</td>
                                <td class="p-3 text-right">{{ number_format($item->price, 0) }} XAF</td>
                                <td class="p-3"><a href="{{ route('pos.menu.items.recipe', [$outlet, $item]) }}" class="text-slate-600 hover:underline text-xs">Recipe</a></td>
                            </tr>
                        @empty
                            <tr><td class="p-3 text-slate-500">No items yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        @empty
            <p class="text-slate-500 text-sm">No categories yet — add one to get started.</p>
        @endforelse
    </div>

    <div class="col-span-1 space-y-4">
        <form method="POST" action="{{ route('pos.menu.categories.store', $outlet) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">New category</div>
            <input name="name" placeholder="e.g. Cocktails" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add category</button>
        </form>

        <form method="POST" action="{{ route('pos.menu.items.store', $outlet) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">New menu item</div>
            <select name="menu_category_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Category…</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}">{{ $category->name }}</option>
                @endforeach
            </select>
            <input name="name" placeholder="Item name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input type="number" step="0.01" name="price" placeholder="Price (XAF)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <textarea name="description" placeholder="Description (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add item</button>
        </form>
    </div>
</div>
@endsection
