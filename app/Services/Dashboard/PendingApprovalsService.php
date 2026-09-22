<?php

namespace App\Services\Dashboard;

use App\Models\DiscountApproval;
use App\Models\Expense;
use App\Models\HousekeepingTask;
use App\Models\LeaveRequest;
use App\Models\MaintenanceTicket;
use App\Models\PayrollRun;
use App\Models\PurchaseOrder;
use App\Models\RefundRequest;
use App\Models\Reservation;
use App\Models\StockRequisition;
use App\Models\StockWastage;
use App\Models\User;

/**
 * Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): the "action items" half of a
 * real management dashboard — what does THIS user specifically need to
 * act on today, across every approval workflow in the system. Purely
 * permission-driven (never role-name-driven), consistent with this
 * project's stated design: access is "permission-scoped, not
 * identity-scoped" (SECURITY_AUDIT.md §5) — an Owner and a Duty Manager
 * both holding approve-expense see the same expense count, they just
 * differ in which OTHER approve-* permissions they also hold.
 *
 * Every count here mirrors the exact status value each module's own
 * approve action checks — see the workflow tests in
 * tests/Feature/{Finance,Hr,Inventory}/*Test.php for the underlying
 * state machines this only reads from, never duplicates.
 */
class PendingApprovalsService
{
    /**
     * @return array<string, array{label: string, count: int, route: string}>
     */
    public function forUser(User $user): array
    {
        $items = [];

        if ($user->can('approve-reservation')) {
            $items['reservations'] = [
                'label' => 'Reservations awaiting approval',
                'count' => Reservation::where('status', 'pending')->count(),
                'route' => 'reservations.index',
            ];
        }

        if ($user->can('approve-expense') || $user->can('approve-large-expense')) {
            $items['expenses'] = [
                'label' => 'Expenses awaiting approval',
                'count' => Expense::where('status', 'pending')->count(),
                'route' => 'finance.expenses.index',
            ];
        }

        if ($user->can('approve-refund')) {
            $items['refunds'] = [
                'label' => 'Refunds awaiting approval',
                'count' => RefundRequest::where('status', 'pending')->count(),
                'route' => 'finance.refunds.index',
            ];
        }

        if ($user->can('approve-maintenance')) {
            $items['maintenance'] = [
                'label' => 'Maintenance tickets awaiting approval',
                'count' => MaintenanceTicket::where('status', 'open')->where('takes_room_out_of_service', true)->count(),
                'route' => 'maintenance.index',
            ];
        }

        // New requirement 5 ("define/complete housekeeping approval
        // workflow if missing") / Feature 6 ("Approved discounts on
        // check-in"): same permission-driven pattern as every other
        // widget here — this is what makes both show up automatically on
        // the Marketing Manager's dashboard (and Duty Manager/GM/Owner's)
        // without any role-name-specific dashboard code anywhere.
        if ($user->can('approve-housekeeping')) {
            $items['housekeeping'] = [
                'label' => 'Housekeeping tasks awaiting approval',
                'count' => HousekeepingTask::where('approval_status', 'pending')->count(),
                'route' => 'housekeeping.index',
            ];
        }

        if ($user->can('approve-discount')) {
            $items['discounts'] = [
                'label' => 'Discount requests addressed to you',
                'count' => DiscountApproval::where('status', 'pending')->where('requested_approver_id', $user->id)->count(),
                'route' => 'discount-approvals.index',
            ];
        }

        if ($user->can('approve-purchase-order')) {
            $items['purchase_orders'] = [
                'label' => 'Purchase orders awaiting approval',
                'count' => PurchaseOrder::where('status', 'submitted')->count(),
                'route' => 'inventory.purchase-orders.index',
            ];
        }

        if ($user->can('approve-requisition')) {
            $items['requisitions'] = [
                'label' => 'Stock requisitions awaiting approval',
                'count' => StockRequisition::where('status', 'pending')->count(),
                'route' => 'inventory.requisitions.index',
            ];
        }

        if ($user->can('approve-wastage')) {
            $items['wastage'] = [
                'label' => 'Wastage records awaiting approval',
                'count' => StockWastage::where('status', 'pending')->count(),
                'route' => 'inventory.wastage.index',
            ];
        }

        if ($user->can('approve-leave')) {
            $items['leave'] = [
                'label' => 'Leave requests awaiting approval',
                'count' => LeaveRequest::where('status', 'pending')->count(),
                'route' => 'hr.leave-requests.index',
            ];
        }

        if ($user->can('approve-payroll-run')) {
            $items['payroll'] = [
                'label' => 'Payroll runs awaiting approval',
                'count' => PayrollRun::where('status', 'draft')->count(),
                'route' => 'hr.payroll-runs.index',
            ];
        }

        return $items;
    }
}
