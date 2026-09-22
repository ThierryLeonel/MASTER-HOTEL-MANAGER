@extends('layouts.app')
@section('title', 'Sync Conflicts')
@section('content')
<div class="mb-4 flex items-center justify-between">
    <div>
        <h1 class="text-lg font-semibold">Sync Conflicts</h1>
        <p class="text-xs text-slate-500">A conflict means this record was changed here AND on the other installation since they last synced — the incoming change was held back rather than silently overwriting the local one. Pick how to resolve each; nothing here is guessed automatically.</p>
    </div>
    <a href="{{ route('admin.sync.index') }}" class="text-sm text-slate-600 hover:underline">&larr; Sync Status</a>
</div>

<div class="space-y-4">
    @forelse ($conflicts as $conflict)
        <div class="rounded-lg bg-white p-4 shadow-sm">
            <div class="mb-3 flex items-center justify-between">
                <div>
                    <span class="font-medium">{{ class_basename($conflict->syncable_type) }}</span>
                    <span class="text-xs text-slate-500">ulid {{ $conflict->syncable_ulid }}</span>
                </div>
                <div class="text-xs text-slate-500">
                    Detected {{ $conflict->detected_at->diffForHumans() }}
                    @if ($conflict->item?->batch?->device)
                        from <span class="font-medium">{{ $conflict->item->batch->device->name }}</span>
                    @endif
                </div>
            </div>

            <div class="mb-3 grid grid-cols-1 gap-3 md:grid-cols-2">
                <div class="rounded border border-slate-200 p-2">
                    <div class="mb-1 text-xs font-semibold uppercase text-slate-500">Local version (kept as-is today)</div>
                    <pre class="whitespace-pre-wrap break-all text-xs text-slate-700">{{ json_encode($conflict->local_data, JSON_PRETTY_PRINT) }}</pre>
                </div>
                <div class="rounded border border-slate-200 p-2">
                    <div class="mb-1 text-xs font-semibold uppercase text-slate-500">Incoming version (held back)</div>
                    <pre class="whitespace-pre-wrap break-all text-xs text-slate-700">{{ json_encode($conflict->incoming_data, JSON_PRETTY_PRINT) }}</pre>
                </div>
            </div>

            <form method="POST" action="{{ route('admin.sync.conflicts.resolve', $conflict) }}" class="flex flex-wrap items-end gap-3">
                @csrf
                <div class="w-56">
                    <label class="block text-xs font-medium mb-1">Resolution</label>
                    <select name="resolution" required class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                        <option value="kept_local">Keep local version (no change)</option>
                        <option value="kept_incoming">Apply incoming version now</option>
                        <option value="merged">Already merged by hand</option>
                        <option value="ignored">Ignore — leave as history</option>
                    </select>
                </div>
                <div class="flex-1 min-w-[12rem]">
                    <label class="block text-xs font-medium mb-1">Notes (optional)</label>
                    <input name="notes" maxlength="1000" class="w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                </div>
                <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Resolve</button>
            </form>
        </div>
    @empty
        <div class="rounded-lg bg-white p-4 text-sm text-slate-500 shadow-sm">No unresolved conflicts.</div>
    @endforelse
</div>
@endsection
