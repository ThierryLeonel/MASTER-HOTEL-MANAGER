@extends('layouts.app')
@section('title', 'Guests')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <form method="GET" class="flex gap-2">
        <input type="text" name="search" value="{{ request('search') }}" placeholder="Search name or phone"
               class="rounded border border-slate-300 px-3 py-1.5 text-sm">
        <button class="rounded bg-slate-200 px-3 py-1.5 text-sm hover:bg-slate-300">Search</button>
    </form>
    <a href="{{ route('guests.create') }}" class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">+ New Guest</a>
</div>

<div class="overflow-hidden rounded-lg bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="bg-slate-100 text-left text-xs uppercase text-slate-500">
            <tr><th class="p-3">Name</th><th class="p-3">Phone</th><th class="p-3">Email</th><th class="p-3"></th></tr>
        </thead>
        <tbody class="divide-y">
            @foreach ($guests as $guest)
                <tr>
                    <td class="p-3">{{ $guest->full_name }}</td>
                    <td class="p-3">{{ $guest->phone }}</td>
                    <td class="p-3">{{ $guest->email }}</td>
                    <td class="p-3"><a href="{{ route('guests.show', $guest) }}" class="text-slate-600 hover:underline">View</a></td>
                </tr>
            @endforeach
        </tbody>
    </table>
</div>
<div class="mt-4">{{ $guests->links() }}</div>
@endsection
