@extends('layouts.app')
@section('title', 'Income Sources')
@section('content')

{{--
    Direct request, fast follow-up to the income-source dropdown
    ("the list should not be fixed... the accountant, or finance
    officer [create/update], and [the owner should be able to] update,
    create and delete it"): the managed list behind the "Income source"
    dropdown on the general "record a payment" screen
    (front-office/payments/index.blade.php). Renaming an entry here never
    touches a historical payment — see IncomeSource's own docblock for
    why — it only changes what the dropdown offers going forward.
--}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Income sources</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Name</th>
                        <th class="p-3">Last changed by</th>
                        <th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($incomeSources as $source)
                        <tr>
                            <td class="p-3">
                                <form method="POST" action="{{ route('finance.income-sources.update', $source) }}" class="flex items-center gap-2">
                                    @csrf
                                    @method('PUT')
                                    <input type="text" name="name" value="{{ $source->name }}" required maxlength="120" class="w-full rounded border border-slate-300 px-2 py-1 text-sm" {{ auth()->user()->cannot('manage-income-sources') ? 'disabled' : '' }}>
                                    @can('manage-income-sources')
                                        <button class="shrink-0 rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                                    @endcan
                                </form>
                            </td>
                            <td class="p-3 text-xs text-slate-500">
                                {{ $source->updater->name ?? $source->creator->name ?? '—' }}
                                <div>{{ $source->updated_at->format('d M Y H:i') }}</div>
                            </td>
                            <td class="p-3 text-right">
                                @can('delete-income-sources')
                                    <form method="POST" action="{{ route('finance.income-sources.destroy', $source) }}" class="inline"
                                          onsubmit="return confirm(@js('Delete income source \"'.$source->name.'\" permanently? Payments already recorded under this name keep showing it exactly as before — this only removes it from the dropdown for future payments.'));">
                                        @csrf
                                        @method('DELETE')
                                        <button class="text-xs text-red-700 hover:underline">Delete</button>
                                    </form>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="3">No income sources yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="lg:col-span-1 hms-side-panel">
        @can('manage-income-sources')
            <form method="POST" action="{{ route('finance.income-sources.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
                @csrf
                <div class="text-xs font-medium">Add an income source</div>
                <p class="text-xs text-slate-500">This is what appears in the "Income source" dropdown on the general "record a payment" screen.</p>
                <input type="text" name="name" required maxlength="120" placeholder="e.g. Vending machine income" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add</button>
            </form>
        @endcan
    </div>
</div>
@endsection
