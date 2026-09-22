@extends('layouts.guest')

@section('subtitle', 'Enter your PIN, ' . $user->name)

@section('content')
<form method="POST" action="{{ route('login.pin.attempt') }}" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">PIN</label>
        <input type="password" inputmode="numeric" pattern="[0-9]*" name="pin" autofocus required
               class="w-full rounded border border-slate-300 px-3 py-2 text-center text-2xl tracking-widest focus:border-slate-500 focus:outline-none">
    </div>
    <button type="submit" class="w-full rounded bg-slate-800 py-2 text-sm font-medium text-white hover:bg-slate-700">
        Sign in
    </button>
    <a href="{{ route('login') }}" class="block text-center text-xs text-slate-500 hover:underline">Not you?</a>
</form>
@endsection
