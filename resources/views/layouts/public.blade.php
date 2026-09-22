<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Book a room') — {{ $hotelName ?? config('app.name') }}</title>
    @include('partials.favicon')
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen">
    <header class="bg-white border-b shadow-sm">
        <div class="max-w-2xl mx-auto px-4 py-4">
            <h1 class="text-xl font-semibold text-slate-800">{{ $hotelName ?? config('app.name') }}</h1>
            <p class="text-sm text-slate-500">@yield('subtitle', 'Request a reservation')</p>
        </div>
    </header>

    <main class="max-w-2xl mx-auto px-4 py-6">
        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @yield('content')
    </main>

    <footer class="max-w-2xl mx-auto px-4 py-6 text-center text-xs text-slate-400">
        {{ $hotelName ?? config('app.name') }}
    </footer>
</body>
</html>
