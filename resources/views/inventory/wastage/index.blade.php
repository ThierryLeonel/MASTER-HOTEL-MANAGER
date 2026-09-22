@extends('layouts.app')
@section('title', 'Wastage')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Wastage #</th><th class="p-3">Product</th><th class="p-3 text-right">Qty</th><th class="p-3">Reason</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($wastages as $wastage)
                    <tr>
                        <td class="p-3">{{ $wastage->wastage_number }}</td>
                        <td class="p-3">{{ $wastage->product->name }}</td>
                        <td class="p-3 text-right">{{ $wastage->quantity }} {{ $wastage->product->unit->abbreviation }}</td>
                        <td class="p-3">{{ $wastage->reason }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $wastage->status }}</span></td>
                        <td class="p-3">
                            @if ($wastage->isPending())
                                @can('approve-wastage')
                                    <form method="POST" action="{{ route('inventory.wastage.approve', $wastage) }}" class="inline">
                                        @csrf
                                        <button class="text-green-700 hover:underline text-xs">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('inventory.wastage.reject', $wastage) }}" onsubmit="return promptReason(this)" class="inline">
                                        @csrf
                                        <input type="hidden" name="reason" value="">
                                        <button class="text-red-600 hover:underline text-xs">Reject</button>
                                    </form>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="6">No wastage recorded yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $wastages->links() }}
    </div>

    <div class="col-span-1">
        @can('record-wastage')
            <form method="POST" action="{{ route('inventory.wastage.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Record wastage</div>
                <select name="product_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Product…</option>
                    @foreach ($products as $product)
                        <option value="{{ $product->id }}">{{ $product->name }} ({{ $product->unit->abbreviation }})</option>
                    @endforeach
                </select>
                <select name="department_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Department (optional)…</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
                <input type="number" step="0.001" min="0.001" name="quantity" placeholder="Quantity" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input name="reason" placeholder="Reason (e.g. broken bottle, spoiled)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Record</button>
            </form>
        @endcan
    </div>
</div>

<script>
function promptReason(form) {
    const reason = prompt('Rejection reason:');
    if (!reason) return false;
    form.reason.value = reason;
    return true;
}
</script>
@endsection
