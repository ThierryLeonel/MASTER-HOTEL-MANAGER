@extends('layouts.app')
@section('title', 'Reports')
@section('content')
<div class="grid grid-cols-2 gap-4">
    @can('view-financial-reports')
        <a href="{{ route('reports.kpi-dashboard') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">KPI Dashboard</div>
            <p class="text-sm text-slate-500">Occupancy, ADR, RevPAR, revenue, and cash flow at a glance.</p>
        </a>
        <a href="{{ route('reports.financial') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Financial</div>
            <p class="text-sm text-slate-500">Revenue by source, cash flow, expenses, and accounts receivable aging.</p>
        </a>
        <a href="{{ route('reports.income-statement') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Income Statement</div>
            <p class="text-sm text-slate-500">Revenue minus expenses over a date range (management estimate — not a formal double-entry statement).</p>
        </a>
        <a href="{{ route('reports.weekly-owner-summary') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Weekly Owner Summary</div>
            <p class="text-sm text-slate-500">A narrative rollup of occupancy, revenue, expenses, cash flow, and receivables for the last 7 days.</p>
        </a>
        <a href="{{ route('reports.payment-summary') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Payment Summary</div>
            <p class="text-sm text-slate-500">Every payment recorded in a date range, by method — printable, and exportable as CSV.</p>
        </a>
        <a href="{{ route('reports.expense-report') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Expense Report</div>
            <p class="text-sm text-slate-500">Every individual expense in a date range with its status — printable, and exportable as CSV.</p>
        </a>
        <a href="{{ route('reports.management-report') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Management Report</div>
            <p class="text-sm text-slate-500">KPI headline figures plus revenue/expense/receivables breakdowns on one printable page.</p>
        </a>
    @endcan
    @canany(['view-financial-reports', 'view-shift-reports'])
        <a href="{{ route('reports.department-reconciliation') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Department Reconciliation</div>
            <p class="text-sm text-slate-500">Cash and sales/income by department — front desk, bar, restaurant, bar outside, bucareaux — with each department's closed shifts.</p>
        </a>
    @endcanany
    @can('view-operational-reports')
        <a href="{{ route('reports.occupancy') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Occupancy</div>
            <p class="text-sm text-slate-500">Occupancy rate, ADR, and RevPAR over a date range.</p>
        </a>
        <a href="{{ route('reports.arrivals-departures') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Arrivals &amp; Departures</div>
            <p class="text-sm text-slate-500">Who checked in and out over a date range.</p>
        </a>
        <a href="{{ route('reports.guest-stay-history') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Guest Stay History</div>
            <p class="text-sm text-slate-500">Completed stays with charge/payment totals over a date range.</p>
        </a>
    @endcan
    {{-- Feature 2: its own permission, so this card can appear for a
         Marketing Manager (or Accountant) who holds 'view-guest-history'
         but not the broader 'view-operational-reports'. --}}
    @can('view-guest-history')
        <a href="{{ route('reports.guest-transaction-history.index') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Guest Transaction History</div>
            <p class="text-sm text-slate-500">Search a guest to see their full transaction history — charges, payments, refunds, and settlements — across every stay.</p>
        </a>
    @endcan
    @can('view-inventory-reports')
        <a href="{{ route('reports.inventory') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">Inventory</div>
            <p class="text-sm text-slate-500">Current stock valuation, wastage cost, and low-stock products.</p>
        </a>
    @endcan
    @can('view-hr-reports')
        <a href="{{ route('reports.hr') }}" class="block rounded-lg bg-white p-4 shadow-sm hover:shadow">
            <div class="font-medium">HR &amp; Payroll</div>
            <p class="text-sm text-slate-500">Payroll cost, attendance summary, and leave balances.</p>
        </a>
    @endcan
</div>
@endsection
