@extends('layouts.app')
@section('title', 'Book Multiple Rooms')
@section('content')

<div class="mb-4 text-sm text-slate-600">
    <a href="{{ route('reservations.create') }}" class="text-blue-700 hover:underline">&larr; Booking just one room? Use the regular form instead.</a>
</div>

<form method="POST" action="{{ route('reservations.store-multi') }}" class="max-w-3xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    <p class="text-sm text-slate-600">One guest or party taking several rooms for the same stay — add as many rooms below as needed and submit them together instead of one at a time.</p>

    <div>
        <label class="block text-sm font-medium mb-1">Guest (the party's main contact)</label>
        <input type="hidden" name="guest_id" id="guest_id" value="{{ old('guest_id', $prefillGuest->id ?? '') }}">
        <div class="relative">
            <input type="text" id="guest_search" autocomplete="off"
                value="{{ $prefillGuest->full_name ?? '' }}"
                placeholder="Type a guest's name or phone number to search…"
                required
                class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <div id="guest_results" class="absolute z-10 mt-1 hidden w-full rounded border border-slate-300 bg-white text-sm shadow-lg"></div>
        </div>
        <p class="mt-1 text-xs text-slate-500" id="guest_hint">
            @if ($prefillGuest ?? null)
                Selected guest. <a href="#" id="guest_clear" class="underline">Not {{ $prefillGuest->full_name }}? Change.</a>
            @else
                Start typing to search existing guests. Not found? Add them from Guests first, then come back here.
            @endif
        </p>
    </div>

    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Check-in date</label>
            <input type="date" name="check_in_date" value="{{ old('check_in_date') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Check-out date</label>
            <input type="date" name="check_out_date" value="{{ old('check_out_date') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>
    <p class="text-xs text-slate-500">Every room booked here shares these same dates. A room needed for different dates is a separate booking — use the regular "New Reservation" form for that one instead.</p>

    <div>
        <div class="mb-2 flex items-center justify-between">
            <label class="block text-sm font-medium">Rooms</label>
            <button type="button" id="add-room-row" class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700 hover:bg-slate-200">+ Add another room</button>
        </div>
        <div id="room-rows" class="space-y-3">
            {{-- The first row is always present; add-room-row clones it (minus any old() values) for each extra room. --}}
            <div class="room-row rounded border border-slate-200 p-3">
                <div class="mb-2 flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Room 1</span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Room category</label>
                        <select name="rooms[0][room_category_id]" required class="room-category w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            @foreach ($categories as $category)
                                <option value="{{ $category->id }}" data-rate="{{ $category->base_rate }}">
                                    {{ $category->name }} — {{ number_format($category->base_rate, 0) }} XAF/night
                                </option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Rate quoted (per night, XAF)</label>
                        <input type="number" step="0.01" name="rooms[0][rate_quoted]" class="room-rate w-full rounded border border-slate-300 px-3 py-2 text-sm" required>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Adults</label>
                        <input type="number" name="rooms[0][adults]" min="1" value="1" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Children</label>
                        <input type="number" name="rooms[0][children]" min="0" value="0" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Deposit required (XAF)</label>
                        <input type="number" step="0.01" name="rooms[0][deposit_required]" value="0" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    </div>
                </div>
                <button type="button" class="remove-room-row mt-2 hidden text-xs text-red-600 hover:underline">Remove this room</button>
            </div>
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Notes (shared by every room in this booking)</label>
        <textarea name="notes" rows="2" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">{{ old('notes') }}</textarea>
    </div>

    <p class="text-xs text-slate-500">Every room here will be created as <strong>pending</strong> and requires manager approval before it is confirmed — same as a single reservation, just done together in one submission.</p>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Submit all rooms for approval</button>
</form>

<script>
(function () {
    // Guest search — identical pattern to reservations/create.blade.php.
    const searchInput = document.getElementById('guest_search');
    const resultsBox = document.getElementById('guest_results');
    const guestIdField = document.getElementById('guest_id');
    const hint = document.getElementById('guest_hint');
    let debounceTimer = null;

    function selectGuest(guest) {
        guestIdField.value = guest.id;
        searchInput.value = guest.full_name;
        resultsBox.classList.add('hidden');
        hint.innerHTML = 'Selected guest. <a href="#" id="guest_clear" class="underline">Not ' + guest.full_name + '? Change.</a>';
        document.getElementById('guest_clear').addEventListener('click', clearGuest);
    }

    function clearGuest(e) {
        if (e) e.preventDefault();
        guestIdField.value = '';
        searchInput.value = '';
        hint.textContent = 'Start typing to search existing guests. Not found? Add them from Guests first, then come back here.';
        searchInput.focus();
    }

    const clearLink = document.getElementById('guest_clear');
    if (clearLink) clearLink.addEventListener('click', clearGuest);

    searchInput.addEventListener('input', function () {
        guestIdField.value = '';
        const q = searchInput.value.trim();

        clearTimeout(debounceTimer);
        if (q.length < 2) {
            resultsBox.classList.add('hidden');
            return;
        }
        debounceTimer = setTimeout(function () {
            fetch('{{ route('guests.search') }}?q=' + encodeURIComponent(q))
                .then(r => r.json())
                .then(function (guests) {
                    resultsBox.innerHTML = '';
                    if (!guests.length) {
                        const row = document.createElement('div');
                        row.className = 'px-3 py-2 text-slate-400';
                        row.textContent = 'No matching guest found.';
                        resultsBox.appendChild(row);
                        resultsBox.classList.remove('hidden');
                        return;
                    }
                    guests.forEach(function (guest) {
                        const row = document.createElement('div');
                        row.className = 'btn-action cursor-pointer px-3 py-2 hover:bg-slate-100';
                        row.textContent = guest.full_name + (guest.phone ? ' — ' + guest.phone : '');
                        row.addEventListener('click', function () { selectGuest(guest); });
                        resultsBox.appendChild(row);
                    });
                    resultsBox.classList.remove('hidden');
                })
                .catch(function () { resultsBox.classList.add('hidden'); });
        }, 300);
    });

    document.addEventListener('click', function (e) {
        if (!resultsBox.contains(e.target) && e.target !== searchInput) {
            resultsBox.classList.add('hidden');
        }
    });

    // Repeatable room rows — plain JS, no build step (same "clone a
    // template row" pattern this app already uses elsewhere, e.g. the
    // discount fields on the general payment screen, just applied to a
    // whole row instead of a couple of inputs).
    const roomRows = document.getElementById('room-rows');
    const addButton = document.getElementById('add-room-row');

    function renumberRows() {
        const rows = roomRows.querySelectorAll('.room-row');
        rows.forEach(function (row, index) {
            row.querySelector('span').textContent = 'Room ' + (index + 1);
            row.querySelectorAll('[name^="rooms["]').forEach(function (field) {
                field.name = field.name.replace(/rooms\[\d+\]/, 'rooms[' + index + ']');
            });
            const removeBtn = row.querySelector('.remove-room-row');
            removeBtn.classList.toggle('hidden', rows.length <= 1);
        });
    }

    addButton.addEventListener('click', function () {
        const firstRow = roomRows.querySelector('.room-row');
        const clone = firstRow.cloneNode(true);
        // Reset the clone's values — cloneNode copies attributes (so the
        // rate's `required` etc. stay intact) but a select/input's
        // current VALUE is separate DOM state that cloneNode also
        // copies; explicitly reset it so a new row doesn't silently
        // inherit whatever the first row currently has typed in.
        clone.querySelectorAll('input').forEach(function (input) {
            if (input.type === 'number' && input.name.includes('adults')) { input.value = '1'; return; }
            if (input.type === 'number' && input.name.includes('children')) { input.value = '0'; return; }
            if (input.type === 'number' && input.name.includes('deposit_required')) { input.value = '0'; return; }
            input.value = '';
        });
        clone.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
        roomRows.appendChild(clone);
        clone.querySelector('.remove-room-row').addEventListener('click', function () {
            clone.remove();
            renumberRows();
        });
        renumberRows();
    });
})();
</script>
@endsection
