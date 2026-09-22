<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Receipt') — {{ config('app.name') }}</title>
    <style>
        {{--
            "the bar and the restaurant will be using but the pos device
            that can carry the application and that prints out the small
            pos receipts" — bar/restaurant/front-desk POS tickets print on
            a narrow thermal receipt printer (80mm roll paper), not a
            regular A4 printer. layouts/print.blade.php (the shared
            back-office document layout — reservation confirmations,
            guest invoices, payment receipts, cash closings) is the wrong
            shape entirely for that: full A4 width/margins, a heavy
            Tailwind CDN load, and a wide multi-column table that would
            either get cut off or print illegibly small on an 80mm roll.
            This is a separate, dedicated layout for that hardware: fixed
            80mm width, no framework dependency (a POS printer has no use
            for one, and a hand-written stylesheet this size is easier to
            get exactly right for a fixed narrow width), monospace type,
            and a single column throughout so nothing can ever overflow
            the roll's width.
        --}}
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; }
        body {
            width: 80mm;
            margin: 0 auto;
            padding: 3mm;
            font-family: 'Courier New', Consolas, monospace;
            font-size: 12px;
            line-height: 1.4;
            color: #000;
            background: #fff;
        }
        .center { text-align: center; }
        .bold { font-weight: 700; }
        .muted { color: #555; font-size: 10px; }
        .divider { border: none; border-top: 1px dashed #000; margin: 2mm 0; }
        .row { display: flex; justify-content: space-between; gap: 6px; margin: 0.5mm 0; }
        .item-row { margin: 1.5mm 0; }
        .item-name { display: block; word-break: break-word; }
        .item-meta { display: flex; justify-content: space-between; font-size: 11px; color: #333; }
        .total-row { display: flex; justify-content: space-between; font-size: 14px; font-weight: 700; margin-top: 1mm; }
        .toolbar { margin-bottom: 3mm; }
        .toolbar a, .toolbar button {
            font-family: inherit; font-size: 11px; color: #1d4ed8; background: none;
            border: none; padding: 0; cursor: pointer; text-decoration: underline;
        }
        @media print {
            .no-print { display: none !important; }
            @page { size: 80mm auto; margin: 0; }
            body { width: 80mm; padding: 2mm; }
        }
        @media screen {
            /* Framed on screen only, so it's obviously a narrow ticket
               and not a mis-rendered full page — the frame itself never
               prints. */
            body { border: 1px dashed #cbd5e1; margin-top: 1.5rem; box-shadow: 0 1px 4px rgba(0,0,0,0.06); }
        }
    </style>
</head>
<body>
    <div class="no-print toolbar center">
        <a href="{{ url()->previous() }}">&larr; Back</a>
        &nbsp;·&nbsp;
        <button onclick="window.print()">Print</button>
    </div>

    @php($hotel = app(\App\Services\SettingsService::class))
    <div class="center">
        <div class="bold" style="font-size: 14px;">{{ $hotel->hotelName() }}</div>
        @if ($hotel->hotelAddress())
            <div class="muted">{{ $hotel->hotelAddress() }}</div>
        @endif
        @if ($hotel->hotelPhone() || $hotel->hotelEmail())
            <div class="muted">
                {{ $hotel->hotelPhone() }}{{ $hotel->hotelPhone() && $hotel->hotelEmail() ? ' · ' : '' }}{{ $hotel->hotelEmail() }}
            </div>
        @endif
        <div class="bold" style="margin-top: 2mm;">@yield('document-title', 'Receipt')</div>
        <div class="muted">{{ now()->format('d M Y H:i') }}</div>
    </div>
    <hr class="divider">

    @yield('content')
</body>
</html>
