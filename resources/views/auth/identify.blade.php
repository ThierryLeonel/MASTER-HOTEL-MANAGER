@extends('layouts.guest')

@section('subtitle', 'Enter your username or email to continue')

@section('content')
<form method="POST" action="{{ route('login.identify') }}" class="space-y-4">
    @csrf
    <div>
        <label class="block text-sm font-medium text-slate-700 mb-1">Username or email</label>
        <input type="text" name="username" value="{{ old('username') }}" autofocus required
               class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-slate-500 focus:outline-none">
    </div>
    <button type="submit" class="w-full rounded bg-slate-800 py-2 text-sm font-medium text-white hover:bg-slate-700">
        Continue
    </button>
</form>
@endsection
