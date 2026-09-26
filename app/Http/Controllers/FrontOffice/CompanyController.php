<?php

namespace App\Http\Controllers\FrontOffice;

use App\Exceptions\HmsException;
use App\Http\Controllers\Controller;
use App\Models\AccountsReceivable;
use App\Models\Company;
use App\Models\Stay;
use App\Services\AuditLogger;
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
    public function __construct(private readonly AuditLogger $audit)
    {
    }

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

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): the company's own contact
     * record and active flag. `credit_limit` here is informational only
     * (see the store() form's own placeholder) — it never rewrites a
     * stay's folio or an AccountsReceivable row's actual balance, both of
     * which stay in their own tables regardless of this edit.
     */
    public function update(Request $request, Company $company)
    {
        $data = $request->validate([
            'name' => 'required|string|max:150',
            'contact_name' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:40',
            'email' => 'nullable|email|max:255',
            'credit_limit' => 'nullable|numeric|min:0',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', $company->is_active);

        $oldValues = $company->only(array_keys($data));
        $company->update($data);

        $this->audit->log('front_office.company_updated', 'front_office', $company, oldValues: $oldValues, newValues: $company->only(array_keys($data)), actor: $request->user());

        return back()->with('status', "Company \"{$company->name}\" updated.");
    }

    /**
     * stays.company_id and accounts_receivable.company_id are both
     * nullOnDelete — a hard delete would silently strip which company a
     * historical stay/debt was billed to, exactly the financial-record
     * rewrite this project avoids. A company with either is deactivated
     * instead (it already disappears from new check-ins/billing via
     * is_active, see index()'s where clause).
     */
    public function destroy(Request $request, Company $company)
    {
        if (Stay::where('company_id', $company->id)->exists()) {
            throw new HmsException("Company \"{$company->name}\" has stay/billing history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        if (AccountsReceivable::where('company_id', $company->id)->exists()) {
            throw new HmsException("Company \"{$company->name}\" has accounts-receivable history and can't be deleted without erasing that record. Deactivate it instead.");
        }

        $name = $company->name;
        $this->audit->log('front_office.company_deleted', 'front_office', $company, oldValues: $company->toArray(), actor: $request->user());
        $company->delete();

        return back()->with('status', "Company \"{$name}\" deleted.");
    }
}
