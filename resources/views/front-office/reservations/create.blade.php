@extends('layouts.app')
@section('title', 'New Reservation')
@section('content')

{{--
    Direct request ("IN THE RESERVATION WE ARE STILL NOT ABLE TO CHOSE IF
    IT IS A MULTIPLE ROOM RESERVATION OR SINGLE ROOM. IF IT IS MULTIPLE ONE
    SHOULD BE ABLE TO SELECT THE ROOMS"), scoped via AskUserQuestion to
    "One page with a toggle" — this single page now covers what used to be
    two separate pages (reservations/create.blade.php and
    reservations/create-multi.blade.php, both still rendered by this same
    view via ReservationController::create()/createMulti() so old links/
    bookmarks to either route keep working). The toggle switches which of
    the two independent <form> blocks below is visible; only the visible
    one is ever actually submitted (a hidden <form> can't be focused or
    submitted), so nothing about ReservationController::store()/
    storeMulti() or ReservationService::create()/createGroup() needed to
    change — this is purely a front-end reorganization of the same two
    existing flows.

    AskUserQuestion also confirmed room-picking stays exactly as it is
    today ("No, keep category-based booking") — a reservation (single or
    multi) still books a ROOM CATEGORY, with the actual room assigned later
    at approval/check-in. "WHICH ROOMS (SELECT)" from the same request is
    answered by the separate Event Reservations feature instead, where
    specific rooms genuinely are picked at booking time (see
    front-office/event-reservations/create.blade.php) — a deliberately
    different booking model for a deliberately different kind of booking.
--}}
<div class="max-w-3xl space-y-4">
    <div class="inline-flex rounded-lg border border-slate-300 bg-white p-1 text-sm">
        <button type="button" id="mode-single" class="rounded px-3 py-1.5 font-medium">Single room</button>
        <button type="button" id="mode-multi" class="rounded px-3 py-1.5 font-medium">Multiple rooms</button>
    </div>

    <div id="single-room-panel" class="rounded-lg bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('reservations.store') }}" class="space-y-4">
            @csrf
            <div>
                <label class="block text-sm font-medium mb-1">Guest</label>
                <input type="hidden" name="guest_id" id="guest_id_single" value="{{ old('guest_id', $prefillGuest->id ?? '') }}">
                <div class="relative">
                    <input type="text" id="guest_search_single" autocomplete="off"
                        value="{{ $prefillGuest->full_name ?? '' }}"
                        placeholder="Type a guest's name or phone number to search…"
                        required
                        class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <div id="guest_results_single" class="absolute z-10 mt-1 hidden w-full rounded border border-slate-300 bg-white text-sm shadow-lg"></div>
                </div>
                <p class="mt-1 text-xs text-slate-500" id="guest_hint_single">
                    @if ($prefillGuest ?? null)
                        Selected guest. <a href="#" id="guest_clear_single" class="underline">Not {{ $prefillGuest->full_name }}? Change.</a>
                    @else
                        Start typing to search existing guests. Not found? Add them from Guests first, then come back here.
                    @endif
                </p>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Room category</label>
                <select name="room_category_id" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    @foreach ($categories as $category)
                        <option value="{{ $category->id }}" data-rate="{{ $category->base_rate }}" @selected(old('room_category_id') == $category->id)>
                            {{ $category->name }} — {{ number_format($category->base_rate, 0) }} XAF/night
                        </option>
                    @endforeach
                </select>
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
            <div class="grid grid-cols-2 gap-4">
                <div>
                    <label class="block text-sm font-medium mb-1">Adults</label>
                    <input type="number" name="adults" min="1" value="{{ old('adults', 1) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-sm font-medium mb-1">Children</label>
                    <input type="number" name="children" min="0" value="{{ old('children', 0) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Rate quoted (per night, XAF)</label>
                <input type="number" step="0.01" name="rate_quoted" value="{{ old('rate_quoted') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Deposit required (XAF)</label>
                <input type="number" step="0.01" name="deposit_required" value="{{ old('deposit_required', 0) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="block text-sm font-medium mb-1">Notes</label>
                <textarea name="notes" rows="2" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">{{ old('notes') }}</textarea>
            </div>
            <p class="text-xs text-slate-500">This reservation will be created as <strong>pending</strong> and requires manager approval before it is confirmed.</p>
            <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Submit for approval</button>
        </form>
    </div>

    <div id="multi-room-panel" class="rounded-lg bg-white p-6 shadow-sm">
        <form method="POST" action="{{ route('reservations.store-multi') }}" class="space-y-4">
            @csrf
            <p class="text-sm text-slate-600">One guest or party taking several rooms for the same stay — add as many rooms below as needed and submit them together instead of one at a time.</p>

            <div>
                <label class="block text-sm font-medium mb-1">Guest (the party's main contact)</label>
                <input type="hidden" name="guest_id" id="guest_id_multi" value="{{ old('guest_id', $prefillGuest->id ?? '') }}">
                <div class="relative">
                    <input type="text" id="guest_search_multi" autocomplete="off"
                        value="{{ $prefillGuest->full_name ?? '' }}"
                        placeholder="Type a guest's name or phone number to search…"
                        required
                        class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
                    <div id="guest_results_multi" class="absolute z-10 mt-1 hidden w-full rounded border border-slate-300 bg-white text-sm shadow-lg"></div>
                </div>
                <p class="mt-1 text-xs text-slate-500" id="guest_hint_multi">
                    @if ($prefillGuest ?? null)
                        Selected guest. <a href="#" id="guest_clear_multi" class="underline">Not {{ $prefillGuest->full_name }}? Change.</a>
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
            <p class="text-xs text-slate-500">Every room booked here shares these same dates. A room needed for different dates is a separate booking — switch back to "Single room" for that one instead.</p>

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
    </div>
</div>

<script>
(function () {
    // Direct request: a toggle, not two separate pages — see this view's
    // own docblock. Only one panel is ever visible, and since each panel
    // is its own independent <form>, only the visible one's fields are
    // ever submitted; the hidden one can't be focused or submitted.
    const modeSingleBtn = document.getElementById('mode-single');
    const modeMultiBtn = document.getElementById('mode-multi');
    const singlePanel = document.getElementById('single-room-panel');
    const multiPanel = document.getElementById('multi-room-panel');

    function setMode(mode) {
        const isMulti = mode === 'multi';
        singlePanel.style.display = isMulti ? 'none' : '';
        multiPanel.style.display = isMulti ? '' : 'none';
        modeSingleBtn.classList.toggle('bg-slate-800', !isMulti);
        modeSingleBtn.classList.toggle('text-white', !isMulti);
        modeSingleBtn.classList.toggle('text-slate-700', isMulti);
        modeMultiBtn.classList.toggle('bg-slate-800', isMulti);
        modeMultiBtn.classList.toggle('text-white', isMulti);
        modeMultiBtn.classList.toggle('text-slate-700', !isMulti);
        try { sessionStorage.setItem('reservations_create_mode', mode); } catch (e) {}
    }

    modeSingleBtn.addEventListener('click', function () { setMode('single'); });
    modeMultiBtn.addEventListener('click', function () { setMode('multi'); });

    // Defaults to whichever mode the old route brought us in on
    // (reservations.create-multi still lands here with mode=multi — see
    // ReservationController::createMulti()), falling back to whatever the
    // user last had open, then to single.
    let initialMode = @json($defaultMode ?? 'single');
    if (initialMode !== 'multi') {
        try {
            const remembered = sessionStorage.getItem('reservations_create_mode');
            if (remembered === 'multi' || remembered === 'single') initialMode = remembered;
        } catch (e) {}
    }
    setMode(initialMode);

    function wireGuestSearch(suffix) {
        const searchInput = document.getElementById('guest_search_' + suffix);
        const resultsBox = document.getElementById('guest_results_' + suffix);
        const guestIdField = document.getElementById('guest_id_' + suffix);
        const hint = document.getElementById('guest_hint_' + suffix);
        let debounceTimer = null;

        function selectGuest(guest) {
            guestIdField.value = guest.id;
            searchInput.value = guest.full_name;
            resultsBox.classList.add('hidden');
            hint.innerHTML = 'Selected guest. <a href="#" class="guest-clear-link underline">Not ' + guest.full_name + '? Change.</a>';
            hint.querySelector('.guest-clear-link').addEventListener('click', clearGuest);
        }

        function clearGuest(e) {
            if (e) e.preventDefault();
            guestIdField.value = '';
            searchInput.value = '';
            hint.textContent = 'Start typing to search existing guests. Not found? Add them from Guests first, then come back here.';
            searchInput.focus();
        }

        const clearLink = hint.querySelector('.guest-clear-link') || document.getElementById('guest_clear_' + suffix);
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
                            row.className = 'cursor-pointer px-3 py-2 hover:bg-slate-100';
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
    }

    wireGuestSearch('single');
    wireGuestSearch('multi');

    // Repeatable room rows (multi panel only) — plain JS, no build step.
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
