@extends('layouts.app')
@section('title', 'Charge Types')
@section('content')

{{--
    Direct request (Sept 2026): "normally we have bar charge, restaurant
    charge, frontdesk charge, rental charge, pool fee, Gym Fee, etc
    include all connect them and charges can be posted and payment can be
    recieved and recorde to the diferent sources, just build it in such a
    way i can edit and or just add or delete." One list, everything on it:
    what used to be the fixed 5-type charge list AND the separate Gym/Pool
    "Facilities" screen. A "System" badge marks the handful this system
    relies on structurally (its code can't change and it can never be
    deleted or switched off) — everything else is fully yours to rename,
    reconfigure, or remove.
--}}
<div class="space-y-6">
    <div class="rounded-lg bg-white shadow-sm">
        <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Charge types</div>
        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4 p-4">
            @foreach ($chargeTypes as $chargeType)
                <div class="rounded-lg border border-slate-200 p-4 space-y-3">
                    <div class="flex items-center justify-between gap-2">
                        <div class="font-medium">{{ $chargeType->name }}</div>
                        <div class="flex items-center gap-1">
                            @if ($chargeType->is_system)
                                <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-800" title="Built-in — code, and whether it's active, can't be changed.">System</span>
                            @endif
                            @unless ($chargeType->is_active)
                                <span class="rounded bg-slate-200 px-2 py-0.5 text-xs text-slate-600">Inactive</span>
                            @endunless
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $chargeType->code }}</span>
                        </div>
                    </div>

                    <div class="flex flex-wrap gap-1 text-[11px]">
                        @if ($chargeType->postable_to_folio)
                            <span class="rounded bg-blue-50 px-2 py-0.5 text-blue-700">Posts to guest folio</span>
                        @endif
                        @if ($chargeType->walk_in_payable)
                            <span class="rounded bg-green-50 px-2 py-0.5 text-green-700">Sellable to walk-ins</span>
                        @endif
                        @if ($chargeType->free_for_resident_guests)
                            <span class="rounded bg-purple-50 px-2 py-0.5 text-purple-700">Free for resident guests</span>
                        @endif
                        <span class="rounded bg-slate-50 px-2 py-0.5 text-slate-600">{{ $chargeType->department->name ?? 'No department assigned' }}</span>
                    </div>

                    @if ($chargeType->description)
                        <p class="text-xs text-slate-500">{{ $chargeType->description }}</p>
                    @endif

                    @can('manage-charge-types')
                        <details class="text-xs">
                            <summary class="cursor-pointer text-slate-600 underline">Edit</summary>
                            <form method="POST" action="{{ route('charge-types.update', $chargeType) }}" class="mt-2 space-y-1">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $chargeType->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                <textarea name="description" rows="2" placeholder="Description (optional)" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">{{ $chargeType->description }}</textarea>
                                <select name="department_id" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                    <option value="">No department</option>
                                    @foreach ($departments as $department)
                                        <option value="{{ $department->id }}" @selected($chargeType->department_id === $department->id)>{{ $department->name }}</option>
                                    @endforeach
                                </select>
                                <label class="flex items-center gap-1"><input type="hidden" name="postable_to_folio" value="0"><input type="checkbox" name="postable_to_folio" value="1" @checked($chargeType->postable_to_folio)> Can be posted to a guest's folio</label>
                                <label class="flex items-center gap-1"><input type="hidden" name="walk_in_payable" value="0"><input type="checkbox" name="walk_in_payable" value="1" @checked($chargeType->walk_in_payable)> Can be sold on the spot to a walk-in</label>
                                <label class="flex items-center gap-1"><input type="hidden" name="free_for_resident_guests" value="0"><input type="checkbox" name="free_for_resident_guests" value="1" @checked($chargeType->free_for_resident_guests)> Free for a currently checked-in guest</label>
                                @unless ($chargeType->is_system)
                                    <label class="flex items-center gap-1"><input type="hidden" name="is_active" value="0"><input type="checkbox" name="is_active" value="1" @checked($chargeType->is_active)> Active</label>
                                @endunless
                                <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                            </form>
                            @unless ($chargeType->is_system)
                                <form method="POST" action="{{ route('charge-types.destroy', $chargeType) }}" class="mt-1"
                                      onsubmit="return confirm(@js('Delete \"'.$chargeType->name.'\" permanently? Only possible if it has never been charged, sold, or given a rate — deactivate it instead if you just want to stop using it.'));">
                                    @csrf @method('DELETE')
                                    <button class="text-red-700 hover:underline">Delete</button>
                                </form>
                            @endunless
                        </details>
                    @endcan

                    @if ($chargeType->walk_in_payable)
                        <table class="w-full text-sm">
                            <thead class="text-left text-xs uppercase text-slate-500">
                                <tr><th class="py-1">Rate</th><th class="py-1 text-right">Amount</th><th class="py-1"></th></tr>
                            </thead>
                            <tbody class="divide-y">
                                @forelse ($chargeType->rates as $rate)
                                    <tr>
                                        <td class="py-1">{{ $rate->name }}</td>
                                        <td class="py-1 text-right">{{ number_format($rate->amount, 0) }}</td>
                                        <td class="py-1 text-right space-x-2 whitespace-nowrap">
                                            @can('manage-charge-types')
                                                <form method="POST" action="{{ route('charge-types.rates.toggle', $rate) }}" class="inline">
                                                    @csrf
                                                    <button class="text-xs {{ $rate->is_active ? 'text-red-600' : 'text-green-700' }} hover:underline">
                                                        {{ $rate->is_active ? 'Disable' : 'Enable' }}
                                                    </button>
                                                </form>
                                                <details class="inline-block">
                                                    <summary class="cursor-pointer text-xs text-slate-600 underline">Edit</summary>
                                                    <form method="POST" action="{{ route('charge-types.rates.update', $rate) }}" class="mt-1 flex gap-1">
                                                        @csrf @method('PUT')
                                                        <input name="name" value="{{ $rate->name }}" required class="w-20 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                                        <input type="number" step="0.01" min="0.01" name="amount" value="{{ $rate->amount }}" required class="w-16 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                                        <button class="rounded bg-slate-800 px-2 py-0.5 text-xs text-white">Save</button>
                                                    </form>
                                                    <form method="POST" action="{{ route('charge-types.rates.destroy', $rate) }}" class="mt-1"
                                                          onsubmit="return confirm('Delete this rate? Only a rate with no sale history can be deleted.');">
                                                        @csrf @method('DELETE')
                                                        <button class="text-xs text-red-700 hover:underline">Delete</button>
                                                    </form>
                                                </details>
                                            @endcan
                                        </td>
                                    </tr>
                                @empty
                                    <tr><td class="py-1 text-slate-500" colspan="3">No rates yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>

                        @can('manage-charge-types')
                            <form method="POST" action="{{ route('charge-types.rates.store', $chargeType) }}" class="flex gap-2">
                                @csrf
                                <input name="name" placeholder="Rate name" required class="flex-1 rounded border border-slate-300 px-2 py-1 text-xs">
                                <input type="number" step="0.01" min="0.01" name="amount" placeholder="XAF" required class="w-24 rounded border border-slate-300 px-2 py-1 text-xs">
                                <button class="rounded bg-slate-800 px-3 py-1 text-xs text-white hover:bg-slate-700">Add</button>
                            </form>
                        @endcan
                    @endif
                </div>
            @endforeach
        </div>
    </div>

    @can('manage-charge-types')
        <div class="rounded-lg bg-white shadow-sm p-4 space-y-2 max-w-lg">
            <div class="text-xs font-medium">Add a charge type</div>
            <p class="text-[11px] text-slate-400">A code is generated automatically once you save — bar charge, restaurant charge, front desk, rental charge, a new facility fee... whatever you need.</p>
            <form method="POST" action="{{ route('charge-types.store') }}" class="space-y-2">
                @csrf
                <input name="name" placeholder="Name (e.g. Rental Charge)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <textarea name="description" placeholder="Description (optional)" rows="2" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm"></textarea>
                <select name="department_id" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">No department</option>
                    @foreach ($departments as $department)
                        <option value="{{ $department->id }}">{{ $department->name }}</option>
                    @endforeach
                </select>
                <label class="flex items-center gap-1 text-sm"><input type="hidden" name="postable_to_folio" value="0"><input type="checkbox" name="postable_to_folio" value="1" checked> Can be posted to a guest's folio</label>
                <label class="flex items-center gap-1 text-sm"><input type="hidden" name="walk_in_payable" value="0"><input type="checkbox" name="walk_in_payable" value="1"> Can be sold on the spot to a walk-in</label>
                <label class="flex items-center gap-1 text-sm"><input type="hidden" name="free_for_resident_guests" value="0"><input type="checkbox" name="free_for_resident_guests" value="1"> Free for a currently checked-in guest</label>
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create charge type</button>
            </form>
        </div>
    @endcan
</div>
@endsection
