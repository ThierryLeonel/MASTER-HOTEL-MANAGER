<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

/**
 * The exact eight roles from spec section 8, plus the Phase-1 permission
 * set (foundation + front office). Later phases (POS, inventory, finance,
 * HR) will extend $permissions and the per-role grants below rather than
 * introducing a parallel authorization mechanism.
 */
class RolesAndPermissionsSeeder extends Seeder
{
    public const ROLES = [
        'owner', 'general_manager', 'duty_manager', 'accountant',
        'cashier', 'front_desk', 'bar_staff', 'restaurant_staff',
        // 'bar_outside_staff'/'bucareaux_staff' (post-9.8 second fix
        // round): the two new separately-run sales points get their own
        // roles, mirroring bar_staff/restaurant_staff exactly — see
        // database/migrations/2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php.
        'bar_outside_staff', 'bucareaux_staff',
        // New requirement 5 ("Marketing Manager role and dashboard"): a
        // front-office-facing management role that can create/approve
        // reservations, discounts, maintenance, and housekeeping, and
        // receive guest payments — but explicitly never sells/settles POS
        // orders, manages the menu, or sells inventory (see this role's
        // grant list below, which deliberately omits every
        // 'operate-*-pos'/'manage-menu'/'manage-products' permission).
        'marketing_manager',
    ];

    public const PERMISSIONS = [
        // Foundation
        'manage-users', 'manage-settings', 'view-audit-log', 'manage-departments',
        // Front Office
        'manage-guests', 'manage-reservations', 'approve-reservation',
        // 'view-rooms' (Phase 9.0) is deliberately separate from
        // 'manage-rooms': every role that needs to see the room/occupancy
        // board (to check guests in, plan housekeeping, etc.) should not
        // automatically also be able to add/edit rooms.
        'view-rooms', 'manage-rooms', 'checkin-guest', 'checkout-guest',
        // 'update-room-status' (post-9.8 fix round): a receptionist needs
        // to flip a room's status (e.g. mark it out of service, or clean)
        // straight from the room board without being able to add/edit
        // rooms outright — kept separate from 'manage-rooms' for the same
        // reason 'view-rooms' already is (see the comment above it).
        'update-room-status',
        'post-folio-charge', 'post-folio-payment', 'void-folio-transaction',
        // 'record-payment' (post-9.8 fix round, new general-purpose
        // Payments module): lets a role record an arbitrary payment not
        // tied to a folio/order/expense/payroll (e.g. rent from a
        // visitor) via PaymentLedgerService directly. Deliberately
        // separate from 'post-folio-payment' (which only ever posts
        // against a guest's folio) since this is a broader capability.
        'record-payment',
        'manage-housekeeping', 'report-maintenance', 'approve-maintenance', 'resolve-maintenance',
        // POS (Bar/Restaurant/Front Desk — Front Desk added post-9.7.4 for
        // receptionist petty sales: juice, water, snacks, biscuits, etc.,
        // via the same outlet-parametrized POS module, see PHASE9_AUDIT_AND_PLAN.md §8)
        'manage-menu', 'operate-bar-pos', 'operate-restaurant-pos', 'operate-front_desk-pos',
        // 'operate-bar_outside-pos'/'operate-bucareaux-pos' (post-9.8
        // second fix round): must be listed here, not only in the
        // per-role grants below — Permission rows are only ever created
        // from this array (see run(), foreach self::PERMISSIONS), so a
        // permission referenced in a grant but missing from this array
        // throws Spatie\Permission\Exceptions\PermissionDoesNotExist at
        // syncPermissions() time. This omission was the cause of the
        // "There is no permission named `operate-bar_outside-pos`" error
        // seen when first deploying this round.
        'operate-bar_outside-pos', 'operate-bucareaux-pos',
        // Inventory
        'manage-products', 'manage-suppliers',
        'manage-purchase-orders', 'approve-purchase-order', 'receive-goods',
        'request-stock', 'approve-requisition',
        'manage-stock-count', 'record-wastage', 'approve-wastage',
        // Finance
        'manage-expenses', 'approve-expense', 'approve-large-expense', 'pay-expense',
        'request-refund', 'approve-refund',
        'record-cash-reconciliation', 'lock-accounting-day',
        // HR & Payroll
        'manage-employees', 'clock-own-attendance', 'record-attendance',
        'request-leave', 'approve-leave',
        'manage-payroll', 'approve-payroll-run', 'pay-payroll-run',
        // Reporting
        'view-financial-reports', 'view-operational-reports',
        'view-inventory-reports', 'view-hr-reports',
        // Dashboards
        'view-dashboard-owner', 'view-dashboard-gm', 'view-dashboard-duty-manager',
        'view-dashboard-accountant', 'view-dashboard-front-desk',
        'view-dashboard-bar', 'view-dashboard-restaurant', 'view-dashboard-cashier',
        // Sync (Phase 9.7.6): the sync dashboard — device status and
        // conflict resolution. One permission for the whole screen,
        // matching how manage-settings/manage-users gate their own
        // single admin screens.
        'manage-sync',
        // "Special guest lodging" (added post-9.7.6 — PHASE9_AUDIT_AND_PLAN.md
        // §9): waiving a stay's room charge is a bigger revenue decision
        // than a POS item comp, so it gets its own permission rather than
        // riding along on manage-guests/checkout-guest (which front_desk
        // already holds unconditionally).
        'mark-stay-complimentary',
        // Cash disbursement (post-9.8 second fix round, PHASE9_AUDIT_AND_PLAN.md
        // §18): 'authorize-cash-disbursement' is the management-level act
        // of creating a disbursement ticket (a pre-authorization);
        // 'record-cash-disbursement' is the accountant/cashier act of
        // actually paying cash out against a ticket, or — when there is
        // no ticket — recording the disbursement with the authorizing
        // superior's details captured directly on it. Deliberately two
        // permissions, matching every other approve/execute split already
        // in this seeder (expenses, refunds, purchase orders, payroll).
        'authorize-cash-disbursement', 'record-cash-disbursement',
        // Shift-based department cash reconciliation (post-9.8 second fix
        // round, §19): each department's own staff open/close their own
        // shift and declare their counted cash for it — separate from
        // 'record-cash-reconciliation', which stays the accountant/
        // cashier's single whole-hotel end-of-day reconciliation.
        'manage-own-shift', 'view-shift-reports',
        // Gym & Swimming Pool (post-9.8 second fix round, §20): front desk
        // grants free access to checked-in guests and sells paid access
        // (gym registration / a pool slot) to walk-ins; 'manage-facilities'
        // is the separate, higher permission for configuring the
        // facilities and their rates.
        'grant-facility-access', 'manage-facilities',
        // Financial accounts & client debt (third post-9.8 fix round,
        // PHASE9_AUDIT_AND_PLAN.md §21-22): 'manage-financial-accounts'
        // configures the Cash/Bank/MTN/Orange (etc.) account list and
        // reconciles each one — Owner/GM/Accountant only, mirroring who
        // already handles the whole-hotel daily cash reconciliation.
        // 'manage-accounts-receivable' views and settles outstanding
        // guest/company debt — a broader group, since front desk and
        // cashier are the ones most likely to actually take a returning
        // debtor's payment.
        'manage-financial-accounts', 'manage-accounts-receivable',
        // Seventh post-9.8 fix round, follow-up ("I NEED A BUTTON IN THE
        // MASTER ADMIN THAT CAN RESET ALL DATA"): the single most
        // destructive action in this whole system — wipes every guest,
        // reservation, stay, folio, payment, expense, and every other
        // piece of operational history, permanently. Deliberately its
        // own permission, NOT folded into 'manage-settings' or any
        // existing admin permission, and deliberately excluded from
        // General Manager's grant below even though GM otherwise holds
        // every permission in this array except 'manage-own-shift' — see
        // that exclusion's own comment. Only Owner holds this (via $all
        // below, and redundantly via the Gate::before bypass every
        // permission check already grants Owner regardless).
        'manage-system-data',
        // Feature 6 ("Approved discounts on check-in") / audited defect
        // #6: the one permission that makes a user an eligible discount
        // approver (DiscountApprovalService::ELIGIBLE_ROLES lists the
        // ROLE names for the "Discount approved by" dropdown, but this
        // permission — held by exactly those same roles below — is what
        // actually gates every approval action).
        'approve-discount',
        // New requirement 5 ("define/complete housekeeping approval
        // workflow if missing"): separate from 'manage-housekeeping', same
        // reasoning as Maintenance's report/approve split.
        'approve-housekeeping',
        // Feature 2 (consolidated guest transaction history report):
        // deliberately its own permission, not folded into
        // 'manage-reservations' or 'manage-guests' — this report exposes
        // a guest's full financial history across every stay, and per the
        // spec is restricted to Manager/Duty Manager/Accountant/Marketing
        // Manager/Owner specifically (see the grants below), not every
        // role that can otherwise view guests or reservations.
        'view-guest-history',
        // New requirement 5: the Marketing Manager dashboard.
        'view-dashboard-marketing-manager',
        // Feature 3 ("one complimentary breakfast per occupied unit per
        // business date"): reversing an already-served entitlement
        // (correcting a mistaken redemption, restoring the stock it
        // consumed) needs a management-level permission distinct from
        // 'operate-restaurant-pos' itself — a server never approves their
        // own correction. Deliberately NOT reusing 'approve-wastage':
        // that permission is intentionally held by NOBODY (the wastage
        // module was hidden from every role, including Owner, in an
        // earlier fix round — see the $wastagePermissions comment below),
        // so gating this on it would make the reversal action permanently
        // unreachable by anyone. A genuinely new permission here, granted
        // to Owner/General Manager/Duty Manager below.
        'reverse-breakfast-entitlement',
    ];

    public function run(): void
    {
        foreach (self::PERMISSIONS as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        foreach (self::ROLES as $roleName) {
            Role::firstOrCreate(['name' => $roleName, 'guard_name' => 'web']);
        }

        // Wastage module hide (post-9.8 fix round, "there is not supposed
        // to be any wastage recording or module"): rather than deleting
        // the table/model/controller/routes (which would destroy any
        // wastage history already recorded), 'record-wastage' and
        // 'approve-wastage' are simply excluded from every role's grant
        // below — including Owner/General Manager, who would otherwise
        // hold them automatically via $all. With nobody holding either
        // permission, the wastage routes 403 for everyone and the nav's
        // "Wastage" link (gated @canany(['record-wastage','approve-wastage']))
        // never renders — the module is fully hidden without touching its
        // data. The permission rows themselves stay in PERMISSIONS/the
        // database (and the migration/model/data are untouched) so this
        // can be reversed later just by adding them back to a grant list.
        $wastagePermissions = ['record-wastage', 'approve-wastage'];
        $all = array_values(array_diff(self::PERMISSIONS, $wastagePermissions));

        $grants = [
            'owner' => $all,
            // Fourth post-9.8 fix round ("let shifts not exist for the
            // accountant, and the manager and the duty manager"): the
            // shift concept — opening/closing a department till — only
            // ever made sense for Front Desk/Bar/Restaurant/Bar Outside/
            // Bucareaux and the other front-line roles that also hold it
            // (Cashier). Owner, General Manager, Duty Manager, and
            // Accountant are back-office roles that never operate a
            // counter themselves, so `manage-own-shift` is withheld from
            // all but Owner here — they keep `view-shift-reports` (see
            // each grant list below), so nothing about REVIEWING every
            // department's shifts changes, only the ability to open one
            // of their own, which they never had a real use for.
            // 'manage-system-data' (seventh post-9.8 fix round, follow-up)
            // is excluded here too, alongside 'manage-own-shift' — the
            // full-data-reset button is deliberately Owner-only, not
            // "every back-office role" like the rest of this array. See
            // that permission's own comment in PERMISSIONS above.
            'general_manager' => array_values(array_diff($all, ['manage-own-shift', 'manage-system-data'])),
            'duty_manager' => [
                'approve-reservation', 'manage-reservations', 'manage-guests',
                'view-rooms', 'manage-rooms', 'update-room-status', 'checkin-guest', 'checkout-guest',
                'post-folio-charge', 'post-folio-payment', 'void-folio-transaction', 'record-payment',
                'manage-housekeeping', 'report-maintenance', 'approve-maintenance', 'resolve-maintenance',
                'manage-menu', 'operate-bar-pos', 'operate-restaurant-pos', 'operate-front_desk-pos',
                'operate-bar_outside-pos', 'operate-bucareaux-pos',
                'manage-products', 'manage-suppliers', 'manage-purchase-orders', 'receive-goods',
                'approve-requisition', 'manage-stock-count',
                'manage-expenses', 'approve-expense', 'pay-expense', 'request-refund', 'record-cash-reconciliation',
                'authorize-cash-disbursement', 'record-cash-disbursement',
                // Fourth post-9.8 fix round: 'manage-own-shift' removed —
                // Duty Manager still holds 'view-shift-reports' below, so
                // reviewing every department's shifts is unaffected.
                'view-shift-reports', 'grant-facility-access', 'manage-facilities',
                'manage-accounts-receivable',
                // Sixth post-9.8 fix round ("the accountant, manager,
                // should have the right to create new account and or
                // modify account") — Duty Manager was the one role that
                // still could NOT create/edit a FinancialAccount
                // (Accountant already held this from the third post-9.8
                // round; General Manager and Owner already hold every
                // permission except manage-own-shift). This closes that
                // gap.
                'manage-financial-accounts',
                // HR access (attendance/leave, both self-service and
                // manage-others) was deliberately removed from Duty
                // Manager here — see the "HR visibility restriction"
                // note above the accountant/cashier/front_desk/bar_staff/
                // restaurant_staff grants below. Only Owner, General
                // Manager, and Accountant should see or touch HR now.
                'view-operational-reports', 'view-inventory-reports',
                'view-dashboard-duty-manager',
                'manage-sync', 'mark-stay-complimentary',
                // Feature 6 / defect #6 and new requirement 5's
                // housekeeping-approval workflow: Duty Manager is an
                // eligible discount approver and housekeeping approver,
                // same as General Manager/Owner (held automatically via
                // $all) and Marketing Manager (granted explicitly below).
                'approve-discount', 'approve-housekeeping',
                // Feature 3: same management-correction tier as the
                // discount/housekeeping approvals just above.
                'reverse-breakfast-entitlement',
                // Feature 2 (consolidated guest transaction history):
                // Manager/Duty Manager/Accountant/Marketing Manager/Owner
                // only, per spec.
                'view-guest-history',
            ],
            // HR visibility restriction (post-9.8 fix round, "view of
            // HR"): only Owner, General Manager, and Accountant should
            // see or use any part of the HR & Payroll module — including
            // the previously-universal self-service `clock-own-attendance`
            // / `request-leave` permissions (spec section 23's original
            // "every role gets these" design is superseded by this
            // decision). Duty Manager, Cashier, Front Desk, Bar Staff, and
            // Restaurant Staff no longer hold any HR permission at all, so
            // the `hr.*` routes 403 for them and the nav's "HR & Payroll"
            // section (resources/views/layouts/app.blade.php) never
            // renders for them. If this hotel still needs those roles to
            // clock in/out, that now has to happen some other way (a
            // physical time clock, or a manager recording it for them via
            // `record-attendance`, which is Owner/GM-only below).
            'accountant' => [
                'view-audit-log', 'post-folio-payment', 'void-folio-transaction',
                'approve-purchase-order',
                // Follow-up round ("the accountant is should be able to
                // make expense entries too, disbursement entries, payment
                // entries") — the accountant could already approve/pay an
                // expense but never RAISE one; that's 'manage-expenses',
                // added here. Safe against the accountant approving their
                // own spend: ExpenseService::approve()'s self-approval
                // guard (`(int) $expense->requested_by === (int)
                // $actor->id`) is a per-user identity check, not a
                // role-based one, so an accountant who raises an expense
                // still cannot approve that same one themselves — a
                // different accountant, or GM/Owner, has to.
                'manage-expenses',
                'approve-expense', 'approve-large-expense', 'pay-expense', 'approve-refund', 'lock-accounting-day',
                'clock-own-attendance', 'request-leave', 'manage-payroll', 'pay-payroll-run',
                'view-financial-reports', 'view-inventory-reports', 'view-hr-reports',
                'view-dashboard-accountant',
                // Cash disbursement / shift reconciliation (post-9.8 second
                // fix round): the accountant is the one who actually
                // records cash going out (ticketed or not) and reviews the
                // department shift/reconciliation reports. Deliberately no
                // 'manage-own-shift' here (fourth post-9.8 fix round) — the
                // accountant reviews shifts, never opens one of their own.
                // ('authorize-cash-disbursement'/'record-cash-disbursement'
                // ARE the "disbursement entries" the same follow-up
                // request asked for — already granted here since this
                // round's second fix, so no change needed for that part.)
                'authorize-cash-disbursement', 'record-cash-disbursement', 'view-shift-reports',
                // Third post-9.8 fix round (PHASE9_AUDIT_AND_PLAN.md §21-22).
                'manage-financial-accounts', 'manage-accounts-receivable',
                // Follow-up round: "payment entries" — the general-purpose
                // Payments module (rent, a one-off visitor payment; see
                // PaymentController's own docblock) had never included the
                // accountant, even though recording that kind of income is
                // squarely accountant work.
                'record-payment',
                // Feature 2 (consolidated guest transaction history):
                // Manager/Duty Manager/Accountant/Marketing Manager/Owner
                // only, per spec.
                'view-guest-history',
            ],
            'cashier' => [
                'post-folio-payment', 'record-cash-reconciliation', 'pay-expense',
                'view-dashboard-cashier',
                'record-cash-disbursement', 'manage-own-shift', 'view-shift-reports',
                'manage-accounts-receivable',
            ],
            'front_desk' => [
                'manage-guests', 'manage-reservations',
                // Fourth post-9.8 fix round ("make sure the front desk
                // have the right to approve reservation too") — front
                // desk already creates reservations (manage-reservations)
                // and now can also approve/reject them, the same combination
                // Duty Manager already holds with no separate self-approval
                // guard (no such guard exists for reservations anywhere in
                // this system today, unlike expenses/refunds/leave).
                'approve-reservation',
                'view-rooms', 'update-room-status', 'checkin-guest', 'checkout-guest',
                'post-folio-charge', 'post-folio-payment', 'record-payment', 'manage-housekeeping', 'report-maintenance', 'request-refund',
                // Petty sales (juice, water, snacks, biscuits, etc.) at the
                // front desk counter — added post-9.7.4, see PHASE9_AUDIT_AND_PLAN.md §8.
                'operate-front_desk-pos',
                'view-dashboard-front-desk',
                // Shift handover for the front desk's own cash drawer, and
                // granting/selling Gym & Pool access — post-9.8 second fix
                // round, see PHASE9_AUDIT_AND_PLAN.md §19-20.
                'manage-own-shift', 'grant-facility-access',
                // A returning guest paying off old debt is usually taken
                // at the front desk — third post-9.8 fix round, §22.
                'manage-accounts-receivable',
            ],
            'bar_staff' => [
                'report-maintenance', 'operate-bar-pos', 'view-dashboard-bar',
                'request-stock', 'manage-own-shift',
            ],
            'restaurant_staff' => [
                'report-maintenance', 'operate-restaurant-pos', 'view-dashboard-restaurant',
                'request-stock', 'manage-own-shift',
            ],
            // 'bar_outside_staff'/'bucareaux_staff' (post-9.8 second fix
            // round): mirror bar_staff/restaurant_staff exactly, for the
            // two new separately-run sales points.
            'bar_outside_staff' => [
                'report-maintenance', 'operate-bar_outside-pos', 'request-stock', 'manage-own-shift',
            ],
            'bucareaux_staff' => [
                'report-maintenance', 'operate-bucareaux-pos', 'request-stock', 'manage-own-shift',
            ],
            // New requirement 5 ("Marketing Manager role and dashboard").
            // Deliberately mapped onto EXISTING permission identifiers
            // wherever one already covers the described capability (no
            // duplicate/parallel permission was invented for anything
            // this role shares with an existing role) — see this
            // permission's own docblock above for the exceptions that did
            // need a new permission (approve-discount, approve-
            // housekeeping, view-guest-history, view-dashboard-marketing-
            // manager). Explicitly and deliberately EXCLUDES every
            // 'operate-*-pos', 'manage-menu', 'manage-products', and
            // 'manage-suppliers'/purchase-order permission — Marketing
            // Manager may receive/record a guest payment or deposit, but
            // that must never imply POS selling/order-creation/
            // settlement/menu/inventory-selling rights (spec requirement,
            // tested explicitly — see MarketingManagerTest).
            'marketing_manager' => [
                'manage-reservations', 'approve-reservation',
                'view-rooms', 'update-room-status',
                'report-maintenance', 'approve-maintenance',
                'manage-housekeeping', 'approve-housekeeping',
                'approve-discount',
                'post-folio-payment', 'record-payment',
                'view-guest-history',
                'manage-guests',
                'view-dashboard-marketing-manager',
            ],
        ];

        foreach ($grants as $roleName => $permissions) {
            Role::findByName($roleName)->syncPermissions($permissions);
        }

        // Follow-up fix ("the front desk can still not update reservation
        // status") — traced to a real, previously-latent bug: this seeder
        // changing what a role can do has NEVER cleared Spatie's own
        // permission cache. Spatie caches the whole role->permission map
        // (config/permission.php's `cache.expiration_time`, 24 hours by
        // default) so that `$user->can(...)` doesn't re-query the
        // database on every single request — but that means every prior
        // round's "re-run RolesAndPermissionsSeeder after deploying" only
        // ever updated the DATABASE row; the live site kept answering
        // permission checks from the STALE cached map until that cache
        // separately expired or was cleared some other way. This was
        // already suspected once before (the diagnostic `hms:check-access`
        // console command, added in an earlier round specifically because
        // of this exact class of report, already knows to force-clear it
        // before checking) but this seeder itself was never fixed to
        // self-clear — so the same "I re-ran the seeder and it's still
        // not working" report could recur for literally any future
        // permission change, not just this one. Clearing it here, once,
        // right after every sync above, means a plain `php artisan
        // db:seed --class=RolesAndPermissionsSeeder --force` is now
        // sufficient on its own — no separate `permission:cache-reset`
        // step to remember, ever again.
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
    }
}
