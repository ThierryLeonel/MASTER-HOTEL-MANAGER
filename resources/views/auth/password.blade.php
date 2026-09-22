@extends('layouts.guest')

@section('subtitle', 'Welcome back, ' . $user->name)

@section('content')
<form method="POST" action="{{ route('login.password.attempt') }}" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Password</label>
        <input type="password" name="password" autofocus required
               class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
    </div>
    <button type="submit" class="w-full rounded bg-slate-800 py-2 text-sm font-medium text-white hover:bg-slate-700">
        Sign in
    </button>
    <a href="{{ route('login') }}" class="block text-center text-xs text-slate-500 hover:underline">Not you?</a>
</form>
@endsection
