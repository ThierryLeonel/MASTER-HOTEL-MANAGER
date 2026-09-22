@extends('layouts.app')
@section('title', 'Payroll Runs')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Period</th><th class="p-3 text-right">Payslips</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($runs as $run)
                    <tr>
                        <td class="p-3">{{ $run->period_start->format('d M Y') }} – {{ $run->period_end->format('d M Y') }}</td>
                        <td class="p-3 text-right">{{ $run->payslips_count }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $run->status }}</span></td>
                        <td class="p-3"><a href="{{ route('hr.payroll-runs.show', $run) }}" class="text-slate-700 hover:underline text-xs">View</a></td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="4">No payroll runs yet.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $runs->links() }}
    </div>

    <div class="col-span-1">
        @can('manage-payroll')
            <form method="POST" action="{{ route('hr.payroll-runs.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">New payroll run</div>
                <input type="date" name="period_start" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input type="date" name="period_end" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <input name="notes" placeholder="Notes (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Create run</button>
            </form>
        @endcan
    </div>
</div>
@endsection
