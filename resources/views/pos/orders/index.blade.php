@extends('layouts.app')
@section('title', \Illuminate\Support\Str::headline($outlet) . ' POS')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <div class="flex gap-2">
        <a href="{{ route('pos.index', $outlet) }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white">Orders</a>
        @can('manage-menu')
            <a href="{{ route('pos.menu.index', $outlet) }}" class="rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Manage menu</a>
        @endcan
    </div>
    <form method="POST" action="{{ route('pos.orders.store', $outlet) }}" class="flex items-center gap-2" id="new-order-form">
        @csrf
        <select name="pos_table_id" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">No table (bar counter / takeaway)</option>
            @foreach ($tables as $table)
                @if ($table->status === 'available')
                    <option value="{{ $table->id }}">{{ $table->label }} ({{ $table->seats }} seats)</option>
                @endif
            @endforeach
        </select>
        {{-- Feature 4 ("a 500 XAF room-service delivery charge"): marking
             this at order-open time, before any item is added, matches
             how OrderService::open() applies the fee. --}}
        <label class="flex items-center gap-1 text-xs text-slate-600">
            <input type="checkbox" name="is_room_delivery" value="1" onchange="document.getElementById('delivery-stay-select').classList.toggle('hidden', !this.checked)">
            Room delivery (+500 XAF)
        </label>
        <select name="delivery_destination_stay_id" id="delivery-stay-select" class="hidden rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">Deliver to room…</option>
            @foreach ($activeStays as $stay)
                <option value="{{ $stay->id }}">{{ $stay->room->room_number }} — {{ $stay->guest->full_name }}</option>
            @endforeach
        </select>
        <button class="btn-action rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New order</button>
    </form>
</div>

{{-- Post-9.8 fix round ("a search bar to search saved orders" — these
     open, not-yet-closed draft/confirmed orders ARE the "saved orders";
     see OrderController::index()'s comment). --}}
<form method="GET" action="{{ route('pos.index', $outlet) }}" class="mb-4">
    <input type="text" name="search" value="{{ request('search') }}" placeholder="Search saved orders by order # or table…" class="w-full max-w-sm rounded border border-slate-300 px-3 py-1.5 text-sm">
</form>

<div class="overflow-hidden rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Order #</th><th class="p-3">Table</th><th class="p-3">Status</th><th class="p-3 text-right">Total</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($openOrders as $order)
                <tr>
                    <td class="p-3">{{ $order->order_number }}</td>
                    <td class="p-3">{{ $order->table->label ?? '—' }}</td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $order->status }}</span></td>
                    <td class="p-3 text-right">{{ number_format($order->total_amount, 0) }} XAF</td>
                    <td class="p-3"><a href="{{ route('pos.orders.show', [$outlet, $order]) }}" class="text-slate-600 hover:underline">Open</a></td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="5">No open orders.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
