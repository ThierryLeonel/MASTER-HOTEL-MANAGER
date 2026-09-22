@extends('layouts.app')
@section('title', 'Department Payment Reconciliation')
@section('content')
@include('reports._date-filter')

<div class="mb-4 flex gap-3 text-xs">
    <a href="{{ route('reports.department-reconciliation.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
    <a href="{{ route('reports.department-reconciliation.csv', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-200 px-3 py-1.5 hover:bg-slate-300">Download CSV</a>
</div>

<p class="text-xs text-slate-500 mb-4">
    "Sales/income" is what each department charged or sold in this period (accrual). "Cash in/out" is what actually
    moved through the payment ledger, cash only — this is the figure each department's cash handover should match.
    They will not always be equal (e.g. a bar tab settled onto a guest's room folio is bar sales but front-desk cash).
</p>

<div class="rounded-lg bg-white shadow-sm mb-6">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Department</th>
                <th class="p-3 text-right">Sales / income</th>
                <th class="p-3 text-right">Cash in</th>
                <th class="p-3 text-right">Cash out</th>
                <th class="p-3 text-right">Net cash</th>
                <th class="p-3 text-right">Non-cash in</th>
                <th class="p-3 text-right">Total collected</th>
                <th class="p-3 text-right">Shifts closed</th>
                <th class="p-3 text-right">Shift variance</th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($departments as $row)
                <tr>
                    <td class="p-3 font-medium">{{ $row['department']->name }}</td>
                    <td class="p-3 text-right">{{ number_format($row['sales'], 0) }}</td>
                    <td class="p-3 text-right">{{ number_format($row['cash_in'], 0) }}</td>
                    <td class="p-3 text-right">{{ number_format($row['cash_out'], 0) }}</td>
                    <td class="p-3 text-right font-medium">{{ number_format($row['net_cash'], 0) }}</td>
                    <td class="p-3 text-right">
                        {{ number_format($row['non_cash_in'], 0) }}
                        @if ($row['non_cash_by_account']->isNotEmpty())
                            <div class="text-xs text-slate-500 font-normal">
                                @foreach ($row['non_cash_by_account'] as $acct)
                                    {{ $acct['label'] }}: {{ number_format($acct['amount'], 0) }}@if (! $loop->last),@endif
                                @endforeach
                            </div>
                        @endif
                    </td>
                    <td class="p-3 text-right">{{ number_format($row['total_collected'], 0) }}</td>
                    <td class="p-3 text-right">{{ $row['shifts']->count() }}</td>
                    <td class="p-3 text-right {{ $row['shift_variance_total'] == 0 ? '' : ($row['shift_variance_total'] > 0 ? 'text-green-700' : 'text-red-600') }}">
                        {{ number_format($row['shift_variance_total'], 0) }}
                    </td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="9">No department activity in this period.</td></tr>
            @endforelse
        </tbody>
        <tfoot class="border-t-2 border-slate-800 font-semibold">
            <tr>
                <td class="p-3">Total</td>
                <td class="p-3 text-right">{{ number_format($totals['sales'], 0) }}</td>
                <td class="p-3 text-right">{{ number_format($totals['cash_in'], 0) }}</td>
                <td class="p-3 text-right">{{ number_format($totals['cash_out'], 0) }}</td>
                <td class="p-3 text-right">{{ number_format($totals['net_cash'], 0) }}</td>
                <td class="p-3 text-right">{{ number_format($totals['non_cash_in'], 0) }}</td>
                <td class="p-3 text-right">{{ number_format($totals['total_collected'], 0) }}</td>
                <td class="p-3"></td>
                <td class="p-3"></td>
            </tr>
        </tfoot>
    </table>
</div>

@foreach ($departments as $row)
    @if ($row['shifts']->isNotEmpty())
        <div class="rounded-lg bg-white shadow-sm mb-4">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">{{ $row['department']->name }} — shifts closed in this period</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">Shift #</th><th class="p-3">Opened</th><th class="p-3">Closed</th><th class="p-3 text-right">Expected</th><th class="p-3 text-right">Declared</th><th class="p-3 text-right">Variance</th></tr>
                </thead>
                <tbody class="divide-y">
                    @foreach ($row['shifts'] as $shift)
                        <tr>
                            <td class="p-3">{{ $shift->shift_number }}</td>
                            <td class="p-3 text-xs">{{ $shift->opened_at->format('d M H:i') }}</td>
                            <td class="p-3 text-xs">{{ $shift->closed_at->format('d M H:i') }}</td>
                            <td class="p-3 text-right">{{ number_format($shift->expected_cash, 0) }}</td>
                            <td class="p-3 text-right">{{ number_format($shift->declared_cash, 0) }}</td>
                            <td class="p-3 text-right {{ $shift->variance == 0 ? '' : ($shift->variance > 0 ? 'text-green-700' : 'text-red-600') }}">{{ number_format($shift->variance, 0) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
@endforeach
@endsection
