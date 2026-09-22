<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ config('app.name') }}</title>
    @include('partials.favicon')
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-100 min-h-screen flex items-center justify-center">
    <div class="w-full max-w-sm bg-white rounded-lg shadow p-8">
        <h1 class="text-xl font-semibold text-slate-800 mb-1">{{ config('app.name') }}</h1>
        <p class="text-sm text-slate-500 mb-6">@yield('subtitle', 'Sign in to continue')</p>

        @if ($errors->any())
            <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                @foreach ($errors->all() as $error)
                    <p>{{ $error }}</p>
                @endforeach
            </div>
        @endif

        @if (session('status'))
            <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-700">
                {{ session('status') }}
            </div>
        @endif

        @yield('content')
    </div>
</body>
</html>
