@extends('layouts.app')
@section('title', 'Guest Transaction History')
@section('content')
<form method="GET" action="{{ route('reports.guest-transaction-history.index') }}" class="mb-4">
    <input type="text" name="q" value="{{ $query }}" placeholder="Search guest by name, phone, or email…" class="w-full max-w-md rounded border border-slate-300 px-3 py-1.5 text-sm">
    <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Search</button>
</form>

@if ($query)
    <div class="overflow-hidden rounded-lg bg-white shadow-sm">
        <table class="w-full text-sm">
            <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
                <tr><th class="p-3">Guest</th><th class="p-3">Phone</th><th class="p-3">Email</th><th class="p-3"></th></tr>
            </thead>
            <tbody class="divide-y">
                @forelse ($guests as $guest)
                    <tr>
                        <td class="p-3">{{ $guest->full_name }}</td>
                        <td class="p-3">{{ $guest->phone }}</td>
                        <td class="p-3">{{ $guest->email }}</td>
                        <td class="p-3"><a href="{{ route('reports.guest-transaction-history.show', $guest) }}" class="text-slate-600 hover:underline">View history</a></td>
                    </tr>
                @empty
                    <tr><td class="p-3 text-slate-500" colspan="4">No guests matched "{{ $query }}".</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
@else
    <p class="text-sm text-slate-500">Search for a guest by name, phone, or email to see their complete transaction history across every stay.</p>
@endif
@endsection
