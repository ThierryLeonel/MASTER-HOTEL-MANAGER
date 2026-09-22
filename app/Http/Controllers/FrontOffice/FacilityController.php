<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\Facility;
use App\Models\FacilityRate;
use Illuminate\Http\Request;

/**
 * Admin-level configuration of facilities and their rates — separate
 * from FacilityAccessController, which is the front-desk day-to-day
 * screen for actually granting/selling access. Gated on 'manage-facilities'
 * (owner/general_manager/duty_manager), not the front desk's
 * 'grant-facility-access'.
 */
class FacilityController extends Controller
{
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
}
