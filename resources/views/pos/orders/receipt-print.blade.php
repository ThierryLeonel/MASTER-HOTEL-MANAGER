@extends('layouts.print-thermal')
@section('title', \Illuminate\Support\Str::headline($outlet) . ' Receipt — ' . $order->order_number)
@section('document-title', \Illuminate\Support\Str::headline($outlet) . ' Receipt')
@section('content')

<div class="row"><span>Order</span><span class="bold">{{ $order->order_number }}</span></div>
<div class="row"><span>Table</span><span>{{ $order->table->label ?? 'No table' }}</span></div>
<div class="row"><span>Served by</span><span>{{ $order->openedBy->name }}</span></div>
@if ($order->closedBy)
    <div class="row"><span>Closed by</span><span>{{ $order->closedBy->name }}</span></div>
    <div class="row muted"><span>&nbsp;</span><span>{{ $order->closed_at->format('d M Y H:i') }}</span></div>
@endif

<hr class="divider">

@foreach ($order->items as $item)
    @unless ($item->isVoided())
        <div class="item-row">
            <span class="item-name">{{ $item->item_name }}</span>
            <div class="item-meta">
                <span>{{ $item->quantity }} &times; {{ number_format($item->unit_price, 0) }}</span>
                <span>{{ number_format($item->line_total, 0) }}</span>
            </div>
        </div>
    @endunless
@endforeach

<hr class="divider">

{{--
    VAT/tax no longer applies to POS sales at all (OrderService::
    recalculateTotals() always zeroes tax_amount now) and never appears
    on this or any other receipt — subtotal and total are identical, so
    only Total is shown to avoid an equally-confusing "Subtotal ... Total"
    pair that differ by nothing.
--}}
<div class="total-row"><span>TOTAL</span><span>{{ number_format($order->total_amount, 0) }} XAF</span></div>

@if ($order->payment_method)
    <div class="center muted" style="margin-top: 3mm;">Paid by {{ str_replace('_', ' ', $order->payment_method) }}</div>
@endif

<div class="center muted" style="margin-top: 4mm;">Thank you!</div>
@endsection
