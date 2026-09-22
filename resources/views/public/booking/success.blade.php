@extends('layouts.public')
@section('title', 'Request received')
@section('subtitle', 'Thank you — we\'ve got your request')

@section('content')
<div class="rounded-lg bg-white shadow-sm p-6 text-center space-y-4">
    <div class="mx-auto flex h-12 w-12 items-center justify-center rounded-full bg-green-100">
        <svg class="h-6 w-6 text-green-600" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="2">
            <path stroke-linecap="round" stroke-linejoin="round" d="M5 13l4 4L19 7" />
        </svg>
    </div>

    <h2 class="text-lg font-semibold text-slate-800">Your reservation request has been received</h2>

    <p class="text-sm text-slate-600">
        This is a request, not a confirmed booking yet. Our front desk will review it and get in touch with you
        shortly to confirm your dates and payment.
    </p>

    @if ($reference)
        <div class="rounded border border-slate-200 bg-slate-50 p-4">
            <p class="text-xs uppercase tracking-wide text-slate-500">Your reference number</p>
            <p class="text-xl font-mono font-semibold text-slate-800">{{ $reference }}</p>
            <p class="text-xs text-slate-500 mt-1">Please keep this — it helps us find your request quickly.</p>
        </div>
    @endif

    @if ($hotelPhone)
        <p class="text-sm text-slate-500">
            Need to reach us sooner? Call {{ $hotelName ?? 'us' }} at
            <span class="font-medium text-slate-700">{{ $hotelPhone }}</span>.
        </p>
    @endif

    <a href="{{ route('public.booking.create') }}" class="inline-block text-sm text-blue-600 hover:text-blue-700">
        Submit another request
    </a>
</div>
@endsection
