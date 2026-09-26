@extends('layouts.print')
@section('title', 'Finance Disbursement Voucher — ' . $disbursement->disbursement_number)
@section('document-title', 'Finance Disbursement Voucher')
@section('content')

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500 w-48">Voucher #</td><td class="py-2 font-medium">{{ $disbursement->disbursement_number }}</td></tr>
        <tr><td class="py-2 text-slate-500">Date</td><td class="py-2">{{ $disbursement->disbursed_at->format('d M Y H:i') }}</td></tr>
        <tr><td class="py-2 text-slate-500">Department</td><td class="py-2">{{ $disbursement->department->name ?? '—' }}</td></tr>
        <tr><td class="py-2 text-slate-500">Paid to</td><td class="py-2 font-medium">{{ $disbursement->paid_to }}</td></tr>
        <tr><td class="py-2 text-slate-500">Purpose</td><td class="py-2">{{ $disbursement->purpose }}</td></tr>
        @if ($disbursement->expense)
            <tr><td class="py-2 text-slate-500">Related expense</td><td class="py-2">{{ $disbursement->expense->expense_number }} — {{ $disbursement->expense->category }}</td></tr>
        @endif
        <tr><td class="py-2 text-slate-500">Financial account</td><td class="py-2">{{ $disbursement->financialAccount->name ?? '—' }}</td></tr>
        <tr><td class="py-2 text-slate-500">Method</td><td class="py-2">{{ str_replace('_', ' ', $disbursement->method) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Authorization</td><td class="py-2">{{ $disbursement->authorizationLabel() }}</td></tr>
        <tr><td class="py-2 text-slate-500">Recorded by</td><td class="py-2">{{ $disbursement->recordedBy->name }}</td></tr>
    </tbody>
</table>

<table class="w-full text-sm mb-6">
    <tbody>
        <tr class="border-t-2 border-slate-800 font-semibold text-base">
            <td class="py-2">Amount disbursed</td>
            <td class="py-2 text-right">{{ number_format($disbursement->amount, 0) }} XAF</td>
        </tr>
    </tbody>
</table>

@if ($disbursement->notes)
    <p class="text-xs text-slate-500 mb-6">Notes: {{ $disbursement->notes }}</p>
@endif

<div class="grid grid-cols-2 gap-8 mt-10 text-sm">
    <div class="border-t border-slate-400 pt-2">Received by (signature)</div>
    <div class="border-t border-slate-400 pt-2">Authorized by (signature)</div>
</div>
@endsection
