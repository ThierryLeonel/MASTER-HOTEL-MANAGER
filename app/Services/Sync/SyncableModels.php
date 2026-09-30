<?php

namespace App\Services\Sync;

use App\Models\AccountingDay;
use App\Models\AccountsReceivable;
use App\Models\AttendanceRecord;
use App\Models\AuditLog;
use App\Models\CashDisbursement;
use App\Models\CashReconciliation;
use App\Models\ChargeType;
use App\Models\ChargeTypeRate;
use App\Models\Company;
use App\Models\Department;
use App\Models\DisbursementTicket;
use App\Models\EmployeeProfile;
use App\Models\Expense;
use App\Models\Facility;
use App\Models\FacilityAccess;
use App\Models\FacilityRate;
use App\Models\FinancialAccount;
use App\Models\FinancialAccountReconciliation;
use App\Models\Folio;
use App\Models\FolioTransaction;
use App\Models\GoodsReceipt;
use App\Models\GoodsReceiptItem;
use App\Models\Guest;
use App\Models\HousekeepingTask;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\LeaveType;
use App\Models\MaintenanceTicket;
use App\Models\MenuCategory;
use App\Models\MenuItem;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Payment;
use App\Models\PayrollDeductionBracket;
use App\Models\PayrollDeductionType;
use App\Models\PayrollRun;
use App\Models\Payslip;
use App\Models\PayslipLine;
use App\Models\PosTable;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\PurchaseOrderItem;
use App\Models\RecipeItem;
use App\Models\RefundRequest;
use App\Models\Reservation;
use App\Models\Room;
use App\Models\RoomCategory;
use App\Models\Shift;
use App\Models\Stay;
use App\Models\StockBatch;
use App\Models\StockCount;
use App\Models\StockCountItem;
use App\Models\StockMovement;
use App\Models\StockRequisition;
use App\Models\StockRequisitionItem;
use App\Models\StockWastage;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;

/**
 * The single source of truth for which models participate in the sync
 * engine. Started as exactly the 48-table list from Phase 9.7.1's
 * `add_ulid_to_syncable_tables` migration (see that migration's docblock
 * for the full "why these 48 and not, e.g., Setting or the permission
 * tables" rationale), extended since by every new business-data model
 * each fix round has added (each given its own `ulid` column in its own
 * CREATE migration, per this project's established pattern) — kept here
 * as one canonical PHP list so the outbox scan
 * (SyncService::buildOutboxBatch()), the ulid backfill command, and any
 * future caller all read from the same place instead of each keeping
 * their own copy that could silently drift out of sync with the
 * migrations.
 */
class SyncableModels
{
    /**
     * @var list<class-string<\Illuminate\Database\Eloquent\Model>>
     */
    public const ALL = [
        AccountingDay::class,
        AccountsReceivable::class,
        AttendanceRecord::class,
        AuditLog::class,
        CashDisbursement::class,
        CashReconciliation::class,
        ChargeType::class,
        ChargeTypeRate::class,
        Company::class,
        Department::class,
        DisbursementTicket::class,
        EmployeeProfile::class,
        Expense::class,
        Facility::class,
        FacilityAccess::class,
        FacilityRate::class,
        FinancialAccount::class,
        FinancialAccountReconciliation::class,
        Folio::class,
        FolioTransaction::class,
        GoodsReceipt::class,
        GoodsReceiptItem::class,
        Guest::class,
        HousekeepingTask::class,
        LeaveBalance::class,
        LeaveRequest::class,
        LeaveType::class,
        MaintenanceTicket::class,
        MenuCategory::class,
        MenuItem::class,
        Order::class,
        OrderItem::class,
        Payment::class,
        PayrollDeductionBracket::class,
        PayrollDeductionType::class,
        PayrollRun::class,
        Payslip::class,
        PayslipLine::class,
        PosTable::class,
        Product::class,
        PurchaseOrder::class,
        PurchaseOrderItem::class,
        RecipeItem::class,
        RefundRequest::class,
        Reservation::class,
        Room::class,
        RoomCategory::class,
        Shift::class,
        Stay::class,
        StockBatch::class,
        StockCount::class,
        StockCountItem::class,
        StockMovement::class,
        StockRequisition::class,
        StockRequisitionItem::class,
        StockWastage::class,
        Supplier::class,
        Unit::class,
        User::class,
    ];

    public static function isSyncable(string $class): bool
    {
        return in_array($class, self::ALL, true);
    }

    /**
     * Security re-audit finding (PHASE9_AUDIT_AND_PLAN.md §9.8): `User` is
     * syncable like every other business record (see the class docblock —
     * "every table ... except settings"), but its login-credential columns
     * are a different kind of data than a guest name or a folio balance.
     * `SyncService::exportableAttributes()` — the single choke point that
     * builds every outbound sync payload and every conflict snapshot — asks
     * here which attributes must never leave the installation that owns
     * them, per model class.
     *
     * For `User`, that's every column that either (a) is a credential a
     * compromised `sync_devices.shared_secret` could otherwise use to take
     * over an account (`password`, `pin_hash`, `totp_secret`,
     * `totp_recovery_codes`, `remember_token`), or (b) is PIN-lockout state
     * that only makes sense locally to whichever installation the failed
     * attempts happened against (`failed_pin_attempts`, `pin_locked_until`).
     * All of these columns are nullable/defaulted (see
     * `0001_01_01_000000_create_users_table.php`), so a `User` row created
     * or updated via sync without them is always valid — it simply means a
     * newly-synced account has no credentials configured on the receiving
     * installation until a local admin sets one there, which is the
     * intended, secure default: credentials are managed per-installation,
     * not silently replicated by whichever side pushes first.
     *
     * `is_active`/`department_id`/`name`/etc. deliberately still sync
     * normally — e.g. a Duty Manager disabling a terminated employee's
     * account on one installation should still lock them out of the other.
     */
    public const PROTECTED_ATTRIBUTES = [
        User::class => [
            'password', 'pin_hash', 'totp_secret', 'totp_recovery_codes',
            'remember_token', 'failed_pin_attempts', 'pin_locked_until',
        ],
    ];

    /**
     * @return list<string>
     */
    public static function protectedAttributesFor(string $class): array
    {
        return self::PROTECTED_ATTRIBUTES[$class] ?? [];
    }
}
