@extends('layouts.app')
@section('title', $purchaseOrder->po_number)
@section('content')
<div class="mb-4">
    <a href="{{ route('inventory.purchase-orders.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to purchase orders</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 space-y-6">
        <div class="rounded-lg bg-white p-4 shadow-sm text-sm flex justify-between">
            <div>
                <div class="font-semibold">{{ $purchaseOrder->po_number }}</div>
                <div class="text-slate-500">{{ $purchaseOrder->supplier->name }} · Ordered by {{ $purchaseOrder->orderedBy->name }}</div>
            </div>
            <span class="h-fit rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $purchaseOrder->status }}</span>
        </div>

        <div class="rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Product</th><th class="p-3 text-right">Ordered</th><th class="p-3 text-right">Received</th><th class="p-3 text-right">Unit cost</th><th class="p-3 text-right">Line total</th></tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($purchaseOrder->items as $item)
                        <tr>
                            <td class="p-3">{{ $item->product->name }}</td>
                            <td class="p-3 text-right">{{ $item->quantity_ordered }} {{ $item->product->unit->abbreviation }}</td>
                            <td class="p-3 text-right">{{ $item->quantity_received }} {{ $item->product->unit->abbreviation }}</td>
                            <td class="p-3 text-right">{{ number_format($item->unit_cost, 2) }}</td>
                            <td class="p-3 text-right">{{ number_format($item->line_total, 2) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($purchaseOrder->status === 'approved')
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <div class="text-xs font-medium mb-2">Receive goods against this PO</div>
                <form method="POST" action="{{ route('inventory.purchase-orders.receive', $purchaseOrder) }}">
                    @csrf
                    <table class="w-full text-sm mb-3">
                        <thead class="text-left text-xs uppercase text-slate-500">
                            <tr><th class="py-1">Product</th><th class="py-1 text-right">Outstanding</th><th class="py-1 text-right">Receive now</th></tr>
                        </thead>
                        <tbody class="divide-y">
                            @foreach ($purchaseOrder->items as $item)
                                @if ((float) $item->quantityOutstanding() > 0)
                                    <tr>
                                        <td class="py-1">
                                            {{ $item->product->name }}
                                            <input type="hidden" name="purchase_order_item_id[]" value="{{ $item->id }}">
                                        </td>
                                        <td class="py-1 text-right">{{ $item->quantityOutstanding() }} {{ $item->product->unit->abbreviation }}</td>
                                        <td class="py-1 text-right"><input type="number" step="0.001" min="0" max="{{ $item->quantityOutstanding() }}" name="quantity_received[]" class="w-28 rounded border border-slate-300 px-2 py-1 text-sm text-right"></td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                    <button class="rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Record receipt</button>
                </form>
            </div>
        @endif
    </div>

    <div class="col-span-1 space-y-3 hms-side-panel">
        @if ($purchaseOrder->status === 'draft')
            <form method="POST" action="{{ route('inventory.purchase-orders.submit', $purchaseOrder) }}">
                @csrf
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Submit for approval</button>
            </form>
        @endif

        @can('approve-purchase-order')
            @if ($purchaseOrder->status === 'submitted')
                <form method="POST" action="{{ route('inventory.purchase-orders.approve', $purchaseOrder) }}">
                    @csrf
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Approve</button>
                </form>
                <form method="POST" action="{{ route('inventory.purchase-orders.reject', $purchaseOrder) }}" onsubmit="return promptReason(this)">
                    @csrf
                    <input type="hidden" name="reason" value="">
                    <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Reject</button>
                </form>
            @endif
        @endcan

        @if (in_array($purchaseOrder->status, ['draft', 'submitted']))
            <form method="POST" action="{{ route('inventory.purchase-orders.cancel', $purchaseOrder) }}" onsubmit="return promptReason(this)">
                @csrf
                <input type="hidden" name="reason" value="">
                <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Cancel</button>
            </form>
        @endif

        @if ($purchaseOrder->rejection_reason)
            <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">Rejected: {{ $purchaseOrder->rejection_reason }}</div>
        @endif
    </div>
</div>

<script>
function promptReason(form) {
    const reason = prompt('Reason:');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
