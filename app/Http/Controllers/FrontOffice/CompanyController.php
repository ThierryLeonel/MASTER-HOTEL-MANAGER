<?php

namespace App\Http\Controllers\FrontOffice;

use App\Http\Controllers\Controller;
use App\Models\Company;
use Illuminate\Http\Request;

/**
 * Corporate/company billing accounts (PHASE9_AUDIT_AND_PLAN.md §22) — a
 * company a stay can optionally be "billed to" at check-in, so its debt
 * rolls up across every stay it was billed for. Gated on the same
 * `manage-guests` permission as Guests, since this is the same kind of
 * front-office record-keeping.
 */
class CompanyController extends Controller
{
    public function index(Request $request)
    {
        $companies = Company::where('is_active', true)
            ->when($request->filled('search'), fn ($q) => $q->where('name', 'like', '%'.$request->string('search').'%'))
            ->orderBy('name')
            ->paginate(25);

        return view('front-office.companies.index', compact('companies'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'contact_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
        ]);

        Company::create($data + ['is_active' => true, 'created_by' => $request->user()->id]);

        return back()->with('status', 'Company added.');
    }
}
