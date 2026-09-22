@extends('layouts.app')
@section('title', 'Facilities')
@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    @foreach ($facilities as $facility)
        <div class="rounded-lg bg-white shadow-sm p-4 space-y-3">
            <div class="flex items-center justify-between">
                <div class="font-medium">{{ $facility->name }}</div>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $facility->code }}</span>
            </div>
            @if ($facility->description)
                <p class="text-xs text-slate-500">{{ $facility->description }}</p>
            @endif

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-slate-500">
                    <tr><th class="py-1">Rate</th><th class="py-1 text-right">Amount</th><th class="py-1"></th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($facility->rates as $rate)
                        <tr>
                            <td class="py-1">{{ $rate->name }}</td>
                            <td class="py-1 text-right">{{ number_format($rate->amount, 0) }}</td>
                            <td class="py-1 text-right">
                                <form method="POST" action="{{ route('facilities.rates.toggle', $rate) }}" class="inline">
                                    @csrf
                                    <button class="text-xs {{ $rate->is_active ? 'text-red-600' : 'text-green-700' }} hover:underline">
                                        {{ $rate->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                            </td>
                        </tr>
                    @empty
                        <tr><td class="py-1 text-slate-500" colspan="3">No rates yet.</td></tr>
                    @endforelse
                </tbody>
            </table>

            <form method="POST" action="{{ route('facilities.rates.store', $facility) }}" class="flex gap-2">
                @csrf
                <input name="name" placeholder="Rate name" required class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs">
                <input type="number" step="0.01" min="0.01" name="amount" placeholder="XAF" required class="w-24 rounded border border-slate-300 px-2 py-1 text-xs">
                <button class="rounded bg-slate-800 px-3 py-1 text-xs text-white hover:bg-slate-700">Add</button>
            </form>
        </div>
    @endforeach

    <div class="rounded-lg bg-white shadow-sm p-4 space-y-2">
        <div class="text-xs font-medium">Add a facility</div>
        <form method="POST" action="{{ route('facilities.store') }}" class="space-y-2">
            @csrf
            <input name="code" placeholder="Code (e.g. spa)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="name" placeholder="Name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <textarea name="description" placeholder="Description (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create facility</button>
        </form>
    </div>
</div>
@endsection
