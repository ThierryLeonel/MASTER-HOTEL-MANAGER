<?php

namespace App\Http\Controllers\Public;

use App\Http\Controllers\Controller;
use App\Models\RoomCategory;
use App\Services\FrontOffice\ReservationService;
use App\Services\SettingsService;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Fourth post-9.8 fix round ("update the system such that it can catch
 * reservations from the website too"). Public, unauthenticated — no
 * `auth` middleware, so this is deliberately routed outside that group
 * in routes/web.php, alongside the same `throttle` pattern already used
 * for the unauthenticated login endpoints just above it.
 *
 * This never touches money or the central payment ledger — the guest can
 * only submit a REQUEST (optionally with a screenshot/reference of a
 * deposit they say they already sent); a staff member reviews it in the
 * normal pending-reservations queue and records any real deposit through
 * the existing, permission-gated ReservationController::recordDeposit()
 * once it's confirmed. See ReservationService::createFromWebsite()'s
 * docblock for the full design.
 */
class WebsiteBookingController extends Controller
{
    public function __construct(
        private readonly ReservationService $service,
        private readonly SettingsService $settings,
    ) {
    }

    public function showForm()
    {
        $categories = RoomCategory::where('is_active', true)->orderBy('base_rate')->get();
        $hotelName = $this->settings->hotelName();

        return view('public.booking.create', compact('categories', 'hotelName'));
    }

    public function store(Request $request)
    {
        // Honeypot: a hidden field no human sees or fills, but a naive
        // form-spam bot typically does. Silently pretend success rather
        // than telling an attacker exactly what tripped the check.
        if ($request->filled('company_website')) {
            return redirect()->route('public.booking.success');
        }

        $data = $request->validate([
            'first_name' => 'required|string|max:100',
            'last_name' => 'nullable|string|max:100',
            'phone' => 'required|string|max:30',
            'email' => 'nullable|email|max:255',
            'room_category_id' => 'required|exists:room_categories,id',
            'check_in_date' => 'required|date|after_or_equal:today',
            'check_out_date' => 'required|date|after:check_in_date',
            'adults' => 'nullable|integer|min:1|max:20',
            'children' => 'nullable|integer|min:0|max:20',
            'notes' => 'nullable|string|max:1000',
            'deposit_claimed_amount' => 'nullable|numeric|min:0|required_with:deposit_claimed_method',
            'deposit_reference' => 'nullable|string|max:150',
            'deposit_claimed_method' => 'nullable|required_with:deposit_claimed_amount|in:cash,mobile_money,card,bank_transfer',
            'deposit_proof' => 'nullable|file|mimes:jpg,jpeg,png,pdf|max:5120',
        ]);

        if (! RoomCategory::where('id', $data['room_category_id'])->where('is_active', true)->exists()) {
            throw ValidationException::withMessages([
                'room_category_id' => 'That room type is no longer available. Please choose another.',
            ]);
        }

        // Correction round (audited defect #33): a guest's deposit-proof
        // upload (typically a mobile-money screenshot showing their own
        // phone number, transaction id, and often other personal/financial
        // details) used to be stored on the PUBLIC disk and served from a
        // predictable, unauthenticated URL — anyone who guessed or scraped
        // the path could view another guest's private financial evidence.
        // It's now stored on the private 'local' disk (not web-reachable at
        // all) and only ever served back out through
        // ReservationController::depositProof(), which requires
        // authentication and the 'manage-reservations' permission.
        if ($request->hasFile('deposit_proof')) {
            $data['deposit_proof_path'] = $request->file('deposit_proof')->store('deposit-proofs', 'local');
        }

        $reservation = $this->service->createFromWebsite($data);

        return redirect()->route('public.booking.success', ['ref' => $reservation->reservation_number]);
    }

    public function success(Request $request)
    {
        return view('public.booking.success', [
            'reference' => $request->query('ref'),
            'hotelName' => $this->settings->hotelName(),
            'hotelPhone' => $this->settings->hotelPhone(),
        ]);
    }
}
