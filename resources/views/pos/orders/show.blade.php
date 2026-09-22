@extends('layouts.app')
@section('title', $order->order_number)
@section('content')
<div class="grid grid-cols-1 gap-6 lg:grid-cols-3">
    {{--
        Post-9.8 fix round ("let the bar and restaurant designs for the
        orders be a click and add to cart"): this used to be a single
        <select> + "Add" button that reloaded the page for every item.
        It's now a grid of clickable menu tiles (grouped into category
        tabs) that add to the cart instantly via fetch() — see the script
        at the bottom. The old menu_item_id <select> form still exists
        (see the "manual add" fallback within #cart), just no longer the
        primary way to add an item, so this still works with JavaScript
        disabled.
    --}}
    <div class="lg:col-span-2 space-y-4">
        @if ($order->isEditable())
            <div class="rounded-lg bg-white p-4 shadow-sm">
                <div class="mb-3 flex flex-wrap gap-2" id="category-tabs">
                    <button type="button" class="btn-action category-tab is-selected rounded-full bg-slate-800 px-3 py-1 text-xs text-white" data-category="all">All</button>
                    @foreach ($menuItems->pluck('category.name', 'category.id')->filter()->unique() as $catId => $catName)
                        <button type="button" class="btn-action category-tab rounded-full bg-slate-200 px-3 py-1 text-xs hover:bg-slate-300" data-category="cat-{{ $catId }}">{{ $catName }}</button>
                    @endforeach
                </div>
                <div class="grid grid-cols-2 gap-2 sm:grid-cols-3 md:grid-cols-4">
                    @foreach ($menuItems as $item)
                        <button type="button"
                            class="btn-action menu-tile cat-{{ $item->menu_category_id }} rounded-lg border border-slate-200 bg-slate-50 p-3 text-left text-sm hover:border-slate-400 hover:bg-white"
                            data-item-id="{{ $item->id }}" data-item-name="{{ $item->name }}">
                            <div class="font-medium">{{ $item->name }}</div>
                            <div class="text-xs text-slate-500">{{ number_format($item->price, 0) }} XAF</div>
                        </button>
                    @endforeach
                </div>
                <p id="tap-hint" class="mt-2 text-xs text-slate-500">Tap an item to add it to the order.</p>
            </div>
        @endif

        <div class="rounded-lg bg-white p-4 shadow-sm text-sm flex justify-between">
            <div>
                <div class="font-semibold">{{ $order->order_number }}</div>
                <div class="text-slate-500">{{ $order->table->label ?? 'No table' }} · Opened by {{ $order->openedBy->name }}</div>
            </div>
            <div class="text-right">
                <div class="text-2xl font-semibold" id="cart-total">{{ number_format($order->total_amount, 0) }} XAF</div>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs" id="cart-status">{{ $order->status }}</span>
                <div class="mt-1 text-xs"><a href="{{ route('pos.orders.receipt', [$outlet, $order]) }}" class="text-slate-500 underline">Print receipt</a></div>
            </div>
        </div>

        {{-- Feature 4 ("a 500 XAF room-service delivery charge"). --}}
        @if ($order->is_room_delivery)
            <div class="rounded-lg bg-white p-4 shadow-sm text-sm">
                <div class="flex items-center justify-between">
                    <div>
                        Room delivery to
                        <strong>{{ $order->deliveryDestinationStay?->room?->room_number ?? '—' }}</strong>
                        — fee {{ number_format($order->delivery_fee_amount, 0) }} XAF
                        @if ($order->delivery_fee_waived)
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800" title="{{ $order->delivery_fee_waive_reason }}">Waived</span>
                        @endif
                    </div>
                    @if ($order->isEditable() && ! $order->delivery_fee_waived)
                        <form method="POST" action="{{ route('pos.orders.delivery-fee.waive', [$outlet, $order]) }}" class="inline" onsubmit="return confirm('Waive this order\'s 500 XAF delivery fee?');">
                            @csrf
                            <input type="hidden" name="reason" value="Waived at front-line staff discretion">
                            <button class="text-xs text-amber-700 hover:underline">Waive fee</button>
                        </form>
                    @endif
                </div>
            </div>
        @endif

        <div class="rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Item</th><th class="p-3 text-right">Qty</th><th class="p-3 text-right">Unit</th><th class="p-3 text-right">Line total</th><th class="p-3"></th></tr>
                </thead>
                <tbody class="divide-y" id="cart-items">
                    @foreach ($order->items as $item)
                        <tr data-item-row="{{ $item->id }}" class="{{ $item->isVoided() ? 'opacity-50 line-through' : '' }}">
                            <td class="p-3">
                                {{ $item->item_name }}
                                @if ($item->is_complimentary)
                                    <span class="ml-1 rounded bg-purple-100 px-1.5 py-0.5 text-xs text-purple-800" title="{{ $item->comp_reason }}">Comp</span>
                                @endif
                            </td>
                            <td class="p-3 text-right">{{ $item->quantity }}</td>
                            <td class="p-3 text-right">{{ number_format($item->unit_price, 0) }}</td>
                            <td class="p-3 text-right">{{ number_format($item->line_total, 0) }}</td>
                            <td class="p-3">
                                @if (! $item->isVoided() && $order->isDraft())
                                    <form method="POST" action="{{ route('pos.orders.items.remove', [$outlet, $order, $item]) }}" class="remove-item-form" onsubmit="return promptReason(this)">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button type="submit" class="btn-action text-red-600 hover:underline text-xs">Remove</button>
                                    </form>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
            <div class="p-3 text-right text-xs text-slate-500 border-t">
                Total <strong id="cart-total-line">{{ number_format($order->total_amount, 0) }} XAF</strong>
            </div>
        </div>

        @if ($order->isEditable())
            <details class="rounded-lg bg-white p-4 shadow-sm text-sm">
                <summary class="cursor-pointer font-medium">Manual add / complimentary item</summary>
                <form method="POST" action="{{ route('pos.orders.items.store', [$outlet, $order]) }}" class="mt-3 space-y-2">
                    @csrf
                    <div class="flex gap-2">
                        <select name="menu_item_id" required class="flex-1 rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <option value="">Select item…</option>
                            @foreach ($menuItems as $item)
                                <option value="{{ $item->id }}">{{ $item->name }} — {{ number_format($item->price, 0) }} XAF</option>
                            @endforeach
                        </select>
                        <input type="number" name="quantity" value="1" min="1" class="w-20 rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <button class="btn-action rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add</button>
                    </div>
                    <label class="flex items-center gap-2 text-xs">
                        <input type="checkbox" name="is_complimentary" value="1" onchange="document.getElementById('comp_reason').classList.toggle('hidden', !this.checked)"> Complimentary (free — e.g. a welcome drink; other items on this order are unaffected)
                    </label>
                    <input id="comp_reason" name="comp_reason" placeholder="Reason (required for a complimentary item)" class="hidden w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                </form>
            </details>
            @if (! $order->isDraft())
                <p class="text-xs text-slate-500">This order is confirmed — existing items are locked, but you can still add new ones.</p>
            @endif
        @endif
    </div>

    <div class="lg:col-span-1 space-y-3">
        @if ($order->isDraft())
            <form method="POST" action="{{ route('pos.orders.confirm', [$outlet, $order]) }}">
                @csrf
                <button class="btn-action w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Confirm order</button>
            </form>

            {{-- "Save order" (post-9.8 fix round): items are already
                 persisted the instant they're added to the cart (each tap
                 is its own request), so "saving" a draft needs no extra
                 server call — this simply returns to the outlet's open
                 orders list, where this order (still a draft) is already
                 listed and can be reopened and edited later via the new
                 search box there. --}}
            <a href="{{ route('pos.index', $outlet) }}" class="btn-action block w-full rounded bg-slate-200 px-3 py-1.5 text-center text-sm hover:bg-slate-300">Save order &amp; back to list</a>
        @endif

        @if ($order->isConfirmed())
            <form method="POST" action="{{ route('pos.orders.close', [$outlet, $order]) }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Settle &amp; close</div>
                <select name="financial_account_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">— pay by folio instead —</option>
                    @foreach ($financialAccounts as $account)
                        <option value="{{ $account->id }}">{{ $account->name }}</option>
                    @endforeach
                </select>
                <select name="stay_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">— pay immediately instead —</option>
                    @foreach ($activeStays as $stay)
                        <option value="{{ $stay->id }}">Room {{ $stay->room->room_number }} — {{ $stay->guest->full_name }}</option>
                    @endforeach
                </select>
                <p class="text-xs text-slate-500">Choose a payment method OR a checked-in guest's room — not both.</p>
                <button class="btn-action w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Close order</button>
            </form>

            <a href="{{ route('pos.index', $outlet) }}" class="btn-action block w-full rounded bg-slate-200 px-3 py-1.5 text-center text-sm hover:bg-slate-300">Save order &amp; back to list</a>

            <form method="POST" action="{{ route('pos.orders.cancel', [$outlet, $order]) }}" onsubmit="return promptReason(this)" class="rounded-lg bg-white p-4 shadow-sm">
                @csrf
                <input type="hidden" name="reason" value="">
                <button class="btn-action w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Cancel order</button>
            </form>
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

(function () {
    const isEditable = {{ $order->isEditable() ? 'true' : 'false' }};
    if (!isEditable) return;

    const addItemUrl = '{{ route('pos.orders.items.store', [$outlet, $order]) }}';
    const csrfToken = document.querySelector('meta[name="csrf-token"]')?.content
        || document.querySelector('input[name="_token"]')?.value;

    // Category tab filtering.
    document.querySelectorAll('.category-tab').forEach(function (tab) {
        tab.addEventListener('click', function () {
            document.querySelectorAll('.category-tab').forEach(t => t.classList.remove('is-selected', 'bg-slate-800', 'text-white'));
            document.querySelectorAll('.category-tab').forEach(t => t.classList.add('bg-slate-200'));
            tab.classList.add('is-selected', 'bg-slate-800', 'text-white');
            tab.classList.remove('bg-slate-200');

            const cat = tab.dataset.category;
            document.querySelectorAll('.menu-tile').forEach(function (tile) {
                tile.style.display = (cat === 'all' || tile.classList.contains(cat)) ? '' : 'none';
            });
        });
    });

    // Click-to-cart: tapping a menu tile posts it straight to the order.
    document.querySelectorAll('.menu-tile').forEach(function (tile) {
        tile.addEventListener('click', function () {
            tile.disabled = true;
            const originalBg = tile.style.backgroundColor;
            tile.classList.add('is-selected');

            fetch(addItemUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json',
                    'X-CSRF-TOKEN': csrfToken,
                },
                body: JSON.stringify({ menu_item_id: tile.dataset.itemId, quantity: 1 }),
            })
                .then(r => r.json())
                .then(function (data) { renderCart(data.order); })
                .catch(function () {
                    document.getElementById('tap-hint').textContent = 'Could not add that item — check your connection and try again.';
                })
                .finally(function () {
                    tile.disabled = false;
                    setTimeout(() => tile.classList.remove('is-selected'), 200);
                });
        });
    });

    function renderCart(order) {
        document.getElementById('cart-total').textContent = formatMoney(order.total_amount) + ' XAF';
        document.getElementById('cart-total-line').textContent = formatMoney(order.total_amount) + ' XAF';
        document.getElementById('cart-status').textContent = order.status;

        const tbody = document.getElementById('cart-items');
        tbody.innerHTML = '';
        order.items.forEach(function (item) {
            if (item.voided) return;
            const tr = document.createElement('tr');
            tr.dataset.itemRow = item.id;
            tr.innerHTML =
                '<td class="p-3">' + escapeHtml(item.item_name) + (item.is_complimentary ? ' <span class="ml-1 rounded bg-purple-100 px-1.5 py-0.5 text-xs text-purple-800">Comp</span>' : '') + '</td>' +
                '<td class="p-3 text-right">' + item.quantity + '</td>' +
                '<td class="p-3 text-right">' + formatMoney(item.unit_price) + '</td>' +
                '<td class="p-3 text-right">' + formatMoney(item.line_total) + '</td>' +
                '<td class="p-3"><button type="button" class="btn-action text-red-600 hover:underline text-xs remove-item-btn" data-item-id="' + item.id + '">Remove</button></td>';
            tbody.appendChild(tr);
        });
        attachRemoveHandlers();
    }

    function attachRemoveHandlers() {
        document.querySelectorAll('.remove-item-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                const reason = prompt('Reason for removing this item:');
                if (!reason) return;
                fetch('{{ url('/pos/'.$outlet.'/orders/'.$order->id.'/items') }}/' + btn.dataset.itemId + '/remove', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',
                        'X-CSRF-TOKEN': csrfToken,
                    },
                    body: JSON.stringify({ reason: reason }),
                })
                    .then(r => r.json())
                    .then(function (data) { renderCart(data.order); })
                    .catch(function () { alert('Could not remove that item — check your connection and try again.'); });
            });
        });
    }

    function formatMoney(v) {
        return Math.round(parseFloat(v)).toLocaleString();
    }
    function escapeHtml(s) {
        const div = document.createElement('div');
        div.textContent = s;
        return div.innerHTML;
    }
})();
</script>
@endsection
