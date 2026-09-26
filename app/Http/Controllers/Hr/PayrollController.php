<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\PayrollDeductionBracket;
use App\Models\PayrollDeductionType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\AuditLogger;
use App\Services\Hr\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;

class PayrollController extends Controller
{
    public function __construct(
        private readonly PayrollService $service,
        private readonly AuditLogger $audit,
    ) {
    }

    public function deductionsIndex()
    {
        $deductionTypes = PayrollDeductionType::with('brackets')->orderBy('display_order')->get();

        return view('hr.payroll.deductions', compact('deductionTypes'));
    }

    public function storeDeductionType(Request $request)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => 'required|string|max:50|unique:payroll_deduction_types,code',
            'calculation_method' => 'required|in:flat_percent,progressive_brackets',
            'flat_rate_percent' => 'required_if:calculation_method,flat_percent|nullable|numeric|min:0|max:100',
        ]);

        PayrollDeductionType::create($data);

        return back()->with('status', 'Deduction type saved.');
    }

    public function storeBracket(Request $request, PayrollDeductionType $payrollDeductionType)
    {
        $data = $request->validate([
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'nullable|numeric|gt:min_amount',
            'rate_percent' => 'required|numeric|min:0|max:100',
        ]);

        $payrollDeductionType->brackets()->create($data);

        return back()->with('status', 'Bracket added.');
    }

    /**
     * Broader request ("...edit, delete and update all categories and
     * every input, except financial inputs"): this is rate CONFIGURATION
     * (like RoomCategory's base_rate), not a financial transaction —
     * payslip_lines stores its own snapshotted label+amount per line at
     * generation time (see that migration's docblock) with no foreign key
     * back to payroll_deduction_types at all, so editing/deleting a
     * deduction type here can never rewrite an already-generated payslip.
     */
    public function updateDeductionType(Request $request, PayrollDeductionType $payrollDeductionType)
    {
        $data = $request->validate([
            'name' => 'required|string|max:255',
            'code' => ['required', 'string', 'max:50', Rule::unique('payroll_deduction_types', 'code')->ignore($payrollDeductionType->id)],
            'calculation_method' => 'required|in:flat_percent,progressive_brackets',
            'flat_rate_percent' => 'required_if:calculation_method,flat_percent|nullable|numeric|min:0|max:100',
            'is_active' => 'nullable|boolean',
        ]);
        $data['is_active'] = $request->boolean('is_active', $payrollDeductionType->is_active);

        $oldValues = $payrollDeductionType->only(array_keys($data));
        $payrollDeductionType->update($data);

        $this->audit->log('hr.deduction_type_updated', 'hr', $payrollDeductionType, oldValues: $oldValues, newValues: $payrollDeductionType->only(array_keys($data)), actor: $request->user());

        return back()->with('status', "Deduction type \"{$payrollDeductionType->name}\" updated.");
    }

    /**
     * Brackets cascade-delete with their type — acceptable, they're just
     * this type's own child configuration rows, never a transaction.
     */
    public function destroyDeductionType(Request $request, PayrollDeductionType $payrollDeductionType)
    {
        $name = $payrollDeductionType->name;
        $this->audit->log('hr.deduction_type_deleted', 'hr', $payrollDeductionType, oldValues: $payrollDeductionType->toArray(), actor: $request->user());
        $payrollDeductionType->delete();

        return back()->with('status', "Deduction type \"{$name}\" deleted.");
    }

    public function updateBracket(Request $request, PayrollDeductionBracket $bracket)
    {
        $data = $request->validate([
            'min_amount' => 'required|numeric|min:0',
            'max_amount' => 'nullable|numeric|gt:min_amount',
            'rate_percent' => 'required|numeric|min:0|max:100',
        ]);

        $oldValues = $bracket->only(array_keys($data));
        $bracket->update($data);

        $this->audit->log('hr.deduction_bracket_updated', 'hr', $bracket, oldValues: $oldValues, newValues: $bracket->only(array_keys($data)), actor: $request->user());

        return back()->with('status', 'Bracket updated.');
    }

    public function destroyBracket(Request $request, PayrollDeductionBracket $bracket)
    {
        $this->audit->log('hr.deduction_bracket_deleted', 'hr', $bracket, oldValues: $bracket->toArray(), actor: $request->user());
        $bracket->delete();

        return back()->with('status', 'Bracket deleted.');
    }

    public function index()
    {
        $runs = PayrollRun::withCount('payslips')->orderByDesc('period_start')->paginate(20);

        return view('hr.payroll.runs-index', compact('runs'));
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'period_start' => 'required|date',
            'period_end' => 'required|date|after_or_equal:period_start',
            'notes' => 'nullable|string|max:500',
        ]);

        $run = $this->service->createRun(
            Carbon::parse($data['period_start']), Carbon::parse($data['period_end']), $request->user(), $data['notes'] ?? null,
        );

        return redirect()->route('hr.payroll-runs.show', $run)->with('status', 'Payroll run created — generate payslips next.');
    }

    public function show(PayrollRun $payrollRun)
    {
        $payrollRun->load(['payslips.user', 'payslips.lines', 'generatedBy', 'approvedBy']);
        // Fourth post-9.8 fix round (day-close rebuild): the "Pay run"
        // form now picks a real financial account instead of a bare
        // method — see FolioController::payment()'s docblock.
        $financialAccounts = FinancialAccount::where('is_active', true)->orderBy('name')->get();

        return view('hr.payroll.runs-show', compact('payrollRun', 'financialAccounts'));
    }

    public function generate(Request $request, PayrollRun $payrollRun)
    {
        $this->service->generate($payrollRun, $request->user());

        return back()->with('status', 'Payslips generated.');
    }

    public function approve(Request $request, PayrollRun $payrollRun)
    {
        $this->service->approve($payrollRun, $request->user());

        return back()->with('status', 'Payroll run approved and locked.');
    }

    /**
     * Fourth post-9.8 fix round (day-close rebuild): `financial_account_id`
     * replaces the old bare `method` select — see
     * FolioController::payment()'s docblock for the same reasoning.
     */
    public function pay(Request $request, PayrollRun $payrollRun)
    {
        $data = $request->validate(['financial_account_id' => 'required|exists:financial_accounts,id']);

        // Correction round (audited defect #31): see PaymentController::
        // store()'s matching comment — never trust the submitted id alone.
        $account = FinancialAccount::where('is_active', true)->findOrFail($data['financial_account_id']);

        $this->service->pay($payrollRun, $request->user(), $account->paymentMethod(), $account->id);

        return back()->with('status', 'Payroll run paid and posted to the ledger.');
    }

    public function myPayslips(Request $request)
    {
        $payslips = Payslip::with(['payrollRun', 'lines'])
            ->where('user_id', $request->user()->id)
            ->orderByDesc('id')
            ->paginate(20);

        return view('hr.payroll.my-payslips', compact('payslips'));
    }
}
