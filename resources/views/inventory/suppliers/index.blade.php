@extends('layouts.app')
@section('title', 'Suppliers')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Name</th><th class="p-3">Contact</th><th class="p-3">Phone</th><th class="p-3">Email</th>
                    @can('manage-suppliers')<th class="p-3"></th>@endcan
                </tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($suppliers as $supplier)
                    <tr class="{{ $supplier->is_active ? '' : 'opacity-50' }}">
                        <td class="p-3">{{ $supplier->name }}@unless ($supplier->is_active)<span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px]">Inactive</span>@endunless</td>
                        <td class="p-3">{{ $supplier->contact_name ?? '—' }}</td>
                        <td class="p-3">{{ $supplier->phone ?? '—' }}</td>
                        <td class="p-3">{{ $supplier->email ?? '—' }}</td>
                        @can('manage-suppliers')
                            <td class="p-3">
                                <details>
                                    <summary class="cursor-pointer text-xs text-slate-600 underline">Edit</summary>
                                    <form method="POST" action="{{ route('inventory.suppliers.update', $supplier) }}" class="mt-2 w-56 space-y-1">
                                        @csrf @method('PUT')
                                        <input name="name" value="{{ $supplier->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <input name="contact_name" value="{{ $supplier->contact_name }}" placeholder="Contact" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <input name="phone" value="{{ $supplier->phone }}" placeholder="Phone" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <input name="email" value="{{ $supplier->email }}" placeholder="Email" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                        <textarea name="address" rows="2" placeholder="Address" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">{{ $supplier->address }}</textarea>
                                        <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" value="1" @checked($supplier->is_active)> Active</label>
                                        <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('inventory.suppliers.destroy', $supplier) }}" class="mt-1"
                                          onsubmit="return confirm(@js('Delete supplier '.$supplier->name.' permanently? Only a supplier with no purchase/receipt history can be deleted — otherwise deactivate it instead.'));">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-700 hover:underline">Delete</button>
                                    </form>
                                </details>
                            </td>
                        @endcan
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="5">No suppliers yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-span-1">
        <form method="POST" action="{{ route('inventory.suppliers.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">New supplier</div>
            <input name="name" placeholder="Company name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="contact_name" placeholder="Contact person" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="phone" placeholder="Phone" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="email" type="email" placeholder="Email" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <textarea name="address" placeholder="Address" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add supplier</button>
        </form>
    </div>
</div>
@endsection
