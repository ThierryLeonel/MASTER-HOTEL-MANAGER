<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HMS Deploy Helper — {{ $heading }}</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
    <div class="mx-auto max-w-2xl p-6 space-y-6">
        <h1 class="text-xl font-semibold">{{ $heading }}</h1>

        <pre class="whitespace-pre-wrap rounded-lg bg-slate-900 p-4 text-xs text-slate-100">{{ $output }}</pre>

        @if ($disabled)
            <div class="rounded-lg border-2 {{ $disabled['success'] ? 'border-green-300 bg-green-50 text-green-800' : 'border-red-300 bg-red-50 text-red-800' }} p-4 text-sm">
                {{ $disabled['message'] }}
            </div>
        @endif

        @if ($token)
            <a href="{{ route('deploy-helper.index', ['token' => $token]) }}" class="inline-block rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Back to deploy helper</a>
        @endif
    </div>
</body>
</html>
