<?php

namespace App\Http\Controllers\FrontOffice;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\FacilityAccess;
use App\Models\FacilityRate;
use App\Services\AuditLogger;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Admin-level configuration of facilities and their rates — separate
 * from FacilityAccessController, which is the front-desk day-to-day
 * screen for actually granting/selling access. Gated on 'manage-facilities'
 * (owner/general_manager/duty_manager), not the front desk's
 * 'grant-facility-access'.
 */
class FacilityController extends Controller
{
    public function __construct(private readonly AuditLogger $audit)
    {
    }

    public function index()
    {
        $facilities = Facility::with(['rates' => fn ($q) => $q->orderBy('name')])->orderBy('name')->get();

        return view('front-office.facilities.index', compact('facilities'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'code' => 'required|string|max:30|unique:facilities,code',
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        Facility::create($data + ['is_active' => true]);

        return back()->with('status', 'Facility created.');
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): editing a facility's own
     * code/name/description. Never touches a past access grant —
     * facility_accesses.amount_charged is captured at grant time, not
     * read live from here.
     */
    public function update(Request $request, Facility $facility)
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:30', Rule::unique('facilities', 'code')->ignore($facility->id)],
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
        ]);

        $oldValues = $facility->only(['code', 'name', 'description']);
        $facility->update($data);

        $this->audit->log('facilities.facility_updated', 'front_office', $facility, oldValues: $oldValues, newValues: $facility->only(array_keys($oldValues)), actor: $request->user());

        return back()->with('status', "Facility \"{$facility->name}\" updated.");
    }

    /**
     * facility_rates/facility_accesses both use restrictOnDelete on
     * facility_id — a facility with either is deactivated instead so it
     * disappears from new grants without losing the accesses already
     * recorded against it.
     */
    public function destroy(Request $request, Facility $facility)
    {
        if ($facility->rates()->exists()) {
            throw new HmsException("Facility \"{$facility->name}\" still has rates configured — delete those rates first.");
        }

        if ($facility->accesses()->exists()) {
            throw new HmsException("Facility \"{$facility->name}\" has access history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        $name = $facility->name;
        $this->audit->log('facilities.facility_deleted', 'front_office', $facility, oldValues: $facility->toArray(), actor: $request->user());
        $facility->delete();

        return back()->with('status', "Facility \"{$name}\" deleted.");
    }

    public function storeRate(Request $request, Facility $facility)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $facility->rates()->create($data + ['is_active' => true]);

        return back()->with('status', 'Rate added.');
    }

    public function toggleRate(FacilityRate $rate)
    {
        $rate->update(['is_active' => ! $rate->is_active]);

        return back()->with('status', 'Rate updated.');
    }

    /**
     * Editing a rate's name/amount. facility_accesses.amount_charged is
     * denormalized at the moment access was granted (see that table's
     * migration docblock), so correcting a rate's price here never
     * rewrites what a past walk-in was actually charged.
     */
    public function updateRate(Request $request, FacilityRate $rate)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $oldValues = $rate->only(['name', 'amount']);
        $rate->update($data);

        $this->audit->log('facilities.rate_updated', 'front_office', $rate, oldValues: $oldValues, newValues: $rate->only(['name', 'amount']), actor: $request->user());

        return back()->with('status', "Rate \"{$rate->name}\" updated.");
    }

    public function destroyRate(Request $request, FacilityRate $rate)
    {
        if (FacilityAccess::where('facility_rate_id', $rate->id)->exists()) {
            throw new HmsException("Rate \"{$rate->name}\" has access history and can't be deleted without erasing that record. Disable it instead.");
        }

        $name = $rate->name;
        $this->audit->log('facilities.rate_deleted', 'front_office', $rate, oldValues: $rate->toArray(), actor: $request->user());
        $rate->delete();

        return back()->with('status', "Rate \"{$name}\" deleted.");
    }
}
