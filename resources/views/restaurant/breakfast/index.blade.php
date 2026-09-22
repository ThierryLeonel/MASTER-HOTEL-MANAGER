@extends('layouts.app')
@section('title', 'Complimentary Breakfast')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-2 gap-6">
    <div class="rounded-lg bg-white shadow-sm">
        <div class="border-b p-3 text-sm font-semibold">Occupied rooms — {{ $businessDate->format('d M Y') }}</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Room</th><th class="p-3">Guest</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($activeStays as $stay)
                    <tr>
                        <td class="p-3">{{ $stay->room->room_number }}</td>
                        <td class="p-3">{{ $stay->guest->full_name }}</td>
                        <td class="p-3">
                            @if ($redeemedStayIds->contains($stay->id))
                                <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Breakfast served today</span>
                            @else
                                <form method="POST" action="{{ route('breakfast.redeem', $stay) }}" class="flex items-center gap-2">
                                    @csrf
                                    <select name="menu_item_id" required class="rounded border border-slate-300 px-2 py-1 text-xs">
                                        <option value="">Choose breakfast item…</option>
                                        @foreach ($breakfastItems as $item)
                                            <option value="{{ $item->id }}">{{ $item->name }}</option>
                                        @endforeach
                                    </select>
                                    <button class="rounded bg-green-700 px-2 py-1 text-xs text-white hover:bg-green-600">Redeem</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="3" class="p-3 text-center text-slate-400">No occupied rooms right now.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-lg bg-white shadow-sm">
        <div class="border-b p-3 text-sm font-semibold">Today's redemptions</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Room</th><th class="p-3">Item</th><th class="p-3">Served by</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($todaysEntitlements as $entitlement)
                    <tr>
                        <td class="p-3">{{ $entitlement->room->room_number }}</td>
                        <td class="p-3">{{ $entitlement->menuItem->name ?? '—' }}</td>
                        <td class="p-3">{{ $entitlement->servedBy->name ?? '—' }}</td>
                        <td class="p-3">
                            @if ($entitlement->isReversed())
                                <span class="rounded bg-red-100 px-2 py-0.5 text-xs text-red-700" title="{{ $entitlement->reversal_reason }}">Reversed</span>
                            @else
                                <span class="rounded bg-green-100 px-2 py-0.5 text-xs text-green-700">Served {{ $entitlement->served_at->format('H:i') }}</span>
                            @endif
                        </td>
                        <td class="p-3">
                            @can('reverse-breakfast-entitlement')
                                @if (! $entitlement->isReversed())
                                    <form method="POST" action="{{ route('breakfast.reverse', $entitlement) }}" class="inline" onsubmit="return confirm('Reverse this breakfast redemption? This restores the stock it consumed but does NOT free up a new redemption for this stay today.');">
                                        @csrf
                                        <input type="hidden" name="reason" value="Reversed by manager">
                                        <button class="text-red-700 hover:underline text-xs">Reverse</button>
                                    </form>
                                @endif
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="p-3 text-center text-slate-400">No breakfasts redeemed yet today.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
