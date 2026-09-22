@extends('layouts.app')
@section('title', 'Change My PIN')
@section('content')

<div class="max-w-md rounded-lg bg-white p-6 shadow-sm">
    @if ($targetUser->auth_type !== 'pin')
        <p class="text-sm text-slate-600">Your account signs in with a password, not a PIN, so there is nothing to change here.</p>
    @else
        <form method="POST" action="{{ route('my-pin.update') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1">Current PIN</label>
                <input type="password" inputmode="numeric" name="current_pin" required autocomplete="off" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">New PIN (4–6 digits)</label>
                <input type="password" inputmode="numeric" name="new_pin" required autocomplete="off" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Confirm new PIN</label>
                <input type="password" inputmode="numeric" name="new_pin_confirmation" required autocomplete="off" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </div>
            <button class="btn-action rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Update PIN</button>
            <p class="text-xs text-slate-500">Forgotten your PIN entirely (can't provide the current one)? Ask an Owner or General Manager to reset it for you from Administration → Users.</p>
        </form>
    @endif
</div>
@endsection
