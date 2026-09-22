@extends('layouts.guest')

@section('subtitle', 'Enter the 6-digit code from your authenticator app')

@section('content')
<form method="POST" action="{{ route('login.totp.attempt') }}" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Authentication code</label>
        <input type="text" inputmode="numeric" name="code" autofocus required autocomplete="one-time-code"
               class="w-full rounded border border-slate-300 px-3 py-2 text-center text-2xl tracking-widest focus:border-slate-500 focus:outline-none">
        <p class="mt-1 text-xs text-slate-500">You may also enter a recovery code.</p>
    </div>
    <button type="submit" class="w-full rounded bg-slate-800 py-2 text-sm font-medium text-white hover:bg-slate-700">
        Verify
    </button>
</form>
@endsection
