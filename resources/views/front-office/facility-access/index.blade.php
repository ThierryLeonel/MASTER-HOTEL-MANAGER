@extends('layouts.app')
@section('title', 'Gym & Pool Access')
@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Access log</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr><th class="p-3">#</th><th class="p-3">Facility</th><th class="p-3">Who</th><th class="p-3 text-right">Amount</th><th class="p-3">Granted</th><th class="p-3"></th></tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($accesses as $access)
                        <tr>
                            <td class="p-3">{{ $access->access_number }}</td>
                            <td class="p-3">{{ $access->facility->name }}</td>
                            <td class="p-3">{{ $access->visitorLabel() }}</td>
                            <td class="p-3 text-right">{{ $access->amount_charged > 0 ? number_format($access->amount_charged, 0) : 'Free' }}</td>
                            <td class="p-3 text-xs">{{ $access->granted_at->format('d M H:i') }}</td>
                            <td class="p-3">
                                @if (! $access->isFree())
                                    <a href="{{ route('facility-access.print', $access) }}" target="_blank" class="text-xs text-blue-700 hover:underline">Print</a>
                                @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="6">No facility access recorded yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $accesses->links() }}
        </div>
    </div>

    <div class="lg:col-span-1 space-y-6">
        <form method="POST" action="{{ route('facility-access.guest') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Free access — checked-in guest</div>
            <select name="facility_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Facility…</option>
                @foreach ($facilities as $facility)
                    <option value="{{ $facility->id }}">{{ $facility->name }}</option>
                @endforeach
            </select>
            <select name="stay_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Guest (currently checked in)…</option>
                @foreach ($activeStays as $stay)
                    <option value="{{ $stay->id }}">{{ $stay->guest->full_name }} — Room {{ $stay->room->room_number }}</option>
                @endforeach
            </select>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Grant free access</button>
        </form>

        <form method="POST" action="{{ route('facility-access.walk-in') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Sell access — walk-in</div>
            <select name="facility_id" required onchange="showRates(this)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Facility…</option>
                @foreach ($facilities as $facility)
                    <option value="{{ $facility->id }}">{{ $facility->name }}</option>
                @endforeach
            </select>
            @foreach ($facilities as $facility)
                <select name="facility_rate_id" data-facility="{{ $facility->id }}" class="rate-select hidden w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                    <option value="">Rate…</option>
                    @foreach ($facility->rates as $rate)
                        <option value="{{ $rate->id }}">{{ $rate->name }} — {{ number_format($rate->amount, 0) }} XAF</option>
                    @endforeach
                </select>
            @endforeach
            <input name="visitor_name" placeholder="Visitor name" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="visitor_phone" placeholder="Phone (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <select name="financial_account_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Received into…</option>
                @foreach ($financialAccounts as $account)
                    <option value="{{ $account->id }}">{{ $account->name }}</option>
                @endforeach
            </select>
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Charge &amp; grant access</button>
        </form>
    </div>
</div>

<script>
function showRates(select) {
    document.querySelectorAll('.rate-select').forEach(el => {
        const isMatch = el.dataset.facility === select.value;
        el.classList.toggle('hidden', !isMatch);
        el.required = isMatch;
        if (!isMatch) el.value = '';
    });
}
</script>
@endsection
