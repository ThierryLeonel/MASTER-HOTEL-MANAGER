@extends('layouts.print')
@section('title', 'Shift Handover — ' . $shift->shift_number)
@section('document-title', 'Shift Handover / Cash Reconciliation')
@section('content')

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-48">Shift #</td><td class="py-2 font-medium">{{ $shift->shift_number }}</td></tr>
        <tr><td class="py-2 text-slate-500">Department</td><td class="py-2">{{ $shift->department->name }}</td></tr>
        <tr><td class="py-2 text-slate-500">Opened</td><td class="py-2">{{ $shift->opened_at->format('d M Y H:i') }} by {{ $shift->openedBy->name }}</td></tr>
        <tr><td class="py-2 text-slate-500">Closed</td>
            <td class="py-2">
                @if ($shift->closed_at)
                    {{ $shift->closed_at->format('d M Y H:i') }} by {{ $shift->closedBy->name }}
                @else
                    <span class="text-amber-700">Still open</span>
                @endif
            </td>
        </tr>
    </tbody>
</table>

@if ($shift->status === 'closed')
    <table class="w-full text-sm mb-4">
        <tbody class="divide-y">
            <tr><td class="py-2 text-slate-500">Expected cash (from the ledger)</td><td class="py-2 text-right">{{ number_format($shift->expected_cash, 0) }}</td></tr>
            <tr><td class="py-2 text-slate-500">Declared cash (counted &amp; handed in)</td><td class="py-2 text-right">{{ number_format($shift->declared_cash, 0) }}</td></tr>
            <tr class="border-t-2 border-slate-800 font-semibold text-base">
                <td class="py-2">Variance</td>
                <td class="py-2 text-right {{ $shift->variance == 0 ? '' : ($shift->variance > 0 ? 'text-green-700' : 'text-red-600') }}">
                    {{ number_format($shift->variance, 0) }} XAF
                </td>
            </tr>
        </tbody>
    </table>
@endif

@if ($accountBreakdown->isNotEmpty())
    <table class="w-full text-sm mb-6">
        <thead>
            <tr class="text-left text-xs uppercase text-slate-500 border-b"><th class="py-2">Account</th><th class="py-2 text-right">Net this shift</th></tr>
        </thead>
        <tbody class="divide-y">
            @foreach ($accountBreakdown as $row)
                <tr>
                    <td class="py-2">{{ $row['label'] }}</td>
                    <td class="py-2 text-right">{{ number_format($row['net'], 0) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>
    <p class="text-xs text-slate-500 mb-6">Only cash is physically counted and declared above — mobile money, bank, and card payments are reconciled against their own account on the Day Close screen, not handed over as cash.</p>
@endif

@if ($shift->notes)
    <p class="text-xs text-slate-500 mb-6">Notes: {{ $shift->notes }}</p>
@endif

<div class="grid grid-cols-2 gap-8 mt-10 text-sm">
    <div class="border-t border-slate-400 pt-2">Handed over by (signature)</div>
    <div class="border-t border-slate-400 pt-2">Received by (signature)</div>
</div>
@endsection
