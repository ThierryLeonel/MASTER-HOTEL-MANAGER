@extends('layouts.app')
@section('title', 'Statutory Deductions')
@section('content')
<div class="grid grid-cols-1 md:grid-cols-3 gap-6">
    <div class="col-span-2 space-y-4">
        @foreach ($deductionTypes as $type)
            <div class="rounded-lg bg-white shadow-sm p-4">
                <div class="flex justify-between items-center mb-2">
                    <div>
                        <span class="font-medium">{{ $type->name }}</span>
                        <span class="text-xs text-slate-500">({{ $type->code }})</span>
                        @unless ($type->is_active)
                            <span class="rounded bg-slate-100 px-2 py-0.5 text-xs ml-2">inactive</span>
                        @endunless
                    </div>
                    <div class="flex items-center gap-2">
                        <span class="text-xs text-slate-500">{{ str_replace('_', ' ', $type->calculation_method) }}</span>
                        <details class="text-xs">
                            <summary class="cursor-pointer text-slate-600 underline">Edit</summary>
                            <form method="POST" action="{{ route('hr.payroll-deductions.update', $type) }}" class="mt-2 w-64 space-y-1">
                                @csrf @method('PUT')
                                <input name="name" value="{{ $type->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                <input name="code" value="{{ $type->code }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                <select name="calculation_method" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                    <option value="flat_percent" @selected($type->calculation_method === 'flat_percent')>Flat percentage</option>
                                    <option value="progressive_brackets" @selected($type->calculation_method === 'progressive_brackets')>Progressive brackets</option>
                                </select>
                                <input type="number" step="0.01" min="0" max="100" name="flat_rate_percent" value="{{ $type->flat_rate_percent }}" placeholder="Flat rate %" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                <label class="flex items-center gap-1"><input type="checkbox" name="is_active" value="1" @checked($type->is_active)> Active</label>
                                <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                            </form>
                            <form method="POST" action="{{ route('hr.payroll-deductions.destroy', $type) }}" class="mt-1"
                                  onsubmit="return confirm(@js('Delete deduction type '.$type->name.' permanently? Its brackets will be deleted too.'));">
                                @csrf @method('DELETE')
                                <button class="text-red-700 hover:underline">Delete</button>
                            </form>
                        </details>
                    </div>
                </div>

                @if ($type->calculation_method === 'flat_percent')
                    <p class="text-sm">Flat rate: <strong>{{ $type->flat_rate_percent }}%</strong> of gross pay.</p>
                @else
                    <table class="w-full text-sm mt-2">
                        <thead class="text-left text-xs uppercase text-slate-500">
                            <tr><th class="py-1">From</th><th class="py-1">To</th><th class="py-1 text-right">Rate</th><th class="py-1"></th></tr>
                        </thead>
                        <tbody class="divide-y">
                            @forelse ($type->brackets as $bracket)
                                <tr>
                                    <td class="py-1">{{ number_format($bracket->min_amount, 0) }}</td>
                                    <td class="py-1">{{ $bracket->max_amount !== null ? number_format($bracket->max_amount, 0) : 'and above' }}</td>
                                    <td class="py-1 text-right">{{ $bracket->rate_percent }}%</td>
                                    <td class="py-1 text-right whitespace-nowrap">
                                        <details class="inline-block">
                                            <summary class="cursor-pointer text-xs text-slate-600 underline">Edit</summary>
                                            <form method="POST" action="{{ route('hr.payroll-deductions.brackets.update', $bracket) }}" class="mt-1 flex gap-1">
                                                @csrf @method('PUT')
                                                <input type="number" step="0.01" min="0" name="min_amount" value="{{ $bracket->min_amount }}" required class="w-20 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                                <input type="number" step="0.01" min="0" name="max_amount" value="{{ $bracket->max_amount }}" class="w-20 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                                <input type="number" step="0.01" min="0" max="100" name="rate_percent" value="{{ $bracket->rate_percent }}" required class="w-16 rounded border border-slate-300 px-1 py-0.5 text-xs">
                                                <button class="rounded bg-slate-800 px-2 py-0.5 text-xs text-white">Save</button>
                                            </form>
                                            <form method="POST" action="{{ route('hr.payroll-deductions.brackets.destroy', $bracket) }}" class="mt-1"
                                                  onsubmit="return confirm('Delete this bracket?');">
                                                @csrf @method('DELETE')
                                                <button class="text-xs text-red-700 hover:underline">Delete</button>
                                            </form>
                                        </details>
                                    </td>
                                </tr>
                            @empty
                                <tr><td class="py-1 text-slate-500" colspan="4">No brackets defined yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>

                    <form method="POST" action="{{ route('hr.payroll-deductions.brackets.store', $type) }}" class="mt-3 flex gap-2">
                        @csrf
                        <input type="number" step="0.01" min="0" name="min_amount" placeholder="Min" required class="w-24 rounded border border-slate-300 px-2 py-1 text-sm">
                        <input type="number" step="0.01" min="0" name="max_amount" placeholder="Max (blank = open-ended)" class="w-40 rounded border border-slate-300 px-2 py-1 text-sm">
                        <input type="number" step="0.01" min="0" max="100" name="rate_percent" placeholder="Rate %" required class="w-24 rounded border border-slate-300 px-2 py-1 text-sm">
                        <button class="rounded bg-slate-800 px-3 py-1 text-sm text-white hover:bg-slate-700">Add bracket</button>
                    </form>
                @endif
            </div>
        @endforeach
    </div>

    <div class="col-span-1 hms-side-panel">
        <form method="POST" action="{{ route('hr.payroll-deductions.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Add deduction type</div>
            <input name="name" placeholder="Name (e.g. Social Security)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input name="code" placeholder="Code (e.g. CNPS)" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <select name="calculation_method" onchange="document.getElementById('flatRateField').hidden = this.value !== 'flat_percent'" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <option value="flat_percent">Flat percentage</option>
                <option value="progressive_brackets">Progressive brackets</option>
            </select>
            <input id="flatRateField" type="number" step="0.01" min="0" max="100" name="flat_rate_percent" placeholder="Flat rate %" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Save</button>
            <p class="text-xs text-slate-500">Progressive types start with no brackets — add them below once saved.</p>
        </form>
    </div>
</div>
@endsection
