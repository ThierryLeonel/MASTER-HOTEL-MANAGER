@extends('layouts.print')
@section('title', 'Daily Cash Closing — ' . $cashReconciliation->business_date->format('d M Y'))
@section('document-title', 'Daily Cash Closing')
@section('content')

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-48">Business date</td><td class="py-2 font-medium">{{ $cashReconciliation->business_date->format('d M Y') }}</td></tr>
        <tr><td class="py-2 text-slate-500">Recorded by</td><td class="py-2">{{ $cashReconciliation->recordedBy->name }}</td></tr>
        <tr><td class="py-2 text-slate-500">Accounting day status</td>
            <td class="py-2">
                @if ($accountingDay?->isLocked())
                    <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">Locked by {{ $accountingDay->lockedBy->name }} on {{ $accountingDay->locked_at->format('d M Y H:i') }}</span>
                @else
                    <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800">Not yet locked</span>
                @endif
            </td>
        </tr>
    </tbody>
</table>

<table class="w-full text-sm mb-4">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500">Expected cash (from the ledger)</td><td class="py-2 text-right">{{ number_format($cashReconciliation->expected_amount, 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Counted cash</td><td class="py-2 text-right">{{ number_format($cashReconciliation->counted_amount, 0) }}</td></tr>
        <tr class="border-t-2 border-slate-800 font-semibold text-base">
            <td class="py-2">Variance</td>
            <td class="py-2 text-right {{ $cashReconciliation->variance == 0 ? '' : ($cashReconciliation->variance > 0 ? 'text-green-700' : 'text-red-600') }}">
                {{ number_format($cashReconciliation->variance, 0) }} XAF
            </td>
        </tr>
    </tbody>
</table>

@if ($cashReconciliation->notes)
    <p class="text-xs text-slate-500">Notes: {{ $cashReconciliation->notes }}</p>
@endif
@endsection
