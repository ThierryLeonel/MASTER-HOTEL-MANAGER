<form method="GET" class="flex flex-wrap items-end gap-2 mb-4">
    <div>
        <label class="block text-xs text-slate-500 mb-1">From</label>
        <input type="date" name="start" value="{{ $start->toDateString() }}" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
    </div>
    <div>
        <label class="block text-xs text-slate-500 mb-1">To</label>
        <input type="date" name="end" value="{{ $end->toDateString() }}" class="rounded border border-slate-300 px-2 py-1.5 text-sm">
    </div>
    <button class="rounded bg-slate-800 px-3 py-1.5 text-sm text-white hover:bg-slate-700">Update</button>
</form>
