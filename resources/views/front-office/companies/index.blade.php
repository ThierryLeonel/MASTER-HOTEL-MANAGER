@extends('layouts.app')
@section('title', 'Companies')
@section('content')

<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
    <div class="lg:col-span-2 space-y-6">
        <form method="GET" class="flex gap-2">
            <input type="text" name="search" value="{{ request('search') }}" placeholder="Search companies…" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Search</button>
        </form>

        <div class="rounded-lg bg-white shadow-sm">
            <div class="border-b p-3 text-xs font-medium uppercase tracking-wide text-slate-500">Companies / corporate clients</div>
            <table class="w-full text-sm">
                <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                    <tr>
                        <th class="p-3">Name</th><th class="p-3">Contact</th><th class="p-3">Phone</th><th class="p-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    @forelse ($companies as $company)
                        <tr class="{{ $company->is_active ? '' : 'opacity-50' }}">
                            <td class="p-3 font-medium">{{ $company->name }}@unless ($company->is_active)<span class="ml-1 rounded bg-slate-200 px-1.5 py-0.5 text-[10px]">Inactive</span>@endunless</td>
                            <td class="p-3">{{ $company->contact_name ?? '—' }}</td>
                            <td class="p-3">{{ $company->phone ?? '—' }}</td>
                            <td class="p-3 space-x-2 whitespace-nowrap">
                                @can('manage-accounts-receivable')
                                    <a href="{{ route('finance.accounts-receivable.index') }}?company={{ $company->id }}" class="text-xs text-blue-700 hover:underline">View debt</a>
                                @endcan
                                @can('manage-guests')
                                    <details class="inline-block">
                                        <summary class="cursor-pointer text-xs text-slate-600 underline">Edit</summary>
                                        <form method="POST" action="{{ route('companies.update', $company) }}" class="mt-2 w-56 space-y-1">
                                            @csrf @method('PUT')
                                            <input name="name" value="{{ $company->name }}" required class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                            <input name="contact_name" value="{{ $company->contact_name }}" placeholder="Contact" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                            <input name="phone" value="{{ $company->phone }}" placeholder="Phone" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                            <input name="email" value="{{ $company->email }}" placeholder="Email" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                            <input type="number" step="0.01" min="0" name="credit_limit" value="{{ $company->credit_limit }}" placeholder="Credit limit" class="w-full rounded border border-slate-300 px-1.5 py-1 text-xs">
                                            <label class="flex items-center gap-1 text-xs"><input type="checkbox" name="is_active" value="1" @checked($company->is_active)> Active</label>
                                            <button class="w-full rounded bg-slate-800 px-2 py-1 text-xs text-white hover:bg-slate-700">Save</button>
                                        </form>
                                        <form method="POST" action="{{ route('companies.destroy', $company) }}" class="mt-1"
                                              onsubmit="return confirm(@js('Delete company '.$company->name.' permanently? Only a company with no stay or debt history can be deleted — otherwise deactivate it instead.'));">
                                            @csrf @method('DELETE')
                                            <button class="text-xs text-red-700 hover:underline">Delete</button>
                                        </form>
                                    </details>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td class="p-3 text-slate-500" colspan="4">No companies added yet.</td></tr>
                    @endforelse
                </tbody>
            </table>
            {{ $companies->links() }}
        </div>
    </div>

    <div class="lg:col-span-1">
        <form method="POST" action="{{ route('companies.store') }}" class="rounded-lg bg-white p-4 shadow-sm space-y-2">
            @csrf
            <div class="text-xs font-medium">Add a company</div>
            <input type="text" name="name" required placeholder="Company name" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input type="text" name="contact_name" placeholder="Contact person (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input type="text" name="phone" placeholder="Phone (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input type="email" name="email" placeholder="Email (optional)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <input type="number" step="0.01" min="0" name="credit_limit" placeholder="Credit limit (optional, informational only)" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
            <button class="w-full rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Add company</button>
        </form>
    </div>
</div>
@endsection
