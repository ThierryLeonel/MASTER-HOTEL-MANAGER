<?php

namespace Tests\Feature\Dashboard;

use App\Models\Department;
use App\Models\Expense;
use App\Models\Folio;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MaintenanceTicket;
use App\Models\PayrollRun;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\RefundRequest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\StockRequisition;
use App\Models\StockWastage;
use App\Models\Stay;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\Dashboard\PendingApprovalsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\CreatesUsers;
use Tests\TestCase;

/**
 * Phase 9.4 (PHASE9_AUDIT_AND_PLAN.md §3.5): PendingApprovalsService is
 * purely permission-driven — every branch here checks $user->can(...),
 * never a role name. These tests exercise every branch directly against
 * the service (not through the dashboard HTTP route) so a failure points
 * straight at the offending permission check.
 */
class PendingApprovalsServiceTest extends TestCase
{
    use RefreshDatabase, CreatesUsers;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRolesAndSettings();
    }

    private function service(): PendingApprovalsService
    {
        return app(PendingApprovalsService::class);
    }

    public function test_a_user_with_no_approve_permissions_sees_no_pending_approval_items(): void
    {
        // cashier's permission grant (RolesAndPermissionsSeeder) holds no
        // approve-* permission at all.
        $cashier = $this->userWithRole('cashier');

        $this->assertSame([], $this->service()->forUser($cashier));
    }

    public function test_owner_sees_every_pending_approval_item(): void
    {
        $owner = $this->userWithRole('owner');

        $items = $this->service()->forUser($owner);

        $this->assertSame(
            ['reservations', 'expenses', 'refunds', 'maintenance', 'purchase_orders', 'requisitions', 'wastage', 'leave', 'payroll'],
            array_keys($items)
        );
    }

    public function test_reservations_awaiting_approval_are_counted_only_for_a_user_who_can_approve_them(): void
    {
        Reservation::factory()->create(['status' => 'pending']);
        Reservation::factory()->create(['status' => 'confirmed']);

        $dutyManager = $this->userWithRole('duty_manager');
        // Fourth post-9.8 fix round ("make sure the front desk have the
        // right to approve reservation too"): front desk now also holds
        // approve-reservation, so it counts for them too now.
        $frontDesk = $this->userWithRole('front_desk');
        $barStaff = $this->pinUser('bar_staff');

        $this->assertSame(1, $this->service()->forUser($dutyManager)['reservations']['count']);
        $this->assertSame(1, $this->service()->forUser($frontDesk)['reservations']['count']);
        $this->assertArrayNotHasKey('reservations', $this->service()->forUser($barStaff));
    }

    public function test_expenses_are_counted_for_either_approve_expense_or_approve_large_expense(): void
    {
        Expense::create([
            'expense_number' => 'EXP-1', 'category' => 'Supplies', 'description' => 'Cleaning supplies',
            'amount' => 20000, 'status' => 'pending', 'requested_by' => User::factory()->create()->id,
        ]);

        // duty_manager holds approve-expense but NOT approve-large-expense.
        $dutyManager = $this->userWithRole('duty_manager');
        // accountant holds both.
        $accountant = $this->userWithRole('accountant');
        $cashier = $this->userWithRole('cashier');

        $this->assertSame(1, $this->service()->forUser($dutyManager)['expenses']['count']);
        $this->assertSame(1, $this->service()->forUser($accountant)['expenses']['count']);
        $this->assertArrayNotHasKey('expenses', $this->service()->forUser($cashier));
    }

    public function test_refunds_awaiting_approval_are_counted(): void
    {
        $folio = Folio::factory()->for(Stay::factory()->create(['status' => 'active']))
            ->create(['status' => 'open', 'balance' => -10000]);

        RefundRequest::create([
            'refund_number' => 'REF-1', 'folio_id' => $folio->id, 'amount' => 10000,
            'reason' => 'Guest overpaid at checkout', 'method' => 'cash', 'status' => 'pending',
            'requested_by' => User::factory()->create()->id,
        ]);

        $accountant = $this->userWithRole('accountant');
        $frontDesk = $this->userWithRole('front_desk');

        $this->assertSame(1, $this->service()->forUser($accountant)['refunds']['count']);
        $this->assertArrayNotHasKey('refunds', $this->service()->forUser($frontDesk));
    }

    public function test_maintenance_tickets_only_count_open_tickets_that_take_a_room_out_of_service(): void
    {
        $reporter = User::factory()->create();
        $room = Room::factory()->create();

        MaintenanceTicket::create([
            'room_id' => $room->id, 'title' => 'AC broken', 'description' => 'Blowing warm air',
            'priority' => 'high', 'status' => 'open', 'takes_room_out_of_service' => true,
            'reported_by' => $reporter->id,
        ]);
        // A ticket that does NOT take the room out of service must not count.
        MaintenanceTicket::create([
            'room_id' => $room->id, 'title' => 'Squeaky door', 'description' => 'Minor noise',
            'priority' => 'low', 'status' => 'open', 'takes_room_out_of_service' => false,
            'reported_by' => $reporter->id,
        ]);

        $dutyManager = $this->userWithRole('duty_manager');

        $this->assertSame(1, $this->service()->forUser($dutyManager)['maintenance']['count']);
    }

    public function test_purchase_orders_awaiting_approval_are_counted(): void
    {
        PurchaseOrder::create([
            'po_number' => 'PO-1', 'supplier_id' => Supplier::factory()->create()->id,
            'status' => 'submitted', 'ordered_by' => User::factory()->create()->id,
            'submitted_at' => now(),
        ]);

        $accountant = $this->userWithRole('accountant');
        $frontDesk = $this->userWithRole('front_desk');

        $this->assertSame(1, $this->service()->forUser($accountant)['purchase_orders']['count']);
        $this->assertArrayNotHasKey('purchase_orders', $this->service()->forUser($frontDesk));
    }

    public function test_stock_requisitions_awaiting_approval_are_counted(): void
    {
        $department = Department::create(['name' => 'Bar', 'code' => 'BAR', 'is_active' => true]);

        StockRequisition::create([
            'requisition_number' => 'REQ-1', 'department_id' => $department->id,
            'status' => 'pending', 'requested_by' => User::factory()->create()->id,
        ]);

        $dutyManager = $this->userWithRole('duty_manager');

        $this->assertSame(1, $this->service()->forUser($dutyManager)['requisitions']['count']);
    }

    public function test_wastage_awaiting_approval_is_counted(): void
    {
        $product = Product::factory()->create(['unit_id' => Unit::factory()->create()->id]);

        StockWastage::create([
            'wastage_number' => 'WST-1', 'product_id' => $product->id, 'quantity' => 3,
            'reason' => 'Dropped and broken', 'status' => 'pending',
            'recorded_by' => User::factory()->create()->id, 'recorded_at' => now(),
        ]);

        $dutyManager = $this->userWithRole('duty_manager');

        $this->assertSame(1, $this->service()->forUser($dutyManager)['wastage']['count']);
    }

    public function test_leave_requests_awaiting_approval_are_counted(): void
    {
        $leaveType = LeaveType::create([
            'name' => 'Annual Leave', 'code' => 'ANNUAL', 'is_paid' => true,
            'max_days_per_year' => 18, 'requires_approval' => true, 'is_active' => true,
        ]);
        $employee = User::factory()->create();

        LeaveRequest::create([
            'user_id' => $employee->id, 'leave_type_id' => $leaveType->id,
            'start_date' => now()->addDays(5)->toDateString(), 'end_date' => now()->addDays(7)->toDateString(),
            'days_requested' => 3.0, 'reason' => 'Family event', 'status' => 'pending',
            'requested_by' => $employee->id,
        ]);

        $dutyManager = $this->userWithRole('duty_manager');
        $cashier = $this->userWithRole('cashier');

        $this->assertSame(1, $this->service()->forUser($dutyManager)['leave']['count']);
        $this->assertArrayNotHasKey('leave', $this->service()->forUser($cashier));
    }

    public function test_draft_payroll_runs_are_counted(): void
    {
        PayrollRun::create([
            'period_start' => now()->startOfMonth()->toDateString(),
            'period_end' => now()->startOfMonth()->addDays(9)->toDateString(),
            'status' => 'draft',
        ]);

        // Per RolesAndPermissionsSeeder, approve-payroll-run is currently
        // held only by owner/general_manager (via the full $all grant) —
        // accountant holds manage-payroll/pay-payroll-run but not the
        // approve permission itself, so this widget only ever appears for
        // Owner/GM. That is a plausible deliberate control (payroll
        // sign-off reserved above Accountant) rather than a dead end like
        // the purchase-orders/requisitions fixes above: accountant can
        // still reach payroll-runs.index via manage-payroll, so nothing is
        // unreachable — it is simply not delegated. Documented here rather
        // than "fixed" since PHASE9_AUDIT_AND_PLAN.md does not call for
        // widening who approves payroll.
        $owner = $this->userWithRole('owner');
        $accountant = $this->userWithRole('accountant');

        $this->assertSame(1, $this->service()->forUser($owner)['payroll']['count']);
        $this->assertArrayNotHasKey('payroll', $this->service()->forUser($accountant));
    }
}
