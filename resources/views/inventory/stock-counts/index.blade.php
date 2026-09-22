@extends('layouts.app')
@section('title', 'Stock Counts')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Count #</th><th class="p-3">Started by</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($stockCounts as $count)
                    <tr>
                        <td class="p-3">{{ $count->count_number }}</td>
                        <td class="p-3">{{ $count->startedBy->name }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $count->status }}</span></td>
                        <td class="p-3"><a href="{{ route('inventory.stock-counts.show', $count) }}" class="text-slate-600 hover:underline">Open</a></td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="4">No stock counts yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $stockCounts->links() }}
    </div>

    <div class="col-span-1">
        <form method="POST" action="{{ route('inventory.stock-counts.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Start a new stock count</div>
            <div class="max-h-64 overflow-y-auto space-y-1 border border-slate-200 rounded p-2">
                @foreach ($products as $product)
                    <label class="flex items-center gap-2 text-sm">
                        <input type="checkbox" name="product_id[]" value="{{ $product->id }}">
                        {{ $product->name }}
                    </label>
                @endforeach
            </div>
            <textarea name="notes" placeholder="Notes (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Start count</button>
        </form>
    </div>
</div>
@endsection
