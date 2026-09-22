@extends('layouts.print')
@section('title', $documentTitle . ' — ' . $stay->guest->full_name)
@section('document-title', $documentTitle)
@section('content')

@php
    // Post-9.8 fix round: computed once here and passed into each of the
    // two duplicate copies below, so both copies are guaranteed to show
    // the exact same numbers (never two independently-computed totals) —
    // see _folio-receipt-copy.blade.php and Folio::departmentSummary().
    //
    // Global hospitality-standard change ("let the total cost be in the
    // folio"): this used to be its own separately-estimated night count
    // (calendar hours / 24), which could disagree with what was actually
    // posted to the folio. It now reads the REAL number of nights
    // CheckInService/CheckOutService actually charged
    // (Stay::nights_charged), so this line can never show a different
    // total than the ledger below it — falling back to actualNights()
    // only for a stay that predates this field (nights_charged = 0).
    $nightsSoFar = $stay->nights_charged ?: $stay->actualNights();
    $departmentSummary = $stay->folio->departmentSummary();
@endphp

<style>
    /* "2 on an A4, separated by a single dash borderline in the middle" */
    .receipt-divider {
        border: none;
        border-top: 1px dashed #94a3b8;
        margin: 0.3cm 0;
    }
    @media print {
        .print-copy { break-inside: avoid; }
    }
</style>

@include('front-office.stays._folio-receipt-copy', ['nightsSoFar' => $nightsSoFar, 'departmentSummary' => $departmentSummary])

<hr class="receipt-divider">

@include('front-office.stays._folio-receipt-copy', ['nightsSoFar' => $nightsSoFar, 'departmentSummary' => $departmentSummary])

<p class="no-print mt-4 text-xs text-slate-400">Two identical copies are printed above for guest/hotel record-keeping — for a folio with a great many transactions, they may spill onto a second sheet when printed.</p>
@endsection
