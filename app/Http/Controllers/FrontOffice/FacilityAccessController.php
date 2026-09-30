<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\ChargeType;
use App\Models\ChargeTypeRate;
use App\Models\FacilityAccess;
use App\Models\FinancialAccount;
use App\Models\Stay;
use App\Services\FrontOffice\FacilityAccessService;
use Illuminate\Http\Request;

/**
 * Charge-types unification (Sept 2026 — see ChargeType's own docblock and
 * FacilityAccessService's): this front-desk day-to-day screen now offers
 * every walk-in-payable ChargeType (Gym, Swimming Pool, and any custom
 * type an admin adds and marks walk-in payable) instead of the old fixed
 * Facility list — admin-level configuration of these has moved to
 * Finance > Charge Types (see ChargeTypeController), which replaces the
 * old FacilityController admin screen.
 */
class FacilityAccessController extends Controller
{
    public function __construct(private readonly FacilityAccessService $service)
    {
    }

    public function index()
    {
        $chargeTypes = ChargeType::where('is_active', true)
            ->where('walk_in_payable', true)
            ->with(['rates' => fn ($q) => $q->where('is_active', true)])
            ->orderBy('name')
            ->get();
        $activeStays = Stay::where('status', 'active')->with('guest', 'room')->get();
        $accesses = FacilityAccess::with(['facility', 'stay.guest', 'rate', 'grantedBy'])->orderByDesc('id')->paginate(25);
        // Fourth post-9.8 fix round (day-close rebuild): the "sell to
        // walk-in" form now picks a real financial account instead of a
        // bare method — see FolioController::payment()'s docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('front-office.facility-access.index', compact('chargeTypes', 'activeStays', 'accesses', 'financialAccounts'));
    }

    public function grantForGuest(Request $request)
    {
        $data = $request->validate([
            'charge_type_id' => 'required|exists:charge_types,id',
            'stay_id' => 'required|exists:stays,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $chargeType = ChargeType::findOrFail($data['charge_type_id']);
        $stay = Stay::findOrFail($data['stay_id']);

        $this->service->grantForGuest($chargeType, $stay, $request->user(), $data['notes'] ?? null);

        return back()->with('status', "Free {$chargeType->name} access granted.");
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `method` select — see
     * FolioController::payment()'s docblock for the same reasoning.
     */
    public function sellToWalkIn(Request $request)
    {
        $data = $request->validate([
            'charge_type_id' => 'required|exists:charge_types,id',
            'charge_type_rate_id' => 'required|exists:charge_type_rates,id',
            'visitor_name' => 'required|string|max:150',
            'visitor_phone' => 'nullable|string|max:40',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $chargeType = ChargeType::findOrFail($data['charge_type_id']);
        $rate = ChargeTypeRate::findOrFail($data['charge_type_rate_id']);
        // Correction round (audited defect #31): see PaymentController::
        // store()'s matching comment — never trust the submitted id alone.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $access = $this->service->sellToWalkIn(
            $chargeType, $rate, $data['visitor_name'], $data['visitor_phone'] ?? null,
            $account->paymentMethod(), $request->user(), $data['notes'] ?? null, $account->id,
        );

        return redirect()->route('facility-access.print', $access)->with('status', "{$chargeType->name} access sold to {$data['visitor_name']}.");
    }

    public function print(FacilityAccess $facilityAccess)
    {
        $facilityAccess->load(['facility', 'rate', 'grantedBy']);

        return view('front-office.facility-access.receipt-print', ['access' => $facilityAccess]);
    }
}
