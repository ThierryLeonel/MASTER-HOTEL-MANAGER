@extends('layouts.app')
@section('title', 'Employees')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Emp #</th><th class="p-3">Name</th><th class="p-3">Job title</th><th class="p-3">Type</th><th class="p-3 text-right">Basic salary</th><th class="p-3">Status</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($profiles as $profile)
                    <tr>
                        <td class="p-3">{{ $profile->employee_number }}</td>
                        <td class="p-3">{{ $profile->user->name }}<br><span class="text-xs text-slate-500">{{ $profile->user->department->name ?? '—' }}</span></td>
                        <td class="p-3">{{ $profile->job_title }}</td>
                        <td class="p-3">{{ str_replace('_', ' ', $profile->employment_type) }}</td>
                        <td class="p-3 text-right">{{ $profile->basic_salary !== null ? number_format($profile->basic_salary, 0) : '—' }}</td>
                        <td class="p-3"><span class="rounded bg-slate-100 px-2 py-0.5 text-xs">{{ $profile->employment_status }}</span></td>
                        <td class="p-3">
                            @if ($profile->employment_status !== 'terminated')
                                <form method="POST" action="{{ route('hr.employees.terminate', $profile) }}" onsubmit="return confirm('Terminate this employee and deactivate their login?')" class="inline">
                                    @csrf
                                    <button class="text-red-600 hover:underline text-xs">Terminate</button>
                                </form>
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="7">No employee profiles yet.</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="col-span-1">
        <form method="POST" action="{{ route('hr.employees.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Add / update employee profile</div>
            <select name="user_id" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="">Staff account…</option>
                @foreach ($activeUsers as $user)
                    <option value="{{ $user->id }}">{{ $user->name }} ({{ $user->username }}){{ $user->employeeProfile ? ' — has a profile' : '' }}</option>
                @endforeach
            </select>
            <input name="job_title" placeholder="Job title" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <select name="employment_type" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="full_time">Full time</option>
                <option value="part_time">Part time</option>
                <option value="contract">Contract</option>
            </select>
            <input type="date" name="hire_date" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input type="number" step="0.01" min="0" name="basic_salary" placeholder="Basic salary (monthly)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <select name="payment_method" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="bank_transfer">Bank transfer</option>
                <option value="cash">Cash</option>
                <option value="mobile_money">Mobile money</option>
            </select>
            <input name="bank_account" placeholder="Bank account (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="national_id" placeholder="National ID (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Save</button>
            <p class="text-xs text-slate-500">Only staff accounts without a profile yet are listed here — editing an existing profile is done by resubmitting for the same user with updated values.</p>
        </form>
    </div>
</div>
@endsection
