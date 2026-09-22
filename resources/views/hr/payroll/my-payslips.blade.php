@extends('layouts.app')
@section('title', 'My Payslips')
@section('content')
<div class="space-y-4">
    @forelse ($payslips as $payslip)
        <div class="rounded-lg bg-white shadow-sm p-4">
            <div class="flex justify-between items-center mb-2">
                <div class="font-medium">{{ $payslip->payrollRun->period_start->format('d M Y') }} – {{ $payslip->payrollRun->period_end->format('d M Y') }}</div>
                <span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $payslip->status }}</span>
            </div>
            <table class="w-full text-sm">
                <tbody class="divide-y">
                    @foreach ($payslip->lines as $line)
                        <tr>
                            <td class="py-1">{{ $line->label }}</td>
                            <td class="py-1 text-right {{ $line->type === 'deduction' ? 'text-red-600' : '' }}">
                                {{ $line->type === 'deduction' ? '-' : '' }}{{ number_format($line->amount, 0) }}
                            </td>
                        </tr>
                    @endforeach
                    <tr class="font-semibold">
                        <td class="py-1">Net pay</td>
                        <td class="py-1 text-right">{{ number_format($payslip->net_pay, 0) }}</td>
                    </tr>
                </tbody>
            </table>
        </div>
    @empty
        <p class="text-sm text-slate-500">No payslips yet.</p>
    @endforelse
</div>
{{ $payslips->links() }}
@endsection
