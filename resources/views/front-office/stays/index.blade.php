@extends('layouts.app')
@section('title', 'Active Stays')
@section('content')
<div class="overflow-hidden rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Room</th><th class="p-3">Guest</th><th class="p-3">Check-in</th><th class="p-3">Expected checkout</th><th class="p-3">Folio balance</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($stays as $stay)
                <tr>
                    <td class="p-3">{{ $stay->room->room_number }}</td>
                    <td class="p-3">
                        {{ $stay->guest->full_name }}
                        @if ($stay->is_complimentary)
                            <span class="ml-1 rounded bg-purple-100 px-1.5 py-0.5 text-xs text-purple-800">Comp</span>
                        @endif
                    </td>
                    <td class="p-3">{{ $stay->check_in_at->format('d M Y H:i') }}</td>
                    <td class="p-3">{{ $stay->expected_check_out_date->format('d M Y') }}</td>
                    <td class="p-3">{{ number_format($stay->folio->balance ?? 0, 0) }} XAF</td>
                    <td class="p-3"><a href="{{ route('stays.show', $stay) }}" class="text-slate-600 hover:underline">Open folio</a></td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="6">No active stays.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
