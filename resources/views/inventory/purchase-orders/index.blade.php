@extends('layouts.app')
@section('title', 'Purchase Orders')
@section('content')
<div class="mb-4 flex justify-between items-center">
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (['draft', 'submitted', 'approved', 'rejected', 'received', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
    @can('manage-purchase-orders')
        <a href="{{ route('inventory.purchase-orders.create') }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New purchase order</a>
    @endcan
</div>

<div class="overflow-hidden rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">PO #</th><th class="p-3">Supplier</th><th class="p-3">Status</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($purchaseOrders as $po)
                <tr>
                    <td class="p-3">{{ $po->po_number }}</td>
                    <td class="p-3">{{ $po->supplier->name }}</td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $po->status }}</span></td>
                    <td class="p-3"><a href="{{ route('inventory.purchase-orders.show', $po) }}" class="text-slate-600 hover:underline">Open</a></td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="4">No purchase orders yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $purchaseOrders->links() }}
@endsection
