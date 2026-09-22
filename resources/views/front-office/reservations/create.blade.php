@extends('layouts.app')
@section('title', 'New Reservation')
@section('content')
<form method="POST" action="{{ route('reservations.store') }}" class="max-w-xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    <div>
        <label class="block text-sm font-medium mb-1">Guest</label>
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

<script>
(function () {
    // Fourth post-9.8 fix round, follow-up: the guest field used to be a
    // <select> capped at the first 200 guests alphabetically (see the old
    // ReservationController::create() docblock) — unusable once a hotel
    // has more guests than that, and awkward even below it. Replaced with
    // the same search-as-you-type pattern already established in
    // checkin/walk-in.blade.php, minus that page's inline "create a new
    // guest" fields — a reservation always needs an EXISTING guest_id
    // (ReservationController::store() validates guest_id as
    // required|exists:guests,id, with no path to create one inline), so
    // this is a pure search-and-select, not a search-or-create.
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
})();
</script>
@endsection
