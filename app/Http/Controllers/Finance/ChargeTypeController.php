<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\ChargeType;
use App\Models\ChargeTypeRate;
use App\Models\Department;
use App\Services\Finance\ChargeTypeService;
use Illuminate\Http\Request;

/**
 * Direct request (Sept 2026): "normally we have bar charge, restaurant
 * charge, frontdesk charge, rental charge, pool fee, Gym Fee, etc
 * include all connect them... just build it in such a way i can edit and
 * or just add or delete." This is the one admin screen that replaces
 * both the old hardcoded charge-type list and the separate Facility
 * (Gym & Pool) management screen — see ChargeType's own docblock.
 * Gated on 'manage-charge-types' (Owner/General Manager/Duty Manager —
 * see RolesAndPermissionsSeeder), the same tier `manage-facilities` used
 * to be gated on.
 */
class ChargeTypeController extends Controller
{
    public function __construct(private readonly ChargeTypeService $service)
    {
    }

    public function index()
    {
        $chargeTypes = ChargeType::with(['department', 'rates' => fn ($q) => $q->orderBy('name')])
            ->orderByDesc('is_system')
            ->orderBy('name')
            ->get();

        $departments = Department::orderBy('name')->get();

        return view('finance.charge-types.index', compact('chargeTypes', 'departments'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'department_id' => 'nullable|exists:departments,id',
            'postable_to_folio' => 'nullable|boolean',
            'walk_in_payable' => 'nullable|boolean',
            'free_for_resident_guests' => 'nullable|boolean',
        ]);

        $this->service->create($data, $request->user());

        return back()->with('status', "\"{$data['name']}\" added to the charge types list.");
    }

    /**
     * `code` is never accepted here — see ChargeTypeService::update()'s
     * docblock for why a charge type's code is permanent from creation
     * onward, even for a non-system one.
     */
    public function update(Request $request, ChargeType $chargeType)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'description' => 'nullable|string|max:500',
            'department_id' => 'nullable|exists:departments,id',
            'postable_to_folio' => 'nullable|boolean',
            'walk_in_payable' => 'nullable|boolean',
            'free_for_resident_guests' => 'nullable|boolean',
            'is_active' => 'nullable|boolean',
        ]);

        $this->service->update($chargeType, $data, $request->user());

        return back()->with('status', "\"{$chargeType->name}\" updated.");
    }

    public function destroy(Request $request, ChargeType $chargeType)
    {
        $name = $chargeType->name;
        $this->service->delete($chargeType, $request->user());

        return back()->with('status', "\"{$name}\" deleted.");
    }

    public function storeRate(Request $request, ChargeType $chargeType)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $this->service->storeRate($chargeType, $data, $request->user());

        return back()->with('status', 'Rate added.');
    }

    public function updateRate(Request $request, ChargeTypeRate $rate)
    {
        $data = $request->validate([
            'name' => 'required|string|max:100',
            'amount' => 'required|numeric|min:0.01',
        ]);

        $this->service->updateRate($rate, $data, $request->user());

        return back()->with('status', "Rate \"{$rate->name}\" updated.");
    }

    public function toggleRate(ChargeTypeRate $rate)
    {
        $this->service->toggleRate($rate);

        return back()->with('status', 'Rate updated.');
    }

    public function destroyRate(Request $request, ChargeTypeRate $rate)
    {
        $name = $rate->name;
        $this->service->destroyRate($rate, $request->user());

        return back()->with('status', "Rate \"{$name}\" deleted.");
    }
}
