@extends('layouts.print')
@section('title', 'Guest Stay History — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'Guest Stay History')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500">
        <tr>
            <th class="py-1">Guest</th><th class="py-1">Room</th><th class="py-1">Check-in</th><th class="py-1">Check-out</th>
            <th class="py-1 text-right">Nights</th><th class="py-1 text-right">Charged</th><th class="py-1 text-right">Paid</th><th class="py-1 text-right">Balance</th>
        </tr>
    </thead>
    <tbody class="divide-y">
        @forelse ($stays as $row)
            <tr>
                <td class="py-1">{{ $row['stay']->guest->full_name }}</td>
                <td class="py-1">{{ $row['stay']->room->room_number }}</td>
                <td class="py-1">{{ $row['stay']->check_in_at->format('d M Y') }}</td>
                <td class="py-1">{{ $row['stay']->actual_check_out_at->format('d M Y') }}</td>
                <td class="py-1 text-right">{{ $row['nights'] }}</td>
                <td class="py-1 text-right">{{ number_format($row['total_charged'], 0) }}</td>
                <td class="py-1 text-right">{{ number_format($row['total_paid'], 0) }}</td>
                <td class="py-1 text-right">{{ number_format($row['balance'], 0) }}</td>
            </tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="8">No completed stays checked out in this period.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
