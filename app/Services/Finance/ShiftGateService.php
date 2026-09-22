<?php

namespace App\Services\Finance;

use App\Exceptions\HmsException;
use App\Models\Department;
use App\Models\Shift;

/**
 * Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21): "make it such
 * that the bar, restaurant, and the front desk (receptionist) can not do
 * any transaction without opening a shift" — extended, at delivery time
 * per an explicit follow-up answer, to the other two outlets that
 * already carry their own shifts (Bar Outside, Bucareaux) for
 * consistency, and applied to every payment method, not just cash.
 *
 * This deliberately REVERSES ShiftService's original "shifts are purely
 * informational, never a hard gate" scope decision (see that class's
 * docblock for the full history of why that was the original call) — but
 * only for these five outlet/front-line departments, and only for
 * NEW/manual transactions those departments' own staff initiate:
 *
 *  - Wired into PaymentLedgerService::record() (gates every payment whose
 *    department was auto-derived from a guest-facing sourceModule — POS
 *    sales, folio payments, general payments, reservation deposits,
 *    facility access sales).
 *  - Wired directly into OrderController (POS order create/add-item/
 *    confirm/close), FolioController::charge() (folio charges are not
 *    "payments" so PaymentLedgerService never sees them), CheckInService
 *    (both check-in paths post an initial lodging charge), and
 *    FacilityAccessService (a free guest grant never touches the ledger
 *    either).
 *
 * Deliberately NOT wired into:
 *  - CheckOutService — spec section 10's "an unpaid balance never blocks
 *    checkout" is a hard, previously-established invariant; blocking a
 *    guest's departure because someone forgot to open a shift would be a
 *    far worse failure mode than a missing shift, exactly the same
 *    reasoning ShiftService's original docblock already used to justify
 *    not gating payments at all. Checkout's automatic extension-night
 *    charge (if any) always posts, regardless of shift state.
 *  - StayController::markComplimentary/unmarkComplimentary — these
 *    reverse/waive a charge rather than create new business, and
 *    shouldn't be blocked by a missing shift.
 *  - Expense/CashDisbursement/Payroll payments — see
 *    PaymentLedgerService's docblock.
 *  - Plain reservation creation (no deposit) — booking ahead of a shift
 *    being open is normal (e.g. a phone reservation taken days out); only
 *    the deposit payment itself (a real cash-handling transaction) is
 *    gated, automatically, via PaymentLedgerService.
 */
class ShiftGateService
{
    public const GATED_DEPARTMENT_CODES = ['FO', 'BAR', 'REST', 'BAROUT', 'BUCA'];

    public function assertOpenForDepartment(?Department $department): void
    {
        if (! $department || ! in_array($department->code, self::GATED_DEPARTMENT_CODES, true)) {
            return;
        }

        $hasOpenShift = Shift::where('department_id', $department->id)->where('status', 'open')->exists();

        if (! $hasOpenShift) {
            throw new HmsException("{$department->name} has no open shift right now — open a shift (Finance > Shifts) before recording this transaction.");
        }
    }

    public function assertOpenForCode(string $code): void
    {
        $this->assertOpenForDepartment(Department::where('code', $code)->first());
    }

    public function assertOpenForOutlet(string $outlet): void
    {
        $this->assertOpenForCode(Department::codeForOutlet($outlet));
    }
}
