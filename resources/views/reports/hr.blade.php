@extends('layouts.app')
@section('title', 'HR & Payroll Report')
@section('content')
@include('reports._date-filter')

<div class="mb-4 text-xs">
    <a href="{{ route('reports.hr.print', ['start' => $start->toDateString(), 'end' => $end->toDateString()]) }}" class="rounded bg-slate-800 px-3 py-1.5 text-white hover:bg-slate-700">Print</a>
</div>

<div class="grid grid-cols-4 gap-4 mb-6">
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Payroll gross (paid)</div>
        <div class="text-2xl font-semibold">{{ number_format($payrollCost['gross'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Statutory deductions</div>
        <div class="text-2xl font-semibold">{{ number_format($payrollCost['statutory'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Payroll net (paid)</div>
        <div class="text-2xl font-semibold">{{ number_format($payrollCost['net'], 0) }}</div>
    </div>
    <div class="rounded-lg bg-white p-4 shadow-sm">
        <div class="text-xs text-slate-500">Payslips paid</div>
        <div class="text-2xl font-semibold">{{ $payrollCost['count'] }}</div>
    </div>
</div>

<div class="grid grid-cols-2 gap-6">
    <div class="rounded-lg bg-white shadow-sm">
        <div class="p-3 font-medium border-b">Attendance summary (period)</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Employee</th><th class="p-3 text-right">Present</th><th class="p-3 text-right">Absent</th><th class="p-3 text-right">Late</th><th class="p-3 text-right">Leave</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($attendance as $row)
                    <tr>
                        <td class="p-3">{{ $row['user']->name }}</td>
                        <td class="p-3 text-right">{{ $row['present'] }}</td>
                        <td class="p-3 text-right">{{ $row['absent'] }}</td>
                        <td class="p-3 text-right">{{ $row['late'] }}</td>
                        <td class="p-3 text-right">{{ $row['on_leave'] }}</td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="5">No attendance records in this period.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-lg bg-white shadow-sm">
        <div class="p-3 font-medium border-b">Leave balances ({{ $start->year }})</div>
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500"><tr><th class="p-3">Employee</th><th class="p-3">Type</th><th class="p-3 text-right">Used / Entitled</th></tr></thead>
            <tbody class="divide-y">
                @forelse ($leaveBalances as $balance)
                    <tr><td class="p-3">{{ $balance->user->name }}</td><td class="p-3">{{ $balance->leaveType->name }}</td><td class="p-3 text-right">{{ $balance->used_days }} / {{ $balance->entitled_days }}</td></tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="3">No leave balances recorded for this year yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
@endsection
