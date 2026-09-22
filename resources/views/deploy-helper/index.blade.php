<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>HMS Deploy Helper</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-slate-50 text-slate-800">
    <div class="mx-auto max-w-2xl p-6 space-y-6">
        <div class="rounded-lg border-2 border-red-300 bg-red-50 p-4 text-sm text-red-800">
            <p class="font-semibold">This page can run migrations and seeders against your live database.</p>
            <p class="mt-1">It is not part of the normal application — it exists only to bootstrap a shared-hosting install with no SSH access. Disable it (button at the bottom, or set <code class="rounded bg-red-100 px-1">ENABLE_DEPLOY_HELPER=false</code> in <code class="rounded bg-red-100 px-1">.env</code> yourself) as soon as you're done with it.</p>
        </div>

        <h1 class="text-xl font-semibold">HMS deploy helper</h1>

        <div class="rounded-lg bg-white p-4 shadow-sm space-y-2 text-sm">
            <div class="font-medium">Database connection</div>
            @if ($dbStatus['connected'])
                <p class="text-green-700">Connected.</p>
                <p>Migrations table exists: {{ $dbStatus['migrationsTableExists'] ? 'yes' : 'no — this looks like a fresh database.' }}</p>
            @else
                <p class="text-red-700">Could not connect: {{ $dbStatus['error'] }}</p>
                <p class="text-slate-500">Check your DB_* values in .env before doing anything else here.</p>
            @endif
        </div>

        @if ($usersAlreadySeeded)
            <div class="rounded-lg border border-amber-300 bg-amber-50 p-3 text-sm text-amber-800">
                The <code>users</code> table already has at least one row. Seeding again will duplicate demo data unless you explicitly confirm it below.
            </div>
        @endif

        <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">1. Run migrations</div>
            <p class="text-xs text-slate-500">Runs <code>php artisan migrate --force</code>. Safe to run more than once — it only applies migrations that haven't run yet.</p>
            <form method="POST" action="{{ route('deploy-helper.migrate') }}" class="space-y-2">
                <input type="hidden" name="token" value="{{ $token }}">
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="disable_after" value="1"> Disable this helper immediately afterward
                </label>
                <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Run migrations</button>
            </form>
        </div>

        <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">2. Seed demo/reference data</div>
            <p class="text-xs text-slate-500">Runs <code>php artisan db:seed --force</code> (roles/permissions, settings, demo users, rooms, demo menu/inventory data). Only needed once, on a fresh install — on a hotel already live with real staff and guests, checking the box below to force it again will duplicate demo accounts and reference data. If you only need to pick up a permissions change or the four starter financial accounts on a hotel that's already live, use 2b or 2c below instead — those are always safe to re-run.</p>
            <form method="POST" action="{{ route('deploy-helper.seed') }}" class="space-y-2">
                <input type="hidden" name="token" value="{{ $token }}">
                @if ($usersAlreadySeeded)
                    <label class="flex items-center gap-2 text-xs text-amber-700">
                        <input type="checkbox" name="force" value="1" required> I understand this may duplicate data — seed anyway
                    </label>
                @endif
                <label class="flex items-center gap-2 text-xs text-slate-600">
                    <input type="checkbox" name="disable_after" value="1"> Disable this helper immediately afterward
                </label>
                <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Run seeders</button>
            </form>
        </div>

        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">2b. Update roles &amp; permissions only</div>
            <p class="text-xs text-slate-600">Runs just the roles/permissions seeder — always safe to click, any number of times, even on a live hotel with real staff and guests already in the system. It never touches guests, rooms, or any other data, and it's what makes a newly-granted staff permission take effect immediately. Use this one (not the big "Run seeders" above) whenever a support update says a role's permissions changed.</p>
            <form method="POST" action="{{ route('deploy-helper.seed-roles-and-permissions') }}" class="space-y-2">
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="rounded bg-blue-700 px-3 py-1.5 text-sm text-white hover:bg-blue-600">Update roles &amp; permissions</button>
            </form>
        </div>

        <div class="rounded-lg border border-blue-200 bg-blue-50 p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">2c. Set up the four starter financial accounts</div>
            <p class="text-xs text-slate-600">Runs just the financial-accounts seeder — Cash, Bank, MTN Mobile Money, Orange Money. Also always safe to click any number of times: if an account with that name already exists it's left completely untouched, so this only ever fills in whichever one is still missing. Use this if the account dropdown on the Payments, Refunds, Expenses, or Day Close screens shows no accounts to choose from.</p>
            <form method="POST" action="{{ route('deploy-helper.seed-financial-accounts') }}" class="space-y-2">
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="rounded bg-blue-700 px-3 py-1.5 text-sm text-white hover:bg-blue-600">Set up financial accounts</button>
            </form>
        </div>

        <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">3. Clear cached config/routes/views</div>
            <p class="text-xs text-slate-500">Runs <code>config:clear</code>, <code>cache:clear</code>, <code>route:clear</code>, <code>view:clear</code> — useful if a previous deploy left a stale cache. Never destructive to your data.</p>
            <form method="POST" action="{{ route('deploy-helper.clear-caches') }}">
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Clear caches</button>
            </form>
        </div>

        <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">4. Run a data integrity check</div>
            <p class="text-xs text-slate-500">Read-only — checks folio balances against their transaction ledgers, Accounts Receivable consistency, room/stay status agreement, negative stock, and purchase-order receiving state. Nothing here writes to your database. Requires migrations to have already run.</p>
            <a href="{{ route('deploy-helper.integrity-check', ['token' => $token]) }}" class="inline-block rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Run integrity check</a>
        </div>

        <div class="rounded-lg bg-white p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">5. Fix the hotel logo (create the storage symlink)</div>
            <p class="text-xs text-slate-500">
                An uploaded hotel logo (and the browser favicon, which reuses it) is stored on the "public" disk and served from <code>/storage/...</code> — a URL that only works once <code>public/storage</code> is linked to <code>storage/app/public</code>. Laravel never creates this automatically; it needs <code>php artisan storage:link</code>, which this button runs for hosts with no terminal.
            </p>
            @if ($storageLinkExists)
                <p class="text-green-700 text-xs">public/storage is already linked — the logo/favicon should load correctly.</p>
            @else
                <p class="text-amber-700 text-xs">public/storage is NOT linked yet — this is why an uploaded logo (and the favicon) currently 404 in the browser.</p>
            @endif
            <form method="POST" action="{{ route('deploy-helper.storage-link') }}">
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create storage link</button>
            </form>
        </div>

        <div class="rounded-lg border-2 border-red-300 bg-white p-4 shadow-sm space-y-3">
            <div class="font-medium text-sm">6. Disable this helper now</div>
            <p class="text-xs text-slate-500">Attempts to set <code>ENABLE_DEPLOY_HELPER=false</code> in .env automatically. If your host's file permissions don't allow that, it will tell you plainly so you can edit .env by hand instead.</p>
            <form method="POST" action="{{ route('deploy-helper.disable') }}">
                <input type="hidden" name="token" value="{{ $token }}">
                <button class="rounded bg-red-700 px-3 py-1.5 text-sm text-white hover:bg-red-600">Disable now</button>
            </form>
        </div>
    </div>
</body>
</html>
