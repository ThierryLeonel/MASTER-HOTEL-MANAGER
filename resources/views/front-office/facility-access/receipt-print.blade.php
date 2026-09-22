@extends('layouts.print-thermal')
@section('title', $access->facility->name . ' Access — ' . $access->access_number)
@section('document-title', $access->facility->name . ' Access')
@section('content')

<div class="row"><span>Receipt</span><span class="bold">{{ $access->access_number }}</span></div>
<div class="row"><span>Visitor</span><span>{{ $access->visitor_name }}</span></div>
@if ($access->visitor_phone)
    <div class="row"><span>Phone</span><span>{{ $access->visitor_phone }}</span></div>
@endif
<div class="row"><span>Granted by</span><span>{{ $access->grantedBy->name }}</span></div>

<hr class="divider">

<div class="row"><span>{{ $access->rate->name ?? 'Access' }}</span><span>{{ number_format($access->amount_charged, 0) }}</span></div>

<hr class="divider">

<div class="total-row"><span>TOTAL</span><span>{{ number_format($access->amount_charged, 0) }} XAF</span></div>

<div class="center muted" style="margin-top: 3mm;">Valid for one visit — present at the {{ strtolower($access->facility->name) }} entrance.</div>
<div class="center muted" style="margin-top: 4mm;">Thank you!</div>
@endsection
