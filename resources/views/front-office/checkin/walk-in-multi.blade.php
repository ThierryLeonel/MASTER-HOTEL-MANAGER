@extends('layouts.app')
@section('title', 'Check in Multiple Rooms')
@section('content')

<div class="mb-4 text-sm text-slate-600">
    <a href="{{ route('checkin.walk-in') }}" class="text-blue-700 hover:underline">&larr; Checking in just one guest? Use the regular form instead.</a>
</div>

<form method="POST" action="{{ route('checkin.walk-in.multi.store') }}" class="max-w-3xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    <p class="text-sm text-slate-600">One walk-in guest or party taking several rooms right now — add as many rooms below as needed and check them all in together instead of one at a time.</p>

    <input type="hidden" name="guest_id" id="guest_id" value="{{ old('guest_id', $prefillGuest->id ?? '') }}">

    <div>
        <label class="block text-sm font-medium mb-1">Guest</label>
        <div class="relative">
            <input type="text" id="guest_search" autocomplete="off"
                value="{{ old('new_first_name') ? old('new_first_name').' '.old('new_last_name') : ($prefillGuest->full_name ?? '') }}"
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
            <input type="text" name="new_first_name" id="new_first_name" value="{{ old('new_first_name') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Last name</label>
            <input type="text" name="new_last_name" id="new_last_name" value="{{ old('new_last_name') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Phone</label>
            <input type="text" name="new_phone" id="new_phone" value="{{ old('new_phone') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Email</label>
            <input type="email" name="new_email" id="new_email" value="{{ old('new_email') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Expected check-out</label>
        <input type="date" name="expected_check_out_date" value="{{ old('expected_check_out_date') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        <p class="mt-1 text-xs text-slate-500">Every room checked in here shares this same check-out date — a room needed for different dates is a separate check-in, not part of this party.</p>
    </div>

    <div>
        <div class="mb-2 flex items-center justify-between">
            <label class="block text-sm font-medium">Rooms</label>
            <button type="button" id="add-room-row" class="rounded bg-slate-100 px-2 py-1 text-xs text-slate-700 hover:bg-slate-200">+ Add another room</button>
        </div>
        <div id="room-rows" class="space-y-3">
            {{-- The first row is always present; add-room-row clones it (minus any old() values) for each extra room. --}}
            <div class="room-row rounded border border-slate-200 p-3 space-y-2">
                <div class="mb-1 flex items-center justify-between">
                    <span class="text-xs font-medium uppercase tracking-wide text-slate-500">Room 1</span>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="block text-xs font-medium mb-1">Room</label>
                        <select name="rooms[0][room_id]" required class="room-select w-full rounded border border-slate-300 px-3 py-2 text-sm">
                            <option value="">Select available room…</option>
                            @foreach ($availableRooms as $room)
                                <option value="{{ $room->id }}" data-rate="{{ $room->category->base_rate }}">{{ $room->room_number }} — {{ $room->category->name }} ({{ number_format($room->category->base_rate, 0) }} XAF)</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Agreed rate (per night, XAF)</label>
                        <input type="number" step="0.01" name="rooms[0][agreed_rate]" class="room-rate w-full rounded border border-slate-300 px-3 py-2 text-sm" required>
                    </div>
                </div>

                {{-- Per-room discount-approval routing — same rule and
                     wording as the single walk-in form, just scoped to
                     this one room, since each room here can have its own
                     agreed rate. --}}
                <div class="discount-approval-block hidden rounded border border-amber-300 bg-amber-50 p-3 space-y-2">
                    <p class="text-xs font-medium text-amber-800">This rate is below the room's standard rate — an eligible approver must sign off. The guest can still be checked in now; select who approved it and they'll confirm it afterward from their own login.</p>
                    <div>
                        <label class="block text-xs font-medium mb-1">Discount approved by</label>
                        <select name="rooms[0][discount_approver_id]" class="discount-approver w-full rounded border border-slate-300 px-2 py-1.5 text-sm">
                            <option value="">Select an eligible approver…</option>
                            @foreach ($eligibleApprovers as $approverOption)
                                <option value="{{ $approverOption->id }}">{{ $approverOption->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div>
                        <label class="block text-xs font-medium mb-1">Reason for this rate</label>
                        <input type="text" name="rooms[0][discount_reason]" class="discount-reason w-full rounded border border-slate-300 px-2 py-1.5 text-sm" placeholder="e.g. repeat guest, long stay, walk-in negotiation">
                    </div>
                </div>

                <button type="button" class="remove-room-row hidden text-xs text-red-600 hover:underline">Remove this room</button>
            </div>
        </div>
    </div>

    {{-- Post-9.8 third fix round: optional — a stay billed to a company
         rolls its debt up under that company. Shared across the whole
         party, same as the guest and dates above. --}}
    <div>
        <label class="block text-sm font-medium mb-1">Bill to company (optional)</label>
        <select name="company_id" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <option value="">— None, bill the guest directly —</option>
            @foreach ($companies as $company)
                <option value="{{ $company->id }}">{{ $company->name }}</option>
            @endforeach
        </select>
    </div>

    <button class="btn-action rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Check in all rooms</button>
</form>

<script>
(function () {
    // Guest search — identical pattern to checkin/walk-in.blade.php.
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

    // Repeatable room rows — same "clone a template row" pattern as
    // reservations/create-multi.blade.php, plus a per-row discount-block
    // toggle mirroring the single walk-in form's own script.
    const roomRows = document.getElementById('room-rows');
    const addButton = document.getElementById('add-room-row');

    function wireRow(row) {
        const roomSelect = row.querySelector('.room-select');
        const rateInput = row.querySelector('.room-rate');
        const discountBlock = row.querySelector('.discount-approval-block');

        function refreshDiscountBlock() {
            const selectedOption = roomSelect.options[roomSelect.selectedIndex];
            const standardRate = selectedOption ? parseFloat(selectedOption.getAttribute('data-rate')) : NaN;
            const agreedRate = parseFloat(rateInput.value);
            const isDiscount = !isNaN(standardRate) && !isNaN(agreedRate) && agreedRate < standardRate;
            discountBlock.classList.toggle('hidden', !isDiscount);
        }

        roomSelect.addEventListener('change', refreshDiscountBlock);
        rateInput.addEventListener('input', refreshDiscountBlock);

        const removeBtn = row.querySelector('.remove-room-row');
        removeBtn.addEventListener('click', function () {
            row.remove();
            renumberRows();
        });
    }

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
        // Reset the clone's values — cloneNode copies attributes but a
        // select/input's current VALUE is separate DOM state that
        // cloneNode also copies; explicitly reset it so a new row doesn't
        // silently inherit whatever the first row currently has entered.
        clone.querySelectorAll('input').forEach(function (input) { input.value = ''; });
        clone.querySelectorAll('select').forEach(function (select) { select.selectedIndex = 0; });
        clone.querySelector('.discount-approval-block').classList.add('hidden');
        roomRows.appendChild(clone);
        wireRow(clone);
        renumberRows();
    });

    roomRows.querySelectorAll('.room-row').forEach(wireRow);
})();
</script>
@endsection
