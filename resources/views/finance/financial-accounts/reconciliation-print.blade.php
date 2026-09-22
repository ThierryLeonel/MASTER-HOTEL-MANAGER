@extends('layouts.print')
@section('title', 'Account Reconciliation — ' . $reconciliation->financialAccount->name)
@section('document-title', 'Financial Account Reconciliation')
@section('content')

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-48">Account</td><td class="py-2 font-medium">{{ $reconciliation->financialAccount->name }} ({{ $reconciliation->financialAccount->code }})</td></tr>
        <tr><td class="py-2 text-slate-500">Reconciled</td><td class="py-2">{{ $reconciliation->reconciled_at->format('d M Y H:i') }} by {{ $reconciliation->reconciledBy->name }}</td></tr>
    </tbody>
</table>

<table class="w-full text-sm mb-4">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500">Expected balance (from the ledger)</td><td class="py-2 text-right">{{ number_format($reconciliation->expected_balance, 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Declared balance (from the statement/app)</td><td class="py-2 text-right">{{ number_format($reconciliation->declared_balance, 0) }}</td></tr>
        <tr class="border-t-2 border-slate-800 font-semibold text-base">
            <td class="py-2">Variance</td>
            <td class="py-2 text-right {{ $reconciliation->variance == 0 ? '' : ($reconciliation->variance > 0 ? 'text-green-700' : 'text-red-600') }}">
                {{ number_format($reconciliation->variance, 0) }} XAF
            </td>
        </tr>
    </tbody>
</table>

@if ($reconciliation->notes)
    <p class="text-xs text-slate-500 mb-6">Notes: {{ $reconciliation->notes }}</p>
@endif

<div class="grid grid-cols-2 gap-8 mt-10 text-sm">
    <div class="border-t border-slate-400 pt-2">Reconciled by (signature)</div>
    <div class="border-t border-slate-400 pt-2">Reviewed by (signature)</div>
</div>
@endsection
