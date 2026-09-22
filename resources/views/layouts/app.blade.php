<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>@yield('title', 'Dashboard') — {{ config('app.name') }}</title>
    @include('partials.favicon')
    <script src="https://cdn.tailwindcss.com"></script>
    {{--
        Post-9.8 fix round: two requests handled here in one small stylesheet
        rather than a build step (this app has none — Tailwind loads via CDN,
        see PHASE9_AUDIT_AND_PLAN.md §10):
          1. "Let the module buttons have hover effects and also click
             effects, a button should show that it has been selected." —
             every button/nav link gets a consistent hover + press
             (`:active`) feel, and anything marked `.is-selected` (set from
             Blade on the current nav link, or from JS on a clicked
             cart/menu tile) gets a visibly distinct "selected" treatment.
          2. "Let the system be responsive... even with very smaller
             screens." — the sidebar was a fixed `w-56` block with no way
             to hide it on a phone-width screen; it now collapses off-canvas
             below the `md` breakpoint behind a hamburger button, exactly
             like any standard responsive admin shell.
    --}}
    <style>
        .btn-action, button, a.nav-link {
            transition: filter .12s ease, transform .05s ease, background-color .12s ease;
        }
        .btn-action:hover, button:hover, a.nav-link:hover {
            filter: brightness(1.08);
        }
        .btn-action:active, button:active, a.nav-link:active {
            transform: scale(0.97);
        }
        .is-selected {
            outline: 2px solid #0f172a;
            outline-offset: -2px;
            font-weight: 600;
        }
        a.nav-link.is-selected {
            background-color: rgb(30 41 59); /* slate-800 */
            color: #fff;
            outline: none;
        }

        /* Responsive off-canvas sidebar (mobile-first). */
        #app-sidebar {
            transform: translateX(-100%);
            transition: transform .2s ease;
        }
        #app-sidebar.sidebar-open {
            transform: translateX(0);
        }
        #sidebar-backdrop {
            display: none;
        }
        #sidebar-backdrop.sidebar-open {
            display: block;
        }
        @media (min-width: 768px) {
            #app-sidebar {
                position: static;
                transform: none;
            }
            #sidebar-backdrop {
                display: none !important;
            }
        }
    </style>
</head>
<body class="bg-slate-50 text-slate-800">
    <div class="flex min-h-screen flex-col md:flex-row">
        <header class="flex items-center justify-between border-b border-slate-200 bg-slate-900 px-4 py-3 text-slate-100 md:hidden">
            <div class="flex items-center gap-2 font-semibold">
                @if ($logoPath = app(\App\Services\SettingsService::class)->hotelLogoPath())
                    <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="Logo" class="h-8 w-8 rounded bg-white object-contain p-0.5">
                @endif
                <span>{{ app(\App\Services\SettingsService::class)->hotelName() }}</span>
            </div>
            <button type="button" onclick="document.getElementById('app-sidebar').classList.add('sidebar-open'); document.getElementById('sidebar-backdrop').classList.add('sidebar-open');" class="btn-action rounded border border-slate-600 px-3 py-1.5 text-sm">
                ☰ Menu
            </button>
        </header>

        <div id="sidebar-backdrop" onclick="document.getElementById('app-sidebar').classList.remove('sidebar-open'); this.classList.remove('sidebar-open');" class="fixed inset-0 z-30 bg-black/40 md:hidden"></div>

        <aside id="app-sidebar" class="fixed inset-y-0 left-0 z-40 w-64 shrink-0 overflow-y-auto bg-slate-900 text-slate-200 flex flex-col md:static md:z-auto md:w-56">
            <div class="flex items-center justify-between gap-2 border-b border-slate-700 p-4">
                <div class="flex min-w-0 items-center gap-2">
                    @if ($logoPath = app(\App\Services\SettingsService::class)->hotelLogoPath())
                        <img src="{{ \Illuminate\Support\Facades\Storage::disk('public')->url($logoPath) }}" alt="{{ app(\App\Services\SettingsService::class)->hotelName() }} logo" class="h-10 w-10 shrink-0 rounded bg-white object-contain p-1">
                    @endif
                    <div class="min-w-0">
                        <div class="truncate text-lg font-bold leading-tight">{{ app(\App\Services\SettingsService::class)->hotelName() }}</div>
                        <div class="text-[11px] uppercase tracking-wide text-slate-400">Hotel Management</div>
                    </div>
                </div>
                <button type="button" onclick="document.getElementById('app-sidebar').classList.remove('sidebar-open'); document.getElementById('sidebar-backdrop').classList.remove('sidebar-open');" class="btn-action shrink-0 rounded px-2 py-1 text-lg leading-none md:hidden">&times;</button>
            </div>
            <nav class="flex-1 p-3 space-y-1 text-sm">
                <a href="{{ route('dashboard') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('dashboard') ? 'is-selected' : '' }}">Dashboard</a>

                @can('manage-guests')
                    <a href="{{ route('guests.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('guests.*') ? 'is-selected' : '' }}">Guests</a>
                @endcan
                @can('manage-guests')
                    <a href="{{ route('companies.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('companies.*') ? 'is-selected' : '' }}">Companies</a>
                @endcan
                @can('manage-reservations')
                    <a href="{{ route('reservations.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('reservations.*') ? 'is-selected' : '' }}">Reservations</a>
                @endcan
                {{-- Feature 6: discount requests addressed to me personally. --}}
                @can('approve-discount')
                    <a href="{{ route('discount-approvals.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('discount-approvals.*') ? 'is-selected' : '' }}">Discount Approvals</a>
                @endcan
                @can('view-rooms')
                    <a href="{{ route('rooms.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('rooms.*') ? 'is-selected' : '' }}">Rooms</a>
                @endcan
                @can('checkin-guest')
                    <a href="{{ route('checkin.walk-in') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('checkin.*') ? 'is-selected' : '' }}">Walk-in Check-in</a>
                @endcan
                @can('manage-guests')
                    <a href="{{ route('stays.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('stays.*') ? 'is-selected' : '' }}">Active Stays</a>
                @endcan
                @can('grant-facility-access')
                    <a href="{{ route('facility-access.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('facility-access.*') ? 'is-selected' : '' }}">Gym &amp; Pool</a>
                @endcan
                @can('manage-facilities')
                    <a href="{{ route('facilities.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('facilities.*') ? 'is-selected' : '' }}">Facilities (rates)</a>
                @endcan
                @can('manage-housekeeping')
                    <a href="{{ route('housekeeping.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('housekeeping.*') ? 'is-selected' : '' }}">Housekeeping</a>
                @endcan
                @can('report-maintenance')
                    <a href="{{ route('maintenance.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('maintenance.*') ? 'is-selected' : '' }}">Maintenance</a>
                @endcan
                @can('operate-bar-pos')
                    <a href="{{ route('pos.index', 'bar') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('pos.*') && request()->route('outlet') === 'bar' ? 'is-selected' : '' }}">Bar POS</a>
                @endcan
                @can('operate-restaurant-pos')
                    <a href="{{ route('pos.index', 'restaurant') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('pos.*') && request()->route('outlet') === 'restaurant' ? 'is-selected' : '' }}">Restaurant POS</a>
                    {{-- Feature 3: one complimentary breakfast per occupied unit per business date. --}}
                    <a href="{{ route('breakfast.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('breakfast.*') ? 'is-selected' : '' }}">Complimentary Breakfast</a>
                @endcan
                @can('operate-front_desk-pos')
                    <a href="{{ route('pos.index', 'front_desk') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('pos.*') && request()->route('outlet') === 'front_desk' ? 'is-selected' : '' }}">Front Desk Sales</a>
                @endcan
                @can('operate-bar_outside-pos')
                    <a href="{{ route('pos.index', 'bar_outside') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('pos.*') && request()->route('outlet') === 'bar_outside' ? 'is-selected' : '' }}">Bar Outside POS</a>
                @endcan
                @can('operate-bucareaux-pos')
                    <a href="{{ route('pos.index', 'bucareaux') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('pos.*') && request()->route('outlet') === 'bucareaux' ? 'is-selected' : '' }}">Bucareaux POS</a>
                @endcan
                @can('record-payment')
                    <a href="{{ route('payments.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('payments.*') ? 'is-selected' : '' }}">Payments</a>
                @endcan

                @canany(['manage-products', 'manage-suppliers', 'manage-purchase-orders', 'approve-purchase-order', 'receive-goods'])
                    <div class="mt-3 border-t border-slate-700 pt-3 text-xs uppercase tracking-wide text-slate-500">Inventory</div>
                @endcanany
                @can('manage-products')
                    <a href="{{ route('inventory.products.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('inventory.products.*') ? 'is-selected' : '' }}">Products</a>
                @endcan
                @can('manage-suppliers')
                    <a href="{{ route('inventory.suppliers.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('inventory.suppliers.*') ? 'is-selected' : '' }}">Suppliers</a>
                @endcan
                @canany(['manage-purchase-orders', 'approve-purchase-order'])
                    <a href="{{ route('inventory.purchase-orders.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('inventory.purchase-orders.*') ? 'is-selected' : '' }}">Purchase Orders</a>
                @endcanany
                @can('receive-goods')
                    <a href="{{ route('inventory.goods-receipts.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('inventory.goods-receipts.*') ? 'is-selected' : '' }}">Goods Receipts</a>
                @endcan
                @canany(['request-stock', 'approve-requisition'])
                    <a href="{{ route('inventory.requisitions.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('inventory.requisitions.*') ? 'is-selected' : '' }}">Requisitions</a>
                @endcanany
                @can('manage-stock-count')
                    <a href="{{ route('inventory.stock-counts.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('inventory.stock-counts.*') ? 'is-selected' : '' }}">Stock Counts</a>
                @endcan
                {{-- Wastage nav link intentionally removed (post-9.8 fix
                     round, "there is not supposed to be any wastage
                     recording or module") — nobody holds record-wastage or
                     approve-wastage anymore (RolesAndPermissionsSeeder), so
                     this @canany would never render for anyone anyway; the
                     link itself is removed too so no dead markup remains. --}}

                @canany(['manage-expenses', 'approve-expense', 'approve-large-expense', 'pay-expense', 'request-refund', 'approve-refund', 'record-cash-reconciliation', 'lock-accounting-day', 'authorize-cash-disbursement', 'record-cash-disbursement', 'manage-own-shift', 'view-shift-reports', 'manage-financial-accounts', 'manage-accounts-receivable'])
                    <div class="mt-3 border-t border-slate-700 pt-3 text-xs uppercase tracking-wide text-slate-500">Finance</div>
                @endcanany
                @canany(['manage-expenses', 'approve-expense', 'approve-large-expense', 'pay-expense'])
                    <a href="{{ route('finance.expenses.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('finance.expenses.*') ? 'is-selected' : '' }}">Expenses</a>
                @endcanany
                @canany(['request-refund', 'approve-refund'])
                    <a href="{{ route('finance.refunds.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('finance.refunds.*') ? 'is-selected' : '' }}">Refunds</a>
                @endcanany
                @canany(['authorize-cash-disbursement', 'record-cash-disbursement'])
                    <a href="{{ route('finance.cash-disbursements.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('finance.cash-disbursements.*') ? 'is-selected' : '' }}">Cash Disbursements</a>
                @endcanany
                @canany(['manage-own-shift', 'view-shift-reports'])
                    <a href="{{ route('finance.shifts.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('finance.shifts.*') ? 'is-selected' : '' }}">Shifts</a>
                @endcanany
                @canany(['record-cash-reconciliation', 'lock-accounting-day'])
                    <a href="{{ route('finance.accounting-days.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('finance.accounting-days.*') ? 'is-selected' : '' }}">Day Close</a>
                @endcanany
                @can('manage-financial-accounts')
                    <a href="{{ route('finance.financial-accounts.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('finance.financial-accounts.*') ? 'is-selected' : '' }}">Financial Accounts</a>
                @endcan
                @can('manage-accounts-receivable')
                    <a href="{{ route('finance.accounts-receivable.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('finance.accounts-receivable.*') ? 'is-selected' : '' }}">Client Debt</a>
                @endcan

                {{-- Post-9.8 "view of HR" restriction: the whole HR & Payroll
                     section, self-service links included, is now visible
                     only to whoever holds clock-own-attendance — which,
                     per RolesAndPermissionsSeeder, is exactly Owner,
                     General Manager, and Accountant. Duty Manager,
                     Cashier, Front Desk, Bar Staff, and Restaurant Staff
                     no longer see this section at all. --}}
                @can('clock-own-attendance')
                    <div class="mt-3 border-t border-slate-700 pt-3 text-xs uppercase tracking-wide text-slate-500">HR &amp; Payroll</div>
                    @can('manage-employees')
                        <a href="{{ route('hr.employees.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('hr.employees.*') ? 'is-selected' : '' }}">Employees</a>
                    @endcan
                    <a href="{{ route('hr.attendance.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('hr.attendance.*') ? 'is-selected' : '' }}">Attendance</a>
                    <a href="{{ route('hr.leave-requests.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('hr.leave-requests.*') ? 'is-selected' : '' }}">Leave</a>
                    @can('manage-employees')
                        <a href="{{ route('hr.payroll-deductions.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('hr.payroll-deductions.*') ? 'is-selected' : '' }}">Deductions</a>
                    @endcan
                    @canany(['manage-payroll', 'approve-payroll-run', 'pay-payroll-run'])
                        <a href="{{ route('hr.payroll-runs.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('hr.payroll-runs.*') ? 'is-selected' : '' }}">Payroll Runs</a>
                    @endcanany
                    <a href="{{ route('hr.my-payslips') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('hr.my-payslips') ? 'is-selected' : '' }}">My Payslips</a>
                @endcan

                @canany(['view-financial-reports', 'view-operational-reports', 'view-inventory-reports', 'view-hr-reports', 'view-shift-reports', 'view-guest-history'])
                    <div class="mt-3 border-t border-slate-700 pt-3 text-xs uppercase tracking-wide text-slate-500">Reports</div>
                    <a href="{{ route('reports.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('reports.*') ? 'is-selected' : '' }}">Reports</a>
                @endcanany

                @canany(['manage-users', 'manage-departments', 'manage-settings'])
                    <div class="mt-3 border-t border-slate-700 pt-3 text-xs uppercase tracking-wide text-slate-500">Administration</div>
                @endcanany
                @can('manage-users')
                    <a href="{{ route('admin.users.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.users.*') ? 'is-selected' : '' }}">Users</a>
                @endcan
                @can('manage-departments')
                    <a href="{{ route('admin.departments.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.departments.*') ? 'is-selected' : '' }}">Departments</a>
                @endcan
                @can('manage-settings')
                    <a href="{{ route('admin.room-categories.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.room-categories.*') ? 'is-selected' : '' }}">Room Categories</a>
                    <a href="{{ route('admin.settings.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.settings.*') ? 'is-selected' : '' }}">Settings</a>
                @endcan
                @can('manage-sync')
                    <a href="{{ route('admin.sync.index') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('admin.sync.*') ? 'is-selected' : '' }}">Sync Status</a>
                @endcan
                {{-- Seventh post-9.8 fix round, follow-up (PHASE9_AUDIT_AND_PLAN.md
                     §20.12) — deliberately styled apart from the rest of
                     Administration (red text, own heading) since this is the one
                     link in this whole nav that can permanently delete data. --}}
                @can('manage-system-data')
                    <div class="mt-3 border-t border-slate-700 pt-3 text-xs uppercase tracking-wide text-red-400">Danger Zone</div>
                    <a href="{{ route('admin.system-data.index') }}" class="nav-link block rounded px-3 py-2 text-red-300 hover:bg-slate-800 hover:text-red-200 {{ request()->routeIs('admin.system-data.*') ? 'is-selected' : '' }}">Master Admin — Data</a>
                @endcan

                <div class="mt-3 border-t border-slate-700 pt-3 text-xs uppercase tracking-wide text-slate-500">My Account</div>
                <a href="{{ route('my-pin.edit') }}" class="nav-link block rounded px-3 py-2 hover:bg-slate-800 {{ request()->routeIs('my-pin.*') ? 'is-selected' : '' }}">Change My PIN</a>
            </nav>
            <div class="p-3 border-t border-slate-700 text-xs">
                <div class="mb-2">{{ auth()->user()->name }}<br>
                    <span class="text-slate-400">{{ auth()->user()->getRoleNames()->first() ?? 'no role' }}</span>
                </div>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <button class="btn-action w-full rounded bg-slate-700 px-3 py-1.5 hover:bg-slate-600">Log out</button>
                </form>
            </div>
        </aside>

        <main class="min-w-0 flex-1 p-4 md:p-6">
            @if (session('status'))
                <div class="mb-4 rounded border border-green-200 bg-green-50 p-3 text-sm text-green-700">
                    {{ session('status') }}
                </div>
            @endif
            @if ($errors->any())
                <div class="mb-4 rounded border border-red-200 bg-red-50 p-3 text-sm text-red-700">
                    @foreach ($errors->all() as $error)
                        <p>{{ $error }}</p>
                    @endforeach
                </div>
            @endif

            <h1 class="text-xl font-semibold mb-4 md:text-2xl">@yield('title', 'Dashboard')</h1>

            @yield('content')
        </main>
    </div>
</body>
</html>
