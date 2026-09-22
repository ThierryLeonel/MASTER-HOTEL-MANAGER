@extends('layouts.print')
@section('title', 'HR & Payroll Report — ' . $start->toDateString() . ' to ' . $end->toDateString())
@section('document-title', 'HR & Payroll Report')
@section('content')

<p class="text-xs text-slate-500 mb-4">{{ $start->format('d M Y') }} &ndash; {{ $end->format('d M Y') }}</p>

<table class="w-full text-sm mb-6">
    <tbody class="divide-y">
        <tr><td class="py-2 text-slate-500">Payroll gross (paid)</td><td class="py-2 text-right">{{ number_format($payrollCost['gross'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Statutory deductions</td><td class="py-2 text-right">{{ number_format($payrollCost['statutory'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Payroll net (paid)</td><td class="py-2 text-right">{{ number_format($payrollCost['net'], 0) }}</td></tr>
        <tr><td class="py-2 text-slate-500">Payslips paid</td><td class="py-2 text-right">{{ $payrollCost['count'] }}</td></tr>
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Attendance summary (period)</p>
<table class="w-full text-sm mb-6">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Employee</th><th class="py-1 text-right">Present</th><th class="py-1 text-right">Absent</th><th class="py-1 text-right">Late</th><th class="py-1 text-right">Leave</th></tr></thead>
    <tbody class="divide-y">
        @forelse ($attendance as $row)
            <tr>
                <td class="py-1">{{ $row['user']->name }}</td>
                <td class="py-1 text-right">{{ $row['present'] }}</td>
                <td class="py-1 text-right">{{ $row['absent'] }}</td>
                <td class="py-1 text-right">{{ $row['late'] }}</td>
                <td class="py-1 text-right">{{ $row['on_leave'] }}</td>
            </tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="5">No attendance records in this period.</td></tr>
        @endforelse
    </tbody>
</table>

<p class="text-xs font-medium mb-1">Leave balances ({{ $start->year }})</p>
<table class="w-full text-sm">
    <thead class="border-b border-slate-300 text-left text-xs uppercase text-slate-500"><tr><th class="py-1">Employee</th><th class="py-1">Type</th><th class="py-1 text-right">Used / Entitled</th></tr></thead>
    <tbody class="divide-y">
        @forelse ($leaveBalances as $balance)
            <tr><td class="py-1">{{ $balance->user->name }}</td><td class="py-1">{{ $balance->leaveType->name }}</td><td class="py-1 text-right">{{ $balance->used_days }} / {{ $balance->entitled_days }}</td></tr>
        @empty
            <tr><td class="py-1 text-slate-500" colspan="3">No leave balances recorded for this year yet.</td></tr>
        @endforelse
    </tbody>
</table>
@endsection
