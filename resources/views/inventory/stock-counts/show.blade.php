@extends('layouts.app')
@section('title', $stockCount->count_number)
@section('content')
<div class="mb-4">
    <a href="{{ route('inventory.stock-counts.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to stock counts</a>
</div>

<div class="rounded-lg bg-white p-4 shadow-sm text-sm flex justify-between mb-4">
    <div>
        <div class="font-semibold">{{ $stockCount->count_number }}</div>
        <div class="text-slate-500">Started by {{ $stockCount->startedBy->name }}</div>
    </div>
    <span class="h-fit rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $stockCount->status }}</span>
</div>

<div class="rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Product</th><th class="p-3 text-right">System qty</th>
                <th class="p-3 text-right">Counted qty</th><th class="p-3 text-right">Variance</th><th class="p-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @foreach ($stockCount->items as $item)
                <tr>
                    <td class="p-3">{{ $item->product->name }}</td>
                    <td class="p-3 text-right">{{ $item->system_quantity }} {{ $item->product->unit->abbreviation }}</td>
                    <td class="p-3 text-right">{{ $item->counted_quantity ?? '—' }}</td>
                    <td class="p-3 text-right {{ (float) $item->variance > 0 ? 'text-green-700' : ((float) $item->variance < 0 ? 'text-red-700' : '') }}">{{ $item->variance ?? '—' }}</td>
                    <td class="p-3">
                        @if ($stockCount->isInProgress())
                            <form method="POST" action="{{ route('inventory.stock-counts.items.record', [$stockCount, $item]) }}" class="flex gap-1">
                                @csrf
                                <input type="number" step="0.001" min="0" name="counted_quantity" value="{{ $item->counted_quantity }}" required class="w-24 rounded border border-slate-300 px-2 py-1 text-xs">
                                <button class="rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                            </form>
                        @endif
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>

@if ($stockCount->isInProgress())
    <form method="POST" action="{{ route('inventory.stock-counts.complete', $stockCount) }}" class="mt-4">
        @csrf
        <button class="rounded bg-green-700 px-4 py-2 text-sm text-white hover:bg-green-600">Complete count &amp; post variances</button>
    </form>
@endif
@endsection
