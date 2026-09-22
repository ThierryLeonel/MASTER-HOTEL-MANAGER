@extends('layouts.app')
@section('title', 'Walk-in Check-in')
@section('content')
<form method="POST" action="{{ route('checkin.walk-in.store') }}" class="max-w-xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    <input type="hidden" name="guest_id" id="guest_id" value="{{ $prefillGuest->id ?? '' }}">

    <div>
        <label class="block text-sm font-medium mb-1">Guest</label>
        <div class="relative">
            <input type="text" id="guest_search" autocomplete="off"
                value="{{ $prefillGuest?->full_name }}"
                placeholder="Type a guest's name or phone number to search…"
                class="w-full rounded border border-slate-300 px-3 py-2 text-sm"
                {{ $prefillGuest ? 'readonly' : '' }}>
            <div id="guest_results" class="absolute z-10 mt-1 hidden w-full rounded border border-slate-300 bg-white text-sm shadow-lg"></div>
        </div>
        <p class="mt-1 text-xs text-slate-500" id="guest_hint">
            @if ($prefillGuest)
                Selected guest. <a href="#" id="guest_clear" class="underline">Not {{ $prefillGuest->full_name }}? Change.</a>
            @else
                Not found? Just keep typing below — a new guest is created automatically when you check in.
            @endif
        </p>
    </div>

    <div id="new_guest_fields" class="grid grid-cols-2 gap-4 {{ $prefillGuest ? 'hidden' : '' }}">
        <div>
            <label class="block text-sm font-medium mb-1">First name</label>
            <input type="text" name="new_first_name" id="new_first_name" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Last name</label>
            <input type="text" name="new_last_name" id="new_last_name" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Phone</label>
            <input type="text" name="new_phone" id="new_phone" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Email</label>
            <input type="email" name="new_email" id="new_email" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Room</label>
        <select name="room_id" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="">Select available room…</option>
            @foreach ($availableRooms as $room)
                <option value="{{ $room->id }}" data-rate="{{ $room->category->base_rate }}">{{ $room->room_number }} — {{ $room->category->name }} ({{ number_format($room->category->base_rate, 0) }} XAF)</option>
            @endforeach
        </select>
    </div>
    <div class="grid grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Expected check-out</label>
            <input type="date" name="expected_check_out_date" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Agreed rate (per night, XAF)</label>
            <input type="number" step="0.01" name="agreed_rate" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>
    {{-- Post-9.8 third fix round (PHASE9_AUDIT_AND_PLAN.md §22): optional —
         a stay billed to a company rolls its debt up under that company. --}}
    <div>
        <label class="block text-sm font-medium mb-1">Bill to company (optional)</label>
        <select name="company_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="">— None, bill the guest directly —</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </select>
    </div>
    <button class="btn-action rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Check in</button>
</form>

<script>
(function () {
    const searchInput = document.getElementById('guest_search');
    const resultsBox = document.getElementById('guest_results');
    const guestIdField = document.getElementById('guest_id');
    const newFields = document.getElementById('new_guest_fields');
    const hint = document.getElementById('guest_hint');
    const clearLink = document.getElementById('guest_clear');
    let debounceTimer = null;

    function selectGuest(guest) {
        guestIdField.value = guest.id;
        searchInput.value = guest.full_name;
        searchInput.readOnly = true;
        resultsBox.classList.add('hidden');
        newFields.classList.add('hidden');
        hint.innerHTML = 'Selected guest. <a href="#" id="guest_clear" class="underline">Not ' + guest.full_name + '? Change.</a>';
        document.getElementById('guest_clear').addEventListener('click', clearGuest);
    }

    function clearGuest(e) {
        if (e) e.preventDefault();
        guestIdField.value = '';
        searchInput.value = '';
        searchInput.readOnly = false;
        newFields.classList.remove('hidden');
        hint.textContent = 'Not found? Just keep typing below — a new guest is created automatically when you check in.';
        searchInput.focus();
    }

    if (clearLink) clearLink.addEventListener('click', clearGuest);

    searchInput.addEventListener('input', function () {
        guestIdField.value = '';
        const q = searchInput.value.trim();

        // Mirror the typed text into the new-guest first name field so
        // check-in can still create the guest even if the person never
        // opens a match from the dropdown.
        const parts = q.split(' ');
        document.getElementById('new_first_name').value = parts[0] || '';
        document.getElementById('new_last_name').value = parts.slice(1).join(' ');
        newFields.classList.remove('hidden');

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
                        resultsBox.classList.add('hidden');
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
