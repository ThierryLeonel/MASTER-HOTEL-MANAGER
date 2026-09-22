@extends('layouts.app')
@section('title', 'Expense Report')
@section('content')
@include('reports._date-filter')

<div class="mb-4 flex gap-3 text-xs">
    <a href="{{ route('reports.expense-report.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
    <a href="{{ route('reports.expense-report.csv', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-200 px-3 py-1.5 hover:bg-slate-300">Download CSV</a>
</div>

<div class="rounded-lg bg-white p-4 shadow-sm mb-4">
    <div class="font-medium mb-2">By category</div>
    <table class="w-full text-sm">
        <tbody class="divide-y">
            @forelse ($summary['by_category'] as $category => $amount)
                <tr><td class="py-1">{{ $category }}</td><td class="py-1 text-right">{{ number_format($amount, 0) }}</td></tr>
            @empty
                <tr><td class="py-1 text-slate-500" colspan="2">No expenses requested in this period.</td></tr>
            @endforelse
            <tr class="font-semibold"><td class="py-1">Requested total</td><td class="py-1 text-right">{{ number_format($summary['total_requested'], 0) }}</td></tr>
            <tr><td class="py-1">Paid total</td><td class="py-1 text-right">{{ number_format($summary['total_paid'], 0) }}</td></tr>
        </tbody>
    </table>
</div>

<div class="rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Expense #</th><th class="p-3">Date</th><th class="p-3">Category</th><th class="p-3">Department</th><th class="p-3">Status</th><th class="p-3">Requested by</th><th class="p-3 text-right">Amount</th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($expenses as $e)
                <tr>
                    <td class="p-3">{{ $e->expense_number }}</td>
                    <td class="p-3">{{ $e->created_at->format('d M') }}</td>
                    <td class="p-3">{{ $e->category }}</td>
                    <td class="p-3">{{ $e->department->name ?? '—' }}</td>
                    <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $e->status }}</span></td>
                    <td class="p-3">{{ $e->requestedBy->name ?? '—' }}</td>
                    <td class="p-3 text-right">{{ number_format($e->amount, 0) }}</td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="7">No expenses in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
