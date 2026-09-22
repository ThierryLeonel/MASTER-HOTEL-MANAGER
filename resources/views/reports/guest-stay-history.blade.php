@extends('layouts.app')
@section('title', 'Guest Stay History')
@section('content')
@include('reports._date-filter')

<div class="mb-4 flex gap-3 text-xs">
    <a href="{{ route('reports.guest-stay-history.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
    <a href="{{ route('reports.guest-stay-history.csv', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-200 px-3 py-1.5 hover:bg-slate-300">Download CSV</a>
</div>

<div class="overflow-hidden rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Guest</th>
                <th class="p-3">Room</th>
                <th class="p-3">Check-in</th>
                <th class="p-3">Check-out</th>
                <th class="p-3 text-right">Nights</th>
                <th class="p-3 text-right">Charged</th>
                <th class="p-3 text-right">Paid</th>
                <th class="p-3 text-right">Balance</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($stays as $row)
                <tr>
                    <td class="p-3">{{ $row['stay']->guest->full_name }}</td>
                    <td class="p-3">{{ $row['stay']->room->room_number }} ({{ $row['stay']->room->category->name }})</td>
                    <td class="p-3">{{ $row['stay']->check_in_at->format('d M Y') }}</td>
                    <td class="p-3">{{ $row['stay']->actual_check_out_at->format('d M Y') }}</td>
                    <td class="p-3 text-right">{{ $row['nights'] }}</td>
                    <td class="p-3 text-right">{{ number_format($row['total_charged'], 0) }}</td>
                    <td class="p-3 text-right">{{ number_format($row['total_paid'], 0) }}</td>
                    <td class="p-3 text-right">{{ number_format($row['balance'], 0) }}</td>
                    <td class="p-3">
                        @if ($row['sent_to_receivables'])
                            <span class="rounded bg-amber-100 px-2 py-0.5 text-xs text-amber-700">Sent to AR</span>
                        @endif
                    </td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="9">No completed stays checked out in this period.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
