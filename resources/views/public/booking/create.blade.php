@extends('layouts.public')
@section('title', 'Book a room')
@section('subtitle', 'Request a reservation — our front desk will confirm it')

@section('content')
<form method="POST" action="{{ route('public.booking.store') }}" enctype="multipart/form-data" class="space-y-6">
    @csrf

    {{-- Honeypot: hidden from a real visitor by layout position and the
         sr-only class, but a naive form-filling bot typically fills every
         input it finds. Left unstyled-hidden (not display:none) since some
         bots skip fields hidden that way specifically to evade this trick. --}}
    <div class="absolute -left-[9999px]" aria-hidden="true">
        <label for="company_website">Leave this field blank</label>
        <input type="text" id="company_website" name="company_website" tabindex="-1" autocomplete="off">
    </div>

    <div class="rounded-lg border border-slate-200 bg-white shadow-sm p-5 space-y-4">
        <h2 class="text-sm font-medium uppercase tracking-wide text-slate-500">Your details</h2>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">First name *</label>
                <input type="text" name="first_name" value="{{ old('first_name') }}" required
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Last name</label>
                <input type="text" name="last_name" value="{{ old('last_name') }}"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Phone number *</label>
                <input type="text" name="phone" value="{{ old('phone') }}" required
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Email (optional)</label>
                <input type="email" name="email" value="{{ old('email') }}"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
        </div>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white shadow-sm p-5 space-y-4">
        <h2 class="text-sm font-medium uppercase tracking-wide text-slate-500">Your stay</h2>

        <div>
            <label class="block text-sm text-slate-600 mb-1">Room type *</label>
            <select name="room_category_id" required class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                <option value="">Select a room type</option>
                @foreach ($categories as $category)
                    <option value="{{ $category->id }}" {{ old('room_category_id') == $category->id ? 'selected' : '' }}>
                        {{ $category->name }} — {{ number_format($category->base_rate, 0) }}/night
                    </option>
                @endforeach
            </select>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Check-in date *</label>
                <input type="date" name="check_in_date" value="{{ old('check_in_date') }}" required
                       min="{{ now()->toDateString() }}"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Check-out date *</label>
                <input type="date" name="check_out_date" value="{{ old('check_out_date') }}" required
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Adults</label>
                <input type="number" name="adults" value="{{ old('adults', 1) }}" min="1" max="20"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">Children</label>
                <input type="number" name="children" value="{{ old('children', 0) }}" min="0" max="20"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
        </div>

        <div>
            <label class="block text-sm text-slate-600 mb-1">Special requests (optional)</label>
            <textarea name="notes" rows="3" class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">{{ old('notes') }}</textarea>
        </div>
    </div>

    <div class="rounded-lg border border-slate-200 bg-white shadow-sm p-5 space-y-4">
        <h2 class="text-sm font-medium uppercase tracking-wide text-slate-500">Already sent a deposit?</h2>
        <p class="text-sm text-slate-500">
            This is optional. If you've already sent a deposit (mobile money, bank transfer, etc.), tell us how much
            and how you sent it, and attach your payment screenshot or receipt — we'll confirm it and credit it to
            your reservation as soon as we approve your request. Otherwise our front desk will contact you about
            payment.
        </p>

        <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
            <div>
                <label class="block text-sm text-slate-600 mb-1">Amount sent</label>
                <input type="number" step="0.01" name="deposit_claimed_amount" value="{{ old('deposit_claimed_amount') }}"
                       class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
            </div>
            <div>
                <label class="block text-sm text-slate-600 mb-1">How did you send it?</label>
                <select name="deposit_claimed_method" class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
                    <option value="">Select one</option>
                    <option value="mobile_money" {{ old('deposit_claimed_method') === 'mobile_money' ? 'selected' : '' }}>Mobile money (MTN/Orange)</option>
                    <option value="bank_transfer" {{ old('deposit_claimed_method') === 'bank_transfer' ? 'selected' : '' }}>Bank transfer</option>
                    <option value="card" {{ old('deposit_claimed_method') === 'card' ? 'selected' : '' }}>Card</option>
                    <option value="cash" {{ old('deposit_claimed_method') === 'cash' ? 'selected' : '' }}>Cash</option>
                </select>
            </div>
        </div>

        <div>
            <label class="block text-sm text-slate-600 mb-1">Transaction reference / note</label>
            <input type="text" name="deposit_reference" value="{{ old('deposit_reference') }}" placeholder="e.g. MTN MoMo ref"
                   class="w-full rounded border border-slate-300 px-3 py-2 text-sm focus:border-blue-500 focus:ring-blue-500">
        </div>

        <div>
            <label class="block text-sm text-slate-600 mb-1">Payment screenshot / receipt (JPG, PNG or PDF, max 5MB)</label>
            <input type="file" name="deposit_proof" accept=".jpg,.jpeg,.png,.pdf"
                   class="w-full rounded border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1.5 file:text-sm file:font-medium file:text-slate-700 hover:file:bg-slate-200">
        </div>
    </div>

    <button type="submit" class="w-full rounded bg-blue-600 text-white font-medium py-3 hover:bg-blue-700">
        Request this reservation
    </button>

    <p class="text-center text-xs text-slate-400">
        This is a request, not a confirmed booking — our front desk will review it and get in touch.
    </p>
</form>
@endsection
