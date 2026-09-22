<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HMS Deploy Helper — Data Integrity Check</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
    <div class="mx-auto max-w-2xl p-6 space-y-6">
        <h1 class="text-xl font-semibold">Data integrity check</h1>
        <p class="text-sm text-slate-500">Read-only — nothing here writes to your database. Each check below queries the live tables directly, the same way the equivalent report/service does elsewhere in the app.</p>

        <div class="space-y-3">
            @foreach ($results as $result)
                <div class="rounded-lg border p-4 text-sm {{ $result['passed'] ? 'border-green-200 bg-green-50' : 'border-red-300 bg-red-50' }}">
                    <div class="flex items-center justify-between">
                        <div class="font-medium">{{ $result['label'] }}</div>
                        <span class="rounded px-2 py-0.5 text-xs font-semibold {{ $result['passed'] ? 'bg-green-100 text-green-800' : 'bg-red-100 text-red-800' }}">
                            {{ $result['passed'] ? 'PASS' : $result['issue_count'] . ' ISSUE(S)' }}
                        </span>
                    </div>
                    @if (! $result['passed'])
                        <ul class="mt-2 list-disc pl-5 text-xs text-red-800 space-y-1">
                            @foreach ($result['issues'] as $issue)
                                <li>{{ $issue }}</li>
                            @endforeach
                        </ul>
                    @endif
                </div>
            @endforeach
        </div>

        @if ($token)
            <a href="{{ route('deploy-helper.index', ['token' => $token]) }}" class="inline-block rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Back to deploy helper</a>
        @endif
    </div>
</body>
</html>
