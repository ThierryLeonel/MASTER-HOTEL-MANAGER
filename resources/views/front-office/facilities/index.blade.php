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

            @can('manage-facilities')
                <details class="text-xs">
                    <summary class="cursor-pointer text-slate-600 underline">Edit facility</summary>
                    <form method="POST" action="{{ route('facilities.update', $facility) }}" class="mt-2 space-y-1">
                        @csrf @method('PUT')
                        <input name="name" value="{{ $facility->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                        <textarea name="description" rows="2" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">{{ $facility->description }}</textarea>
                        <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                    </form>
                    <form method="POST" action="{{ route('facilities.destroy', $facility) }}" class="mt-1"
                          onsubmit="return confirm(@js('Delete facility '.$facility->name.' permanently? Only a facility with no rates or access history can be deleted.'));">
                        @csrf @method('DELETE')
                        <button class="text-red-700 hover:underline">Delete facility</button>
                    </form>
                </details>
            @endcan

            <table class="w-full text-sm">
                <thead class="text-left text-xs uppercase text-slate-500">
                    <tr><th class="py-1">Rate</th><th class="py-1 text-right">Amount</th><th class="py-1"></th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($facility->rates as $rate)
                        <tr>
                            <td class="py-1">{{ $rate->name }}</td>
                            <td class="py-1 text-right">{{ number_format($rate->amount, 0) }}</td>
                            <td class="py-1 text-right space-x-2 whitespace-nowrap">
                                <form method="POST" action="{{ route('facilities.rates.toggle', $rate) }}" class="inline">
                                    @csrf
                                    <button class="text-xs {{ $rate->is_active ? 'text-red-600' : 'text-green-700' }} hover:underline">
                                        {{ $rate->is_active ? 'Disable' : 'Enable' }}
                                    </button>
                                </form>
                                <details class="inline-block">
                                    <summary class="cursor-pointer text-xs text-slate-600 underline">Edit</summary>
                                    <form method="POST" action="{{ route('facilities.rates.update', $rate) }}" class="mt-1 flex gap-1">
                                        @csrf @method('PUT')
                                        <input name="name" value="{{ $rate->name }}" required class="w-20 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                        <input type="number" step="0.01" min="0.01" name="amount" value="{{ $rate->amount }}" required class="w-16 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                        <button class="rounded bg-slate-800 px-2 py-0.5 text-xs text-white">Save</button>
                                    </form>
                                    <form method="POST" action="{{ route('facilities.rates.destroy', $rate) }}" class="mt-1"
                                          onsubmit="return confirm('Delete this rate? Only a rate with no access history can be deleted.');">
                                        @csrf @method('DELETE')
                                        <button class="text-xs text-red-700 hover:underline">Delete</button>
                                    </form>
                                </details>
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
            <input name="name" placeholder="Name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <p class="text-[11px] text-slate-400">A facility code is generated automatically once you save.</p>
            <textarea name="description" placeholder="Description (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create facility</button>
        </form>
    </div>
</div>
@endsection
