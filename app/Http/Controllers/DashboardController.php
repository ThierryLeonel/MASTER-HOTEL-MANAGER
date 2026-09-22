<?php

namespace App\Http\Controllers;

use App\Models\AccountsReceivable;
use App\Models\HousekeepingTask;
use App\Models\MaintenanceTicket;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\Stay;
use App\Services\Dashboard\PendingApprovalsService;
use App\Services\Reporting\FinancialReportService;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Dashboard (spec section 25).
 *
 * Phase 9.0 honesty/security fix: this controller's docblock previously
 * claimed "Role-specific dashboards" while the code built one identical
 * $data array for every authenticated user, including a hotel-wide
 * financial total (openReceivablesTotal) that front-line, non-financial
 * roles (bar/restaurant staff, cashier) have no business reason to see.
 * That claim was false and has been corrected below.
 *
 * This fix scopes the one figure that is genuinely financial-reporting
 * data (open receivables) behind the same permissions used elsewhere for
 * financial reporting/operational oversight ('view-financial-reports',
 * 'view-operational-reports'). It does NOT yet build the fully
 * per-role-tailored dashboard layouts described in the master prompt
 * (different widget sets per role, using the existing
 * view-dashboard-{owner,gm,duty-manager,accountant,front-desk,bar,
 * restaurant,cashier} permissions that RolesAndPermissionsSeeder already
 * grants but that nothing reads yet) — that full redesign is tracked as
 * Phase 9.4 (Management Dashboard) in PHASE9_AUDIT_AND_PLAN.md. The
 * operational counts left ungated here (occupancy, arrivals/departures,
 * housekeeping/maintenance ticket counts) are not guest-identifying or
 * financial data and match what every role already sees on the
 * equivalent list screens they have access to.
 *
 * Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5) builds the actual
 * role-aware content on top of this: a "pending approvals" widget
 * (PendingApprovalsService — purely permission-driven, see that class's
 * docblock) showing each user only the approval queues they personally
 * hold an approve-* permission for, and a trailing-7-day financial
 * summary (reusing FinancialReportService::incomeStatement(), the same
 * method the Income Statement report and Weekly Owner Summary use — see
 * WeeklyOwnerSummaryService) gated behind 'view-financial-reports'
 * specifically, not the broader $canViewFinancials used for receivables.
 */
class DashboardController extends Controller
{
    public function __construct(
        private readonly PendingApprovalsService $pendingApprovals,
        private readonly FinancialReportService $financial,
    ) {
    }

    public function index(Request $request)
    {
        $user = Auth::user();

        $canViewFinancials = $user->can('view-financial-reports') || $user->can('view-operational-reports');
        $canViewFinancialReports = $user->can('view-financial-reports');

        $data = [
            'roomsTotal' => Room::count(),
            'roomsOccupied' => Room::where('status', 'occupied')->count(),
            'roomsAvailable' => Room::where('status', 'available')->count(),
            'roomsMaintenance' => Room::whereIn('status', ['maintenance', 'out_of_service'])->count(),
            'arrivalsToday' => Reservation::where('status', 'confirmed')->whereDate('check_in_date', today())->count(),
            'departuresToday' => Stay::where('status', 'active')->whereDate('expected_check_out_date', today())->count(),
            'pendingReservations' => Reservation::where('status', 'pending')->count(),
            'activeStays' => Stay::where('status', 'active')->count(),
            'canViewFinancials' => $canViewFinancials,
            'openReceivables' => $canViewFinancials
                ? AccountsReceivable::whereIn('status', ['open', 'partially_paid'])->count()
                : null,
            'openReceivablesTotal' => $canViewFinancials
                ? AccountsReceivable::whereIn('status', ['open', 'partially_paid'])->sum('amount_outstanding')
                : null,
            'pendingHousekeeping' => HousekeepingTask::where('status', 'pending')->count(),
            'openMaintenanceTickets' => MaintenanceTicket::whereIn('status', ['open', 'approved', 'in_progress'])->count(),
            'ticketsAwaitingApproval' => MaintenanceTicket::where('status', 'open')->where('takes_room_out_of_service', true)->count(),
            'canReviewMaintenance' => $user->can('report-maintenance'),
            'pendingApprovals' => $this->pendingApprovals->forUser($user),
            'canViewFinancialReports' => $canViewFinancialReports,
            'weeklyFinancialSummary' => $canViewFinancialReports
                ? $this->financial->incomeStatement(Carbon::today()->subDays(6), Carbon::today())
                : null,
        ];

        $occupancyRate = $data['roomsTotal'] > 0
            ? round(($data['roomsOccupied'] / $data['roomsTotal']) * 100, 1)
            : 0.0;

        return view('dashboard', array_merge($data, [
            'occupancyRate' => $occupancyRate,
            'user' => $user,
        ]));
    }
}
