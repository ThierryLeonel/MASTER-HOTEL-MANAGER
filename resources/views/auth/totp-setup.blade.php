@extends('layouts.guest')

@section('subtitle', 'Set up two-factor authentication (required for your role)')

@section('content')
<div class="mb-4 flex justify-center">{!! $qrSvg !!}</div>
<p class="mb-2 text-xs text-slate-500">Scan with Google Authenticator, Authy, or any TOTP app. Can't scan? Enter this key manually:</p>
<p class="mb-4 break-all rounded bg-slate-100 p-2 text-center text-xs font-mono">{{ $secret }}</p>

<form method="POST" action="{{ route('login.totp.setup.attempt') }}" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Enter the 6-digit code to confirm</label>
        <input type="text" inputmode="numeric" name="code" autofocus required
               class="w-full rounded border border-slate-300 px-3 py-2 text-center text-2xl tracking-widest focus:border-slate-500 focus:outline-none">
    </div>
    <button type="submit" class="w-full rounded bg-slate-800 py-2 text-sm font-medium text-white hover:bg-slate-700">
        Confirm &amp; finish sign in
    </button>
</form>
@endsection
