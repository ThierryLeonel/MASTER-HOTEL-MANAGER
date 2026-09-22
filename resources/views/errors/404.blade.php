<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    {{--
        Deliberately standalone — no @extends, no SettingsService call, no
        DB query of any kind. An error page has to render even in the
        exact moment something else is broken, so it never depends on
        anything that could itself be the reason a page failed to load.
        Found needed after a live incident where every online booking
        submission 404'd (see ReservationService::createFromWebsite()'s
        docblock) and, with no custom error view in the project, the
        visitor saw Laravel's bare framework default — unbranded and
        not styled to match the rest of this app, which is what actually
        prompted the "not responsive on my phone" report: it wasn't the
        booking FORM that looked broken, it was the plain error page a
        guest landed on after that specific bug fired.
    --}}
    <title>Page not found — {{ config('app.name') }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center px-4">
    <div class="w-full max-w-md rounded-lg bg-white p-6 text-center shadow-sm">
        <div class="mx-auto mb-4 flex h-12 w-12 items-center justify-center rounded-full bg-slate-100 text-slate-500 text-xl font-semibold">
            404
        </div>
        <h1 class="text-lg font-semibold text-slate-800">Page not found</h1>
        <p class="mt-2 text-sm text-slate-500">
            That page doesn't exist, or the link you followed is out of date. Nothing on your end went wrong.
        </p>
        <div class="mt-5 flex flex-col gap-2 sm:flex-row sm:justify-center">
            <a href="{{ url('/book') }}" class="w-full rounded bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700 sm:w-auto">
                Request a reservation
            </a>
            <a href="{{ url('/') }}" class="w-full rounded bg-slate-200 px-4 py-2 text-sm font-medium text-slate-700 hover:bg-slate-300 sm:w-auto">
                Go to the homepage
            </a>
        </div>
        <p class="mt-4 text-xs text-slate-400">
            {{ config('app.name') }}
        </p>
    </div>
</body>
</html>
