@extends('layouts.app')
@section('title', $requisition->requisition_number)
@section('content')
<div class="mb-4">
    <a href="{{ route('inventory.requisitions.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Back to requisitions</a>
</div>

<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 space-y-6">
        <div class="rounded-lg bg-white p-4 shadow-sm text-sm flex justify-between">
            <div>
                <div class="font-semibold">{{ $requisition->requisition_number }}</div>
                <div class="text-slate-500">{{ $requisition->department->name }} · Requested by {{ $requisition->requestedBy->name }}</div>
            </div>
            <span class="h-fit rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $requisition->status }}</span>
        </div>

        <div class="rounded-lg bg-white shadow-sm">
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Product</th><th class="p-3 text-right">Quantity requested</th></tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($requisition->items as $item)
                        <tr>
                            <td class="p-3">{{ $item->product->name }}</td>
                            <td class="p-3 text-right">{{ $item->quantity_requested }} {{ $item->product->unit->abbreviation }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>

        @if ($requisition->rejection_reason)
            <div class="rounded-lg bg-red-50 border border-red-200 p-3 text-xs text-red-700">Rejected: {{ $requisition->rejection_reason }}</div>
        @endif
    </div>

    <div class="col-span-1 space-y-3">
        @if ($requisition->isPending())
            @can('approve-requisition')
                <form method="POST" action="{{ route('inventory.requisitions.approve', $requisition) }}">
                    @csrf
                    <button class="w-full rounded bg-green-700 px-3 py-1.5 text-sm text-white hover:bg-green-600">Approve &amp; issue stock</button>
                </form>
                <form method="POST" action="{{ route('inventory.requisitions.reject', $requisition) }}" onsubmit="return promptReason(this)">
                    @csrf
                    <input type="hidden" name="reason" value="">
                    <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Reject</button>
                </form>
            @endcan
            <form method="POST" action="{{ route('inventory.requisitions.cancel', $requisition) }}">
                @csrf
                <button class="w-full rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Cancel</button>
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
</script>
@endsection
