@extends('layouts.app')
@section('title', 'New Event Reservation')
@section('content')

{{--
    Direct request ("WE ALSO NEED RESERVATION FOR EVENT: WHAT KIND OF
    EVENT, WHICH SPACES DO THEY WANT TO RESERVE, ROOM, WHICH ROOMS
    (SELECT), MAIN HALL, MINI HALL FOR EXAMPLE, POOL OR THE WHOLE OUTER
    ENVIROMENT (SPACE) ETC"), scoped via AskUserQuestion to the "Full
    package" field set (event type, expected guests, date/time range,
    deposit) "mirroring how room reservations already handle deposits and
    approval". Unlike a room reservation, the contact here is OPTIONAL as
    an existing guest — an event organizer is very often not a lodging
    guest at all, so this form accepts either a searched-and-selected
    guest OR a typed-in contact name (EventReservationController::store()
    requires at least one).
--}}
<form method="POST" action="{{ route('event-reservations.store') }}" class="max-w-2xl space-y-4 rounded-lg bg-white p-6 shadow-sm">
    @csrf
    <div>
        <label class="block text-sm font-medium mb-1">Existing guest (optional)</label>
        <input type="hidden" name="guest_id" id="guest_id" value="{{ old('guest_id') }}">
        <div class="relative">
            <input type="text" id="guest_search" autocomplete="off"
                placeholder="Type a guest's name or phone number to search…"
                class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            <div id="guest_results" class="absolute z-10 mt-1 hidden w-full rounded border border-slate-300 bg-white text-sm shadow-lg"></div>
        </div>
        <p class="mt-1 text-xs text-slate-500" id="guest_hint">Leave blank if the organizer isn't an existing guest — enter their contact details below instead.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Contact name</label>
            <input type="text" name="contact_name" value="{{ old('contact_name') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
            @error('contact_name')<p class="mt-1 text-xs text-red-600">{{ $message }}</p>@enderror
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Contact phone</label>
            <input type="text" name="contact_phone" value="{{ old('contact_phone') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Contact email</label>
            <input type="email" name="contact_email" value="{{ old('contact_email') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Event name</label>
            <input type="text" name="event_name" value="{{ old('event_name') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Event type (wedding, conference, birthday, ...)</label>
            <input type="text" name="event_type" value="{{ old('event_type') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-3 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Expected guests</label>
            <input type="number" min="1" name="expected_guests" value="{{ old('expected_guests') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Starts</label>
            <input type="datetime-local" name="start_at" value="{{ old('start_at') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Ends</label>
            <input type="datetime-local" name="end_at" value="{{ old('end_at') }}" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-2">Spaces (Main Hall, Mini Hall, Pool / outdoor area, ...)</label>
        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
            @forelse ($eventSpaces as $space)
                <label class="flex items-center gap-2 rounded border border-slate-200 px-2 py-1.5 text-sm">
                    <input type="checkbox" name="event_space_ids[]" value="{{ $space->id }}" @checked(collect(old('event_space_ids'))->contains($space->id))>
                    {{ $space->name }}
                </label>
            @empty
                <p class="col-span-full text-xs text-slate-500">No event spaces configured yet. @can('manage-event-spaces')<a href="{{ route('event-spaces.index') }}" class="underline">Add one.</a>@endcan</p>
            @endforelse
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-2">Specific rooms (optional — "WHICH ROOMS (SELECT)")</label>
        <div class="grid grid-cols-2 sm:grid-cols-4 gap-2 max-h-48 overflow-y-auto">
            @forelse ($rooms as $room)
                <label class="flex items-center gap-2 rounded border border-slate-200 px-2 py-1.5 text-sm">
                    <input type="checkbox" name="room_ids[]" value="{{ $room->id }}" @checked(collect(old('room_ids'))->contains($room->id))>
                    {{ $room->room_number }} <span class="text-xs text-slate-400">({{ $room->category->name ?? '—' }})</span>
                </label>
            @empty
                <p class="col-span-full text-xs text-slate-500">No rooms available.</p>
            @endforelse
        </div>
        <p class="mt-1 text-xs text-slate-500">Booking a room here only reserves it for the event's dates — it does not check anyone in. Front desk still runs an ordinary check-in for whoever actually occupies it.</p>
    </div>

    <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
        <div>
            <label class="block text-sm font-medium mb-1">Agreed amount (XAF, optional)</label>
            <input type="number" step="0.01" min="0" name="agreed_amount" value="{{ old('agreed_amount') }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
        <div>
            <label class="block text-sm font-medium mb-1">Deposit required (XAF)</label>
            <input type="number" step="0.01" min="0" name="deposit_required" value="{{ old('deposit_required', 0) }}" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">
        </div>
    </div>

    <div>
        <label class="block text-sm font-medium mb-1">Notes</label>
        <textarea name="notes" rows="2" class="w-full rounded border border-slate-300 px-3 py-2 text-sm">{{ old('notes') }}</textarea>
    </div>

    <p class="text-xs text-slate-500">This event reservation will be created as <strong>pending</strong> and requires manager approval before it is confirmed.</p>
    <button class="rounded bg-slate-800 px-4 py-2 text-sm text-white hover:bg-slate-700">Submit for approval</button>
</form>

<script>
(function () {
    const searchInput = document.getElementById('guest_search');
    const resultsBox = document.getElementById('guest_results');
    const guestIdField = document.getElementById('guest_id');
    const hint = document.getElementById('guest_hint');
    let debounceTimer = null;

    function selectGuest(guest) {
        guestIdField.value = guest.id;
        searchInput.value = guest.full_name;
        resultsBox.classList.add('hidden');
        hint.textContent = 'Selected guest: ' + guest.full_name + '.';
    }

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
})();
</script>
@endsection
