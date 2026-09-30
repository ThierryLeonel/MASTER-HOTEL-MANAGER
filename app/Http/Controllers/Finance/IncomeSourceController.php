<?php

namespace App\Http\Controllers\Finance;

use App\Http\Controllers\Controller;
use App\Models\IncomeSource;
use App\Services\Finance\IncomeSourceService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Direct request ("the list should not be fixed... the accountant, or
 * finance officer [create/update], and [the owner] should be able to
 * update, create and delete it"): a small management screen for the
 * `income_sources` list behind the "Income source" dropdown on the
 * general "record a payment" screen. Create/update are gated on
 * `manage-income-sources` (Owner/General Manager/Accountant); delete is
 * gated separately on `delete-income-sources` (Owner-only), the same
 * "delete is more destructive, so it's Owner-only" split already used for
 * `delete-users` and `void-transactions` — see routes/web.php and
 * RolesAndPermissionsSeeder for both permissions.
 */
class IncomeSourceController extends Controller
{
    public function __construct(private readonly IncomeSourceService $service)
    {
    }

    public function index(Request $request)
    {
        $incomeSources = IncomeSource::with(['creator', 'updater'])->orderBy('name')->get();

        return view('finance.income-sources.index', compact('incomeSources'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:120|unique:income_sources,name',
        ]);

        $this->service->create($data, $request->user());

        return back()->with('status', 'Income source added.');
    }

    public function update(Request $request, IncomeSource $incomeSource)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120', Rule::unique('income_sources', 'name')->ignore($incomeSource->id)],
        ]);

        $this->service->update($incomeSource, $data, $request->user());

        return back()->with('status', "\"{$incomeSource->name}\" updated.");
    }

    public function destroy(Request $request, IncomeSource $incomeSource)
    {
        $name = $incomeSource->name;
        $this->service->delete($incomeSource, $request->user());

        return back()->with('status', "\"{$name}\" deleted.");
    }
}
