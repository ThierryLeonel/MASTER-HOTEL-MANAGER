@extends('layouts.app')
@section('title', 'Discount approvals')
@section('content')
<div class="rounded-lg bg-white shadow-sm">
    <div class="p-4 text-sm text-slate-500">
        Discount requests addressed to you personally — selecting your name on a reservation is only ever a
        request; approving one here, from your own logged-in session, is what actually authorizes it.
    </div>
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr>
                <th class="p-3">Reservation / Stay</th>
                <th class="p-3">Category</th>
                <th class="p-3">Standard rate</th>
                <th class="p-3">Requested rate</th>
                <th class="p-3">Requested by</th>
                <th class="p-3">Reason</th>
                <th class="p-3"></th>
            </tr>
        </thead>
        <tbody class="divide-y">
            @forelse ($pending as $approval)
                <tr>
                    <td class="p-3">
                        @if ($approval->approvable instanceof \App\Models\Reservation)
                            <a href="{{ route('reservations.show', $approval->approvable) }}" class="text-blue-600 underline">{{ $approval->approvable->reservation_number }}</a>
                        @else
                            Stay #{{ $approval->approvable_id }}
                        @endif
                    </td>
                    <td class="p-3">{{ $approval->roomCategory->name }}</td>
                    <td class="p-3">{{ number_format($approval->standard_rate, 0) }}</td>
                    <td class="p-3">{{ number_format($approval->agreed_rate, 0) }}{{ $approval->is_complimentary ? ' (complimentary)' : '' }}</td>
                    <td class="p-3">{{ $approval->requestedBy->name ?? '—' }}</td>
                    <td class="p-3">{{ $approval->reason }}</td>
                    <td class="p-3 space-x-2">
                        <form method="POST" action="{{ route('discount-approvals.approve', $approval) }}" class="inline">
                            @csrf
                            <button class="text-green-700 hover:underline">Approve</button>
                        </form>
                        <form method="POST" action="{{ route('discount-approvals.reject', $approval) }}" class="inline" onsubmit="return confirm('Reject this discount request?');">
                            @csrf
                            <input type="hidden" name="reason" value="Rejected by approver">
                            <button class="text-red-700 hover:underline">Reject</button>
                        </form>
                    </td>
                </tr>
            @empty
                <tr><td class="p-3 text-slate-500" colspan="7">No discount requests pending your approval.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
@endsection
