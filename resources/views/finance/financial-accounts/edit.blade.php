@extends('layouts.app')
@section('title', 'Edit Financial Account')
@section('content')
<form method="POST" action="{{ route('finance.financial-accounts.update', $financialAccount) }}" class="max-w-lg space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    @method('PUT')
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Name</label>
            <input name="name" value="{{ old('name', $financialAccount->name) }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Short code</label>
            <input name="code" value="{{ old('code', $financialAccount->code) }}" required maxlength="30" class="w-full rounded border border-slate-300 px-3 py-2 text-sm uppercase">
        </div>
    </div>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Type</label>
            <select name="type" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                @foreach (['cash' => 'Cash', 'bank' => 'Bank', 'mobile_money' => 'Mobile Money', 'card' => 'Card'] as $value => $label)
                    <option value="{{ $value }}" @selected(old('type', $financialAccount->type) === $value)>{{ $label }}</option>
                @endforeach
            </select>
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Opening balance</label>
            <input type="number" step="0.01" min="0" name="opening_balance" value="{{ old('opening_balance', $financialAccount->opening_balance) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <p class="mt-1 text-xs text-slate-500">Correcting this changes the account's current balance immediately — everything ledgered since is added on top of it, exactly as before.</p>
        </div>
    </div>
    <div class="flex gap-2">
        <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Save changes</button>
        <a href="{{ route('finance.financial-accounts.index') }}" class="rounded border border-slate-300 px-4 py-2 text-sm text-slate-600 hover:bg-slate-50">Cancel</a>
    </div>
</form>
@endsection
