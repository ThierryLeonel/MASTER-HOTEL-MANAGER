<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Models\FinancialAccount;
use App\Models\PayrollDeductionBracket;
use App\Models\PayrollDeductionType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Services\Hr\PayrollService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

class PayrollController extends Controller
{
    public function __construct(private readonly PayrollService $service)
    {
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
