@extends('layouts.app')
@section('title', 'Goods Receipts')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Receipt #</th><th class="p-3">PO</th><th class="p-3">Supplier</th><th class="p-3">Received by</th><th class="p-3">Received at</th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($goodsReceipts as $receipt)
                    <tr>
                        <td class="p-3">{{ $receipt->receipt_number }}</td>
                        <td class="p-3">{{ $receipt->purchaseOrder->po_number ?? '— (ad-hoc)' }}</td>
                        <td class="p-3">{{ $receipt->supplier->name ?? '—' }}</td>
                        <td class="p-3">{{ $receipt->receivedBy->name }}</td>
                        <td class="p-3">{{ $receipt->received_at->format('Y-m-d H:i') }}</td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="5">No goods receipts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-span-1">
        <form method="POST" action="{{ route('inventory.goods-receipts.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Record a receipt</div>
            {{--
                "Goods received should be able to ref purchase orders for
                proper accountability": picking a PO here matches each line
                below to that PO's own item (by product) so quantity_received
                bookkeeping on the PO stays correct, exactly as if this had
                been received from the PO's own page. Leave it unset for a
                genuinely ad-hoc delivery that never had a PO.
            --}}
            <select name="purchase_order_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">No purchase order (ad-hoc)…</option>
                @foreach ($openPurchaseOrders as $po)
                    <option value="{{ $po->id }}">{{ $po->po_number }} — {{ $po->supplier->name ?? 'no supplier' }}</option>
                @endforeach
            </select>
            <select name="supplier_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Supplier (optional, if not receiving against a PO)…</option>
                @foreach ($suppliers as $supplier)
                    <option value="{{ $supplier->id }}">{{ $supplier->name }}</option>
                @endforeach
            </select>
            @for ($i = 0; $i < 4; $i++)
                <div class="grid grid-cols-1 md:grid-cols-3 gap-1">
                    <select name="product_id[]" class="col-span-1 rounded border border-slate-300 px-1 py-1.5 text-xs">
                        <option value="">—</option>
                        @foreach ($products as $product)
                            <option value="{{ $product->id }}">{{ $product->name }}</option>
                        @endforeach
                    </select>
                    <input type="number" step="0.001" min="0" name="quantity_received[]" placeholder="Qty" class="rounded border border-slate-300 px-1 py-1.5 text-xs">
                    <input type="number" step="0.01" min="0" name="unit_cost[]" placeholder="Cost" class="rounded border border-slate-300 px-1 py-1.5 text-xs">
                </div>
            @endfor
            <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Record receipt</button>
        </form>
    </div>
</div>
@endsection
