<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title', 'Document') — {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
    {{--
        Phase 9.5 (PHASE9_AUDIT_AND_PLAN.md §3.6): a deliberately bare
        layout — no sidebar/nav/session-flash chrome from layouts/app.blade.php
        — so every printable document is just the document. "Print / Save as
        PDF" is the PDF export path: this sandbox cannot install/verify a
        server-side PDF library (Packagist is blocked — see §3.6/§7 for the
        recorded decision), and every modern browser's native print-to-PDF
        produces a real PDF file from this same page with zero new
        dependencies, so nothing here is a placeholder for a "future" PDF
        feature — it already works today.
    --}}
    <style>
        @media print {
            .no-print { display: none !important; }
            body { background: #fff !important; }
        }
        {{--
            Post-9.8 fix round: explicit A4 sizing (there was none before —
            the page relied entirely on the browser's own default paper
            size/margins) so the folio receipt's "2 copies on one A4
            sheet" layout (resources/views/front-office/stays/folio-print.blade.php)
            has a predictable page height to design against. Harmless for
            every other document that extends this same layout — it just
            means "print on A4 with a 1.2cm margin" instead of whatever
            the browser/printer's own default happened to be.
        --}}
        @page { size: A4; margin: 1.2cm; }
    </style>
</head>
<body class="bg-white text-slate-800 p-8 max-w-3xl mx-auto text-sm">
    <div class="no-print mb-6 flex items-center justify-between border-b border-slate-200 pb-4">
        <a href="{{ url()->previous() }}" class="text-sm text-slate-500 hover:underline">&larr; Back</a>
        <button onclick="window.print()" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">
            Print / Save as PDF
        </button>
    </div>

    {{--
        `$hidePrintHeader` (set by the controller when it renders a view
        that already carries its own header) skips this shared block —
        added because the folio receipt renders TWO self-contained copies
        (each with its own compact hotel header, guest/room details, and
        document title/date — see _folio-receipt-copy.blade.php), and
        this layout's own header on top of that made THREE header blocks
        on one page for what should only ever show two. Every other
        document (reservation confirmation, payment receipt, cash
        closing, etc.) has no header of its own, so it keeps using this
        one — only a view that sets `$hidePrintHeader = true` opts out.
    --}}
    @unless ($hidePrintHeader ?? false)
        @php($hotel = app(\App\Services\SettingsService::class))
        <div class="border-b border-slate-300 pb-4 mb-6 flex items-start justify-between">
            <div>
                <div class="text-lg font-semibold">{{ $hotel->hotelName() }}</div>
                @if ($hotel->hotelAddress())
                    <div class="text-xs text-slate-500">{{ $hotel->hotelAddress() }}</div>
                @endif
                @if ($hotel->hotelPhone() || $hotel->hotelEmail())
                    <div class="text-xs text-slate-500">
                        {{ $hotel->hotelPhone() }}{{ $hotel->hotelPhone() && $hotel->hotelEmail() ? ' · ' : '' }}{{ $hotel->hotelEmail() }}
                    </div>
                @endif
            </div>
            <div class="text-right">
                <div class="text-base font-semibold text-slate-700">@yield('document-title', 'Document')</div>
                <div class="text-xs text-slate-500">Printed {{ now()->format('d M Y H:i') }}</div>
            </div>
        </div>
    @endunless

    @yield('content')
</body>
</html>
