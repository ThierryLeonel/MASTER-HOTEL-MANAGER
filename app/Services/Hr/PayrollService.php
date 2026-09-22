<?php

namespace App\Services\Hr;

use App\Exceptions\HmsException;
use App\Models\EmployeeProfile;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\PaymentLedgerService;
use App\Services\SettingsService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Payroll run lifecycle: draft -> approved -> paid.
 *
 *   draft    -> generate()/regenerate() build one payslip per active,
 *               payroll-eligible employee (a real EmployeeProfile with
 *               a basic_salary set). Safe to re-run while draft: it
 *               replaces whatever payslips already exist in this run.
 *   approved -> approve() locks every payslip to `finalized`; nothing
 *               in this run can be regenerated after this point — a
 *               correction belongs in the next run, never a rewrite of
 *               this one (the same "never edit history" rule as
 *               folio_transactions/stock_movements/payments).
 *   paid     -> pay() posts every payslip's net pay through the same
 *               central PaymentLedgerService every other module uses,
 *               which means it is subject to the accounting-day lock
 *               exactly like an expense payment or a folio payment.
 */
class PayrollService
{
    public function __construct(
        private readonly AuditLogger $audit,
        private readonly SettingsService $settings,
        private readonly PayrollDeductionService $deductions,
        private readonly AttendanceService $attendance,
        private readonly PaymentLedgerService $ledger,
    ) {
    }

    public function createRun(Carbon $start, Carbon $end, User $actor, ?string $notes = null): PayrollRun
    {
        if ($end->lt($start)) {
            throw new HmsException('Payroll period end cannot be before the start.');
        }

        $run = PayrollRun::create([
            'period_start' => $start->toDateString(),
            'period_end' => $end->toDateString(),
            'status' => 'draft',
            'generated_by' => $actor->id,
            'notes' => $notes,
        ]);

        $this->audit->log('hr.payroll_run_created', 'hr', $run, null, $run->toArray(), actor: $actor);

        return $run;
    }

    /**
     * @param  array<int, array{allowance?: float, other_deduction?: float}>  $manualEntries  keyed by user_id
     */
    public function generate(PayrollRun $run, User $actor, array $manualEntries = []): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new HmsException("Payslips can only be (re)generated while the run is draft (current status: {$run->status}).");
        }

        $eligible = EmployeeProfile::where('employment_status', 'active')
            ->whereNotNull('basic_salary')
            ->with('user')
            ->get();

        if ($eligible->isEmpty()) {
            throw new HmsException('No payroll-eligible employees found (an active employee profile with a basic salary set).');
        }

        return DB::transaction(function () use ($run, $actor, $manualEntries, $eligible) {
            // Regenerating while draft replaces whatever was there before.
            foreach ($run->payslips as $existing) {
                $existing->lines()->delete();
                $existing->delete();
            }

            $workingDays = $this->settings->payrollWorkingDaysPerMonth();

            foreach ($eligible as $profile) {
                $this->generateOnePayslip($run, $profile, $manualEntries[$profile->user_id] ?? [], $workingDays);
            }

            $this->audit->log('hr.payroll_run_generated', 'hr', $run, null, $run->fresh('payslips')->toArray(), actor: $actor);

            return $run->fresh('payslips');
        });
    }

    private function generateOnePayslip(PayrollRun $run, EmployeeProfile $profile, array $manual, int $workingDays): Payslip
    {
        $basic = (float) $profile->basic_salary;
        $allowance = (float) ($manual['allowance'] ?? 0);
        $otherDeduction = (float) ($manual['other_deduction'] ?? 0);

        $absentDays = $this->attendance->countAbsentDays($profile->user, $run->period_start, $run->period_end);
        $dailyRate = $workingDays > 0 ? $basic / $workingDays : 0;
        $absenceDeduction = round($absentDays * $dailyRate, 2);

        $grossPay = round($basic + $allowance - $absenceDeduction, 2);
        $statutory = $this->deductions->calculate($grossPay);
        $netPay = round($grossPay - $statutory['total'] - $otherDeduction, 2);

        $payslip = Payslip::create([
            'payroll_run_id' => $run->id,
            'user_id' => $profile->user_id,
            'basic_salary' => $basic,
            'total_allowances' => $allowance,
            'absence_deduction' => $absenceDeduction,
            'gross_pay' => $grossPay,
            'total_statutory_deductions' => $statutory['total'],
            'other_deductions' => $otherDeduction,
            'net_pay' => $netPay,
            'status' => 'draft',
        ]);

        $payslip->lines()->create(['type' => 'earning', 'label' => 'Basic salary', 'amount' => $basic]);
        if ($allowance > 0) {
            $payslip->lines()->create(['type' => 'earning', 'label' => 'Allowance', 'amount' => $allowance]);
        }
        if ($absenceDeduction > 0) {
            $payslip->lines()->create(['type' => 'deduction', 'label' => "Unpaid absence ({$absentDays}d)", 'amount' => $absenceDeduction]);
        }
        foreach ($statutory['breakdown'] as $row) {
            $payslip->lines()->create(['type' => 'deduction', 'label' => $row['type']->name, 'amount' => $row['amount']]);
        }
        if ($otherDeduction > 0) {
            $payslip->lines()->create(['type' => 'deduction', 'label' => 'Other deduction', 'amount' => $otherDeduction]);
        }

        return $payslip;
    }

    public function approve(PayrollRun $run, User $actor): PayrollRun
    {
        if (! $run->isDraft()) {
            throw new HmsException("Only a draft payroll run can be approved (current status: {$run->status}).");
        }
        if ($run->payslips()->count() === 0) {
            throw new HmsException('Generate payslips before approving this run.');
        }

        return DB::transaction(function () use ($run, $actor) {
            $run->payslips()->update(['status' => 'finalized']);
            $run->update(['status' => 'approved', 'approved_by' => $actor->id, 'approved_at' => now()]);

            $this->audit->log('hr.payroll_run_approved', 'hr', $run, null, $run->fresh()->toArray(), actor: $actor);

            return $run->fresh();
        });
    }

    /**
     * Posts every payslip's net pay to the central payment ledger —
     * subject to AccountingDayService::assertOpen() exactly like every
     * other module's payments, so a locked accounting day blocks
     * payroll payout just as it blocks a folio or expense payment.
     *
     * Concurrency fix (fourth post-9.8 fix round, "money not double
     * recorded" — PHASE9_AUDIT_AND_PLAN.md §20): the status check used to
     * run BEFORE DB::transaction(), against an unlocked `$run` instance,
     * and was never re-checked inside it. Two duplicate "Pay this run"
     * requests racing against the same approved run (a double form
     * submission, a retried click) could both pass that check before
     * either had committed, and both then loop every payslip and post it
     * to the ledger a second time — the exact real money duplicated for
     * every employee in the run. `ExpenseService::markPaid()` already
     * fixed the identical shape of bug for a single expense payout; this
     * applies the same fix here — the status check now runs INSIDE the
     * transaction, against a row locked with `lockForUpdate()`, so a
     * second, racing request blocks until the first commits, then sees
     * the now-'paid' status and fails cleanly instead of paying twice.
     */
    public function pay(PayrollRun $run, User $actor, string $method, ?int $financialAccountId = null): PayrollRun
    {
        return DB::transaction(function () use ($run, $actor, $method, $financialAccountId) {
            /** @var PayrollRun $locked */
            $locked = PayrollRun::query()->whereKey($run->getKey())->lockForUpdate()->firstOrFail();

            if (! $locked->isApproved()) {
                throw new HmsException("Only an approved payroll run can be paid (current status: {$locked->status}).");
            }

            foreach ($locked->payslips as $payslip) {
                $this->ledger->record(
                    amount: (float) $payslip->net_pay,
                    method: $method,
                    sourceModule: 'payroll',
                    recordedBy: $actor,
                    sourceReference: "Payslip #{$payslip->id} (run #{$locked->id})",
                    reference: $payslip,
                    guestId: null,
                    direction: 'out',
                    financialAccountId: $financialAccountId,
                );

                $payslip->update(['status' => 'paid', 'payment_method' => $method, 'paid_at' => now()]);
            }

            $locked->update(['status' => 'paid', 'paid_at' => now()]);

            $this->audit->log('hr.payroll_run_paid', 'hr', $locked, null, $locked->fresh()->toArray(), actor: $actor);

            return $locked->fresh();
        });
    }
}
