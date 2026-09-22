{{--
    Post-9.8 fix round — one printable copy of the folio receipt. Used
    twice by folio-print.blade.php (once "up", once "down" on the same A4
    sheet, per "the receipt should be duplicate, up and down, with them
    both carrying the same info"). Deliberately self-contained with its
    own compact hotel header (logo, name, region, phone, email) rather
    than relying only on layouts.print's shared header at the top of the
    page, since each torn-off copy needs to carry that information on its
    own. Font sizes here are kept small and consistent (text-[11px]/text-xs)
    so two full copies plus a signature line comfortably fit one A4 half
    each — "let the font not be too big for the receipt or too small".
--}}
@php($hotel = app(\App\Services\SettingsService::class))
<div class="print-copy" style="page-break-inside: avoid;">
    <div class="flex items-start justify-between border-b border-slate-300 pb-2 mb-2">
        <div class="flex items-center gap-2">
            @if ($logoPath = $hotel->hotelLogoPath())
                <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="Logo" class="h-10 w-10 rounded object-contain">
            @endif
            <div>
                <div class="text-sm font-bold">{{ $hotel->hotelName() }}</div>
                <div class="text-[10px] text-slate-500">
                    @if ($hotel->hotelRegion()) {{ $hotel->hotelRegion() }} @endif
                    @if ($hotel->hotelPhone()) · Tel: {{ $hotel->hotelPhone() }} @endif
                    @if ($hotel->hotelEmail()) · {{ $hotel->hotelEmail() }} @endif
                </div>
            </div>
        </div>
        <div class="text-right">
            <div class="text-xs font-semibold uppercase tracking-wide">{{ $documentTitle }}</div>
            <div class="text-[10px] text-slate-500">{{ now()->format('d M Y H:i') }}</div>
        </div>
    </div>

    <table class="w-full text-[11px] mb-2">
        <tbody>
            <tr>
                <td class="py-0.5 text-slate-500 w-24">Guest</td><td class="py-0.5 font-medium">{{ $stay->guest->full_name }}</td>
                <td class="py-0.5 text-slate-500 w-20">Room</td><td class="py-0.5">{{ $stay->room->room_number }} ({{ $stay->room->category->name }})</td>
            </tr>
            <tr>
                <td class="py-0.5 text-slate-500">Check-in</td><td class="py-0.5">{{ $stay->check_in_at->format('d M Y H:i') }}</td>
                <td class="py-0.5 text-slate-500">Check-out</td><td class="py-0.5">{{ $stay->actual_check_out_at?->format('d M Y H:i') ?? 'Not yet' }}</td>
            </tr>
            <tr>
                <td class="py-0.5 text-slate-500">Room charge</td>
                <td class="py-0.5" colspan="3">
                    @if ($stay->is_complimentary)
                        {{ number_format($stay->agreed_rate, 0) }} XAF / night (waived — complimentary)
                    @else
                        {{ number_format($stay->agreed_rate, 0) }} XAF &times; {{ $nightsSoFar }} {{ \Illuminate\Support\Str::plural('night', $nightsSoFar) }}
                        = {{ number_format($stay->agreed_rate * $nightsSoFar, 0) }} XAF
                    @endif
                </td>
            </tr>
        </tbody>
    </table>

    @if ($departmentSummary)
        <table class="w-full text-[11px] mb-2 border-t border-b border-slate-200">
            <tbody>
                @foreach ($departmentSummary as $label => $amount)
                    <tr><td class="py-0.5 text-slate-500">{{ $label }}</td><td class="py-0.5 text-right">{{ number_format($amount, 0) }} XAF</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table class="w-full text-[10px] mb-2">
        <thead class="border-b border-slate-300 text-left uppercase text-slate-500">
            <tr><th class="py-0.5">Date</th><th class="py-0.5">Description</th><th class="py-0.5 text-right">Amount</th></tr>
        </thead>
        <tbody>
            @foreach ($stay->folio->transactions as $tx)
                @unless ($tx->isVoided())
                    <tr>
                        <td class="py-0.5">{{ $tx->transaction_date->format('d M') }}</td>
                        <td class="py-0.5">{{ $tx->description }}</td>
                        <td class="py-0.5 text-right">{{ number_format($tx->amount, 0) }}</td>
                    </tr>
                @endunless
            @endforeach
        </tbody>
        <tfoot>
            <tr class="border-t-2 border-slate-800 font-semibold text-xs">
                <td class="py-1" colspan="2">Balance</td>
                <td class="py-1 text-right">{{ number_format($stay->folio->balance, 0) }} XAF</td>
            </tr>
        </tfoot>
    </table>

    @if ($stay->folio->accountsReceivable)
        <p class="text-[10px] text-amber-700 mb-2">
            Outstanding {{ number_format($stay->folio->accountsReceivable->amount_outstanding, 0) }} XAF moved to Accounts Receivable ({{ $stay->folio->accountsReceivable->status }}).
        </p>
    @endif

    <div class="mt-2 flex items-end justify-between text-[10px] text-slate-600">
        <div class="w-1/2">
            <div class="border-t border-slate-400 pt-1">Guest signature</div>
        </div>
        <div class="w-1/3 text-right">
            <div class="border-t border-slate-400 pt-1">Date</div>
        </div>
    </div>
</div>
