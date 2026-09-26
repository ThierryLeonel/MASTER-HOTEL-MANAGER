@extends('layouts.app')
@section('title', 'Stock Requisitions')
@section('content')
<div class="mb-4 flex justify-between items-center">
    <form method="GET" class="flex gap-2">
        <select name="status" onchange="this.form.submit()" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
            <option value="">All statuses</option>
            @foreach (['pending', 'approved', 'rejected', 'cancelled'] as $status)
                <option value="{{ $status }}" @selected(request('status') === $status)>{{ ucfirst($status) }}</option>
            @endforeach
        </select>
    </form>
    @can('request-stock')
        <a href="{{ route('inventory.requisitions.create') }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New requisition</a>
    @endcan
</div>

<div class="overflow-x-auto rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Requisition #</th><th class="p-3">Department</th><th class="p-3">Requested by</th><th class="p-3">Status</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($requisitions as $requisition)
                <tr>
                    <td class="p-3">{{ $requisition->requisition_number }}</td>
                    <td class="p-3">{{ $requisition->department->name }}</td>
                    <td class="p-3">{{ $requisition->requestedBy->name }}</td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $requisition->status }}</span></td>
                    <td class="p-3"><a href="{{ route('inventory.requisitions.show', $requisition) }}" class="text-slate-600 hover:underline">Open</a></td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="5">No requisitions yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
{{ $requisitions->links() }}
@endsection
