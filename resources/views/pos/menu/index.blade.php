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
                <div class="border-b p-3 text-sm font-semibold flex items-center justify-between">
                    <span>{{ $category->name }}</span>
                    @can('manage-menu')
                        <details class="text-xs font-normal">
                            <summary class="cursor-pointer text-slate-600 underline">Edit</summary>
                            <div class="mt-2 flex items-center gap-2">
                                <form method="POST" action="{{ route('pos.menu.categories.update', [$outlet, $category]) }}" class="flex gap-1">
                                    @csrf @method('PUT')
                                    <input name="name" value="{{ $category->name }}" required class="w-32 rounded border border-slate-300 px-1.5 py-1 text-xs">
                                    <button class="rounded bg-slate-800 px-2 py-1 text-xs text-white">Save</button>
                                </form>
                                <form method="POST" action="{{ route('pos.menu.categories.destroy', [$outlet, $category]) }}"
                                      onsubmit="return confirm(@js('Delete category '.$category->name.' permanently? Only a category with no menu items can be deleted.'));">
                                    @csrf @method('DELETE')
                                    <button class="text-red-700 hover:underline">Delete</button>
                                </form>
                            </div>
                        </details>
                    @endcan
                </div>
                <table class="w-full text-sm">
                    <tbody class="divide-y">
                        @forelse ($category->items as $item)
                            <tr>
                                <td class="p-3">{{ $item->name }}@unless ($item->is_active)<span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px]">Inactive</span>@endunless</td>
                                <td class="p-3 text-right">{{ number_format($item->price, 0) }} XAF</td>
                                <td class="p-3"><a href="{{ route('pos.menu.items.recipe', [$outlet, $item]) }}" class="text-slate-600 hover:underline text-xs">Recipe</a></td>
                                @can('manage-menu')
                                    <td class="p-3">
                                        <details>
                                            <summary class="cursor-pointer text-xs text-slate-600 underline">Edit</summary>
                                            <form method="POST" action="{{ route('pos.menu.items.update', [$outlet, $item]) }}" class="mt-2 w-56 space-y-1">
                                                @csrf @method('PUT')
                                                <select name="menu_category_id" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                                    @foreach ($categories as $cat)
                                                        <option value="{{ $cat->id }}" @selected($cat->id === $item->menu_category_id)>{{ $cat->name }}</option>
                                                    @endforeach
                                                </select>
                                                <input name="name" value="{{ $item->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                                <input type="number" step="0.01" min="0" name="price" value="{{ $item->price }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                                <textarea name="description" rows="2" placeholder="Description" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">{{ $item->description }}</textarea>
                                                <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" value="1" @checked($item->is_active)> Active</label>
                                                <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                                            </form>
                                            <form method="POST" action="{{ route('pos.menu.items.destroy', [$outlet, $item]) }}" class="mt-1"
                                                  onsubmit="return confirm(@js('Delete item '.$item->name.' permanently? Only an item with no orders, recipe, or breakfast history can be deleted — otherwise deactivate it instead.'));">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-red-700 hover:underline">Delete</button>
                                            </form>
                                        </details>
                                    </td>
                                @endcan
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
