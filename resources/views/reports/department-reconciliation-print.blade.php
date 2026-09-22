@extends('layouts.print')
@section('title', 'Department Payment Reconciliation — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Department Payment Reconciliation')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-6">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
        <tr>
            <th class="py-2">Department</th>
            <th class="py-2 text-right">Sales</th>
            <th class="py-2 text-right">Cash in</th>
            <th class="py-2 text-right">Cash out</th>
            <th class="py-2 text-right">Net cash</th>
            <th class="py-2 text-right">Non-cash in</th>
            <th class="py-2 text-right">Total collected</th>
        </tr>
    </thead>
    <tbody class="divide-y">
        @foreach ($departments as $row)
            <tr>
                <td class="py-2">{{ $row['department']->name }}</td>
                <td class="py-2 text-right">{{ number_format($row['sales'], 0) }}</td>
                <td class="py-2 text-right">{{ number_format($row['cash_in'], 0) }}</td>
                <td class="py-2 text-right">{{ number_format($row['cash_out'], 0) }}</td>
                <td class="py-2 text-right">{{ number_format($row['net_cash'], 0) }}</td>
                <td class="py-2 text-right">
                    {{ number_format($row['non_cash_in'], 0) }}
                    @if ($row['non_cash_by_account']->isNotEmpty())
                        <div class="text-xs text-slate-500">
                            @foreach ($row['non_cash_by_account'] as $acct)
                                {{ $acct['label'] }}: {{ number_format($acct['amount'], 0) }}@if (! $loop->last),@endif
                            @endforeach
                        </div>
                    @endif
                </td>
                <td class="py-2 text-right">{{ number_format($row['total_collected'], 0) }}</td>
            </tr>
        @endforeach
    </tbody>
    <tfoot class="border-t-2 border-slate-800 font-semibold">
        <tr>
            <td class="py-2">Total</td>
            <td class="py-2 text-right">{{ number_format($totals['sales'], 0) }}</td>
            <td class="py-2 text-right">{{ number_format($totals['cash_in'], 0) }}</td>
            <td class="py-2 text-right">{{ number_format($totals['cash_out'], 0) }}</td>
            <td class="py-2 text-right">{{ number_format($totals['net_cash'], 0) }}</td>
            <td class="py-2 text-right">{{ number_format($totals['non_cash_in'], 0) }}</td>
            <td class="py-2 text-right">{{ number_format($totals['total_collected'], 0) }}</td>
        </tr>
    </tfoot>
</table>

@foreach ($departments as $row)
    @if ($row['shifts']->isNotEmpty())
        <p class="text-xs font-medium mb-1">{{ $row['department']->name }} — shifts closed in this period</p>
        <table class="w-full text-sm mb-4">
            <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
                <tr><th class="py-1">Shift #</th><th class="py-1">Closed</th><th class="py-1 text-right">Expected</th><th class="py-1 text-right">Declared</th><th class="py-1 text-right">Variance</th></tr>
            </thead>
            <tbody class="divide-y">
                @foreach ($row['shifts'] as $shift)
                    <tr>
                        <td class="py-1">{{ $shift->shift_number }}</td>
                        <td class="py-1">{{ $shift->closed_at->format('d M H:i') }}</td>
                        <td class="py-1 text-right">{{ number_format($shift->expected_cash, 0) }}</td>
                        <td class="py-1 text-right">{{ number_format($shift->declared_cash, 0) }}</td>
                        <td class="py-1 text-right">{{ number_format($shift->variance, 0) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif
@endforeach
@endsection
