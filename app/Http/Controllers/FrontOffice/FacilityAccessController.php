<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\FacilityAccess;
use App\Models\FacilityRate;
use App\Models\FinancialAccount;
use App\Models\Stay;
use App\Services\FrontOffice\FacilityAccessService;
use Illuminate\Http\Request;

class FacilityAccessController extends Controller
{
    public function __construct(private readonly FacilityAccessService $service)
    {
    }

    public function index()
    {
        $facilities = Facility::where('is_active', true)->with(['rates' => fn ($q) => $q->where('is_active', true)])->orderBy('name')->get();
        $activeStays = Stay::where('status', 'active')->with('guest', 'room')->get();
        $accesses = FacilityAccess::with(['facility', 'stay.guest', 'rate', 'grantedBy'])->orderByDesc('id')->paginate(25);
        // Fourth post-9.8 fix round (day-close rebuild): the "sell to
        // walk-in" form now picks a real financial account instead of a
        // bare method — see FolioController::payment()'s docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('front-office.facility-access.index', compact('facilities', 'activeStays', 'accesses', 'financialAccounts'));
    }

    public function grantForGuest(Request $request)
    {
        $data = $request->validate([
            'facility_id' => 'required|exists:facilities,id',
            'stay_id' => 'required|exists:stays,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $facility = Facility::findOrFail($data['facility_id']);
        $stay = Stay::findOrFail($data['stay_id']);

        $this->service->grantForGuest($facility, $stay, $request->user(), $data['notes'] ?? null);

        return back()->with('status', "Free {$facility->name} access granted.");
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `method` select — see
     * FolioController::payment()'s docblock for the same reasoning.
     */
    public function sellToWalkIn(Request $request)
    {
        $data = $request->validate([
            'facility_id' => 'required|exists:facilities,id',
            'facility_rate_id' => 'required|exists:facility_rates,id',
            'visitor_name' => 'required|string|max:150',
            'visitor_phone' => 'nullable|string|max:40',
            'financial_account_id' => 'required|exists:financial_accounts,id',
            'notes' => 'nullable|string|max:500',
        ]);

        $facility = Facility::findOrFail($data['facility_id']);
        $rate = FacilityRate::findOrFail($data['facility_rate_id']);
        // Correction round (audited defect #31): see PaymentController::
        // store()'s matching comment — never trust the submitted id alone.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $access = $this->service->sellToWalkIn(
            $facility, $rate, $data['visitor_name'], $data['visitor_phone'] ?? null,
            $account->paymentMethod(), $request->user(), $data['notes'] ?? null, $account->id,
        );

        return redirect()->route('facility-access.print', $access)->with('status', "{$facility->name} access sold to {$data['visitor_name']}.");
    }

    public function print(FacilityAccess $facilityAccess)
    {
        $facilityAccess->load(['facility', 'rate', 'grantedBy']);

        return view('front-office.facility-access.receipt-print', ['access' => $facilityAccess]);
    }
}
