@extends('layouts.app')
@section('title', 'Suppliers')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Name</th><th class="p-3">Contact</th><th class="p-3">Phone</th><th class="p-3">Email</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($suppliers as $supplier)
                    <tr>
                        <td class="p-3">{{ $supplier->name }}</td>
                        <td class="p-3">{{ $supplier->contact_name ?? '—' }}</td>
                        <td class="p-3">{{ $supplier->phone ?? '—' }}</td>
                        <td class="p-3">{{ $supplier->email ?? '—' }}</td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="4">No suppliers yet.</td></tr>
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
