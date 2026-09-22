@extends('layouts.app')
@section('title', 'Income Statement')
@section('content')
@include('reports._date-filter')

<div class="mb-4 text-xs">
    <a href="{{ route('reports.income-statement.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
</div>

<div class="mb-4 rounded border border-amber-200 bg-amber-50 p-3 text-xs text-amber-800">
    Management estimate, not a formal accounting statement: this system does not maintain a Chart of Accounts or
    General Ledger, so this view combines the Financial report's revenue and expense figures rather than posting
    real double-entry journal entries. Treat it as a quick read on operating performance, not as a substitute for
    a proper Income Statement prepared by an accountant.
</div>

<div class="max-w-2xl rounded-lg bg-white p-4 shadow-sm">
    <table class="w-full text-sm">
        <tbody class="divide-y">
            <tr class="bg-slate-50"><td class="py-2 px-2 font-medium" colspan="2">Revenue</td></tr>
            @foreach ($statement['revenue_by_type'] as $type => $amount)
                <tr><td class="py-1 px-2 pl-6">{{ str_replace('_', ' ', ucfirst($type)) }}</td><td class="py-1 px-2 text-right">{{ number_format($amount, 0) }}</td></tr>
            @endforeach
            <tr class="font-semibold"><td class="py-1 px-2">Total revenue</td><td class="py-1 px-2 text-right">{{ number_format($statement['total_revenue'], 0) }}</td></tr>

            <tr class="bg-slate-50"><td class="py-2 px-2 font-medium" colspan="2">Expenses</td></tr>
            @forelse ($statement['expenses_by_category'] as $category => $amount)
                <tr><td class="py-1 px-2 pl-6">{{ $category }}</td><td class="py-1 px-2 text-right">-{{ number_format($amount, 0) }}</td></tr>
            @empty
                <tr><td class="py-1 px-2 pl-6 text-slate-500" colspan="2">No expenses requested in this period.</td></tr>
            @endforelse
            <tr class="font-semibold"><td class="py-1 px-2">Total expenses</td><td class="py-1 px-2 text-right">-{{ number_format($statement['total_expenses'], 0) }}</td></tr>

            <tr class="border-t-2 border-slate-800 text-base font-semibold">
                <td class="py-2 px-2">Net operating result</td>
                <td class="py-2 px-2 text-right {{ $statement['net_operating_result'] >= 0 ? 'text-green-700' : 'text-red-600' }}">
                    {{ number_format($statement['net_operating_result'], 0) }}
                </td>
            </tr>
        </tbody>
    </table>
</div>
@endsection
