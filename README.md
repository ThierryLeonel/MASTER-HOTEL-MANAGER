# Hotel Management System (HMS)

A single-property Hotel Management System built on Laravel 12 + MySQL/MariaDB, designed to run on ordinary PHP/MySQL shared hosting (no Docker, no Redis, no permanently-running Node process).

**This README states plainly what is built, what is not, and what has and has not been executed/verified.** Nothing below claims a test result that wasn't actually produced by running the command shown.

---

## 1. Current status: Phase 9 (Hybrid HMS expansion) COMPLETE — all of 9.0–9.8, plus Front Desk petty sales, complimentary items/stays, a post-9.8 fix round (concurrency, ownership invariants, login enumeration, POS lifecycle), an HR visibility restriction, a large post-go-live "corrections" round (see `PHASE9_AUDIT_AND_PLAN.md` §14), a real-lodging-charges/deposits-as-payments/automatic-checkout round, removal of the room's separate clean/dirty housekeeping status, a printable-receipt redesign, a second post-9.8 fix round — two new POS outlets, cash disbursement, shift-based department cash reconciliation, Gym & Swimming Pool, a department reconciliation report, and every report made printable (see `PHASE9_AUDIT_AND_PLAN.md` §17 (§17.1-17.9)) — a third post-9.8 fix round — mandatory shift gating for five departments, reconciled financial accounts (Cash/Bank/MTN Mobile Money/Orange Money), required financial-account attribution on expenses/cash disbursements, and client debt settlement at the guest or company level (see `PHASE9_AUDIT_AND_PLAN.md` §18-19) — a fourth post-9.8 fix round — Accountant/General Manager/Duty Manager can no longer open a shift, a payroll double-payment concurrency bug found and fixed by a "money not double recorded" audit, Front Desk can now approve/reject reservations, and a new public, unauthenticated website booking-request page (no online payment — always lands as a `pending` reservation for staff to review) (see `PHASE9_AUDIT_AND_PLAN.md` §20) — a fifth post-9.8 fix round — a searchable-guest text box on the create-reservation screen, a real fix for the Spatie permission-cache bug that could leave a freshly granted permission invisible after a reseed, two new Accountant grants (`manage-expenses`, `record-payment`), and a genuine day-close & cash-reconciliation rebuild that gates locking the day on every real financial account (Cash/Bank/MTN/Orange) being reconciled for that day specifically, not just an old whole-hotel cash-only count (see `PHASE9_AUDIT_AND_PLAN.md` §20.8) — a sixth post-9.8 fix round — refund approval now requires a real financial account (closing the last payment path that could still post with a freely-typed method), and Duty Manager can now create financial accounts alongside Accountant, General Manager, and Owner (see `PHASE9_AUDIT_AND_PLAN.md` §20.9) — a sixth post-9.8 fix round follow-up — financial accounts can now actually be edited (not just created) and reactivated after being deactivated, with debt settlement confirmed already real-account-based (see `PHASE9_AUDIT_AND_PLAN.md` §20.10) — and a seventh post-9.8 fix round — the Deploy Helper gained two always-safe, individually re-runnable buttons (update roles & permissions, set up financial accounts) so a no-SSH host has a real way to apply either fix on a hotel that's already live (see `PHASE9_AUDIT_AND_PLAN.md` §20.11) — and a seventh post-9.8 fix round follow-up — an Owner-only Master Admin data screen with full backup, a spreadsheet data export, and a permanent reset of all operational data that's aware of this system's hybrid-sync design (see `PHASE9_AUDIT_AND_PLAN.md` §20.12) — added out of band — and, most recently, a full 34-defect correction round plus six new requirements (consolidated guest transaction history, one complimentary breakfast per unit per day, a 500 XAF room-delivery charge, a Marketing Manager role/dashboard, approved discounts on check-in, and the hotel-logo/favicon fix) audited against the original `hms-14.zip` baseline, including two real concurrency bugs found and fixed by a genuine two-process database test harness — see `CORRECTION_ROUND_34_DELIVERY.md` for the full change summary, migration/upgrade instructions, role-permission matrix, and honestly-disclosed test-execution limitations

Phases 1–8 (see below) delivered a complete, working, single-database Hotel Management System. A new master
prompt (Phase 9) expands the scope substantially: a local-server + cloud-VPS hybrid architecture with bidirectional
sync, complete Administration/Settings UI, management-style financial reporting, a role-aware dashboard, printable
documents, a data integrity checker, and hardened financial authorization. The full audit of what exists vs. what
Phase 9 requires, the architectural decisions that were resolved with the project owner, and the phased
implementation plan (9.0 through 9.8) all live in `PHASE9_AUDIT_AND_PLAN.md` — read that first for the current
state of this expansion.

**Phase 9.0** fixed four live authorization gaps found during the Phase 9 audit — `rooms.index`,
`stays.index`/`stays.show`, and `maintenance.index` had no permission gate at all (predating Phase 9, not
introduced by it), and `DashboardController` leaked a hotel-wide financial total to every role despite its own
docblock claiming role-specific dashboards. See the "Phase 9.0 Addendum" section appended to `SECURITY_AUDIT.md`
for the full writeup with evidence.

**Phase 9.2** gave Administration (Users/Roles), Departments, Settings, and Room Categories real admin screens for
the first time. (Phase 9.1 was the architectural-decision step itself, resolved with the project owner and
recorded in `PHASE9_AUDIT_AND_PLAN.md` §4 — there was no code to build for it.)

**Phase 9.3** added the two financial reports that were genuinely missing (Income Statement, Guest Stay History) —
the other four items on the master prompt's reporting checklist (Cash Flow, AR Aging, Revenue, Inventory) turned
out to already exist from Phase 6.

**Phase 9.4 (this delivery) is complete**: built a purely permission-driven "pending approvals" widget
(`PendingApprovalsService`, gated on the 9 `approve-*` permissions in the system, never on role name) and a
trailing-7-day financial summary, both wired into the dashboard; and the "Weekly Owner Summary" the master prompt
called for — a narrative rollup of occupancy, revenue, expenses, cash flow, and receivables for the last 7 days
(`WeeklyOwnerSummaryService`), reusing Phase 9.3's report services rather than recomputing anything. Verifying the
approvals widget's routes were all actually reachable also surfaced and fixed two real, pre-existing dead ends:
Accountant could approve a purchase order by POST but never see a page listing one to approve, and the identical
problem for Duty Manager and stock requisitions. See §13 below for the full changelog entry.

**Phase 9.5 (this delivery) is complete**: all 11 named printable documents from the master prompt's checklist
(reservation confirmation, guest invoice, final hotel bill, payment receipt, restaurant/bar receipt, daily cash
closing, payment summary, expense report, management report, weekly owner summary) now render through a new bare
`layouts/print.blade.php`, each reusing the exact permission its underlying screen already enforces. CSV export
(native PHP, no new dependency) is wired to the three genuinely list-shaped reports. PDF export was deliberately
**not** built as a Composer dependency — see §13 for the recorded decision; every browser's own "Print → Save as
PDF" already produces a real PDF from these same pages today.

**Phase 9.6 (this delivery) is complete**: a read-only `DataIntegrityCheckerService` runs 7 checks against the live
data — folio balances vs. their transaction ledger, Accounts Receivable rows tying to a real folio and never owing
more than they originally did, every stay having a folio, room status agreeing with whether it actually has an
active stay (both directions), no negative stock, and every purchase order marked "received" actually being fully
received — each one reusing an existing model method (`Folio::recomputeBalance()`, `PurchaseOrder::isFullyReceived()`)
rather than recomputing its own copy of the same logic. It never modifies data, only reports. Exposed as
`php artisan integrity:check` (a real cron/CI-usable command, non-zero exit on any failure) and as a new read-only
step on the deploy helper's page. See §13 for the full changelog entry.

**Phase 9.7 (in progress) — sub-phases 9.7.1–9.7.4 of 6 complete**: the hybrid sync engine (by far the largest
remaining item) was re-scoped into 6 concrete sub-phases before any code was written — see
`PHASE9_AUDIT_AND_PLAN.md` §6 for the full breakdown. Sub-phase 9.7.1, the highest-risk architectural piece, is
done: every table either the local server or the cloud VPS can independently write now has a globally-unique
`ulid` column (additive, 48 tables, zero existing primary/foreign keys touched), auto-assigned by a new
`HasSyncUlid` trait that never lets it be mass-assigned and always preserves a value already set. `Setting` is the
one model deliberately excluded from this rollout. Sub-phase 9.7.2 built the sync engine's own bookkeeping tables
— `sync_devices`, `sync_checkpoints`, `sync_batches`, `sync_items`, `sync_conflicts`, `sync_logs` — plus device
authentication (`DeviceAuthService`, reusing this codebase's existing `Hash::make()`/`Hash::check()` secret
convention) and `php artisan sync:register-device`. Sub-phase 9.7.3 built the engine itself, `SyncService`: an
outbox that scans all 48 syncable tables for rows changed since a device's last checkpoint, an idempotent apply
step that safely no-ops on a replayed batch, and a conflict rule that detects when a local row changed since the
last sync from a given device and — rather than guessing which side should win — leaves the local row untouched
and records both versions for a human to resolve later. Deletion propagation is deliberately conservative: only
the two soft-deletable models (Guest, User) propagate a delete automatically; everything else is skipped with a
plain reason rather than risking an automated hard delete of business data. A new `php artisan
sync:backfill-ulids` command assigns ulids to any rows that existed before 9.7.1's migration ran. Sub-phase 9.7.4
built the transport, but first corrected a real flaw found in 9.7.2's device design: a bcrypt hash can verify an
incoming secret but can never be retrieved to present on an outgoing call, which bidirectional sync needs —
`sync_devices` now stores one `shared_secret` per counterpart, encrypted (not hashed), and `sync:register-device`
gained `--import-key`/`--import-secret` so both installations' operators can agree on the same secret by hand
(the same trust model the deploy helper already uses for `DEPLOY_TOKEN`). Three new JSON endpoints —
`/sync/push`, `/sync/pull`, `/sync/acknowledge` — registered outside the normal `web` middleware group (no
session/CSRF, same reasoning as the deploy helper's own routes) let one installation exchange a batch with the
other; the controller itself has no sync logic of its own, only authentication and delegation to the
already-tested `SyncService`. Sub-phase 9.7.5 built the scheduled entry point that actually calls that transport:
`SyncRunner` drives both directions against one counterpart device — push (skipping the network call entirely
when there's nothing new to send, but still advancing the local checkpoint so the next run scans forward) and
pull (always calling through, even on an empty reply, so the counterpart's own checkpoint keeps advancing too) —
and `php artisan sync:run`, a thin cron-usable command that loops every active device with an `endpoint_url` and
exits non-zero on any device's failure, the same contract `integrity:check` already established. Sub-phase 9.7.6,
the last of Phase 9.7, closed the loop with a human-facing side: a permission-gated (`manage-sync`) dashboard
showing every registered device's status and last-sync times, a conflict-resolution screen backed by a new
`SyncConflictResolver` service (the only place a conflict can be resolved — kept-local, kept-incoming, merged, or
ignored), and an 8th `DataIntegrityCheckerService` check flagging any conflict left unresolved for more than 7
days, deliberately deferred from Phase 9.6 since `sync_conflicts` didn't exist yet at that point. See §13 for all
six changelog entries. **Phase 9.7 (the hybrid sync engine) is now fully complete.**

**Front Desk petty sales (added post-9.7.4, out of band with the sync-engine plan)**: at the user's request,
receptionists can now ring up small walk-up sales — bottled water, juice, soft drinks, biscuits, snacks — through
the existing Bar/Restaurant POS module rather than a new feature: `front_desk` is now a third valid `outlet` value
for that already outlet-parametrized module (`operate-front_desk-pos`, no table assignment since a reception
counter has no seating), and a real, pre-existing bug this surfaced — `OrderService` hardcoded folio-deferral
charges to exactly `bar_charge`/`restaurant_charge` — was fixed to generalize to any outlet. See
`PHASE9_AUDIT_AND_PLAN.md` §8 for the full design decision and evidence, and §13 for the changelog entry.

**Complimentary items/stays (added post-9.7.6, out of band with the sync-engine plan)**: at the user's request —
clarified to mean per-item, not per-order — a bar/restaurant/front-desk order can now carry a free line (e.g. a
welcome drink) alongside other, normally-billed items on the same order; and a stay can be marked complimentary,
waiving only its room charge while bar/restaurant/front-desk/other charges against that guest's folio still post
and get paid exactly as normal. Comping a POS item just needs a reason (same tier as the existing `removeItem()`
action); marking a whole stay complimentary is a bigger revenue decision, so it's gated behind a new
`mark-stay-complimentary` permission held only by duty_manager/GM/owner — deliberately not front_desk. See
`PHASE9_AUDIT_AND_PLAN.md` §9 for the full design decision and evidence, and §13 for the changelog entry.

**Phase 9.8 (documentation, a final security re-audit, and packaging) is now complete, closing out Phase 9
entirely.** The re-audit — following the same practice as Phase 7 and the Phase 9.0 addendum, fixing what it finds
rather than only describing it — covered everything built since the Phase 9.0 addendum (admin screens, reports,
dashboard, the hybrid sync engine, Front Desk POS, complimentary items/stays) and found and fixed 3 genuine gaps
plus 1 defensive hardening item: a cross-outlet IDOR in the POS controller (any outlet's staff could touch another
outlet's orders by substituting an order ID), a credential-takeover path in the sync engine (a compromised device
secret could rewrite another account's password/PIN/TOTP secret, and credential hashes could leak into the sync-
conflicts admin screen), unsanitized SVG accepted by the hotel-logo upload, and CSV/formula-injection hardening on
every report export. Five new documents were produced: `INSTALLATION.md`, `HYBRID_ARCHITECTURE.md`,
`USER_GUIDE.md`, `SECURITY.md` (this re-audit, consolidated with `SECURITY_AUDIT.md`'s earlier two passes), and
`TEST_REPORT.md` — plus an honest Readiness Rating in `PHASE9_AUDIT_AND_PLAN.md` §10. See that file's §9.8
writeup and §13 below for the full changelog entry.

Separately, §11 roadmap item 9 (end-to-end verification against a real live deployment) is not unfinished work so
much as a deliberate handoff: it requires a human with an actual deployed instance and real network/browser access
to that instance, neither of which exists here — the same honesty principle as `composer install`/`php artisan
test` (see §7 and `TEST_REPORT.md`) applies: it's named plainly rather than glossed over.

### Built in Phase 1 (foundation + front office)

- **Foundation**: users, departments, role/permission system (8 roles, spatie/laravel-permission), a database-backed `settings` table for every configurable business policy (tax rate, deposit %, expense threshold, session timeout, PIN lockout rules — nothing hardcoded), and an append-only `audit_logs` table written by every mutation.
- **Authentication**, exactly matching the three auth models the spec requires:
  - Password + TOTP (Owner, General Manager, Accountant) — real TOTP via `pragmarx/google2fa`, QR enrollment via `bacon/bacon-qr-code`, recovery codes, no plaintext secrets.
  - Password only (Duty Manager).
  - PIN with brute-force lockout (Cashier, Front Desk, Bar Staff, Restaurant Staff).
  - All three flows are rate-limited server-side; authorization is enforced via policies/permission middleware on every route, never by hiding a UI button.
- **Front Office**, the complete lifecycle: guest → reservation (pending → manager approval → confirmed, with an optional deposit/advance payment recorded through the same central payments ledger as every other payment) → check-in (walk-in or from reservation) — which posts the **full anticipated stay cost** (rate × nights) to the folio immediately and applies any reservation deposit as a credit, so the guest's true outstanding balance is visible from the moment they check in — → active stay with a real append-only folio ledger (room/bar/restaurant/other charges, payments, refunds, adjustments) → check-out, which automatically charges only a genuine extension beyond what was already charged (never re-charges, never auto-refunds an early departure) → **unpaid balance becomes Accounts Receivable and never blocks checkout** → housekeeping tasks → maintenance tickets with an approval gate before a room is actually taken out of service.
- Double-booking and double check-in are prevented with row-level locks inside DB transactions, not just application-level checks.
- Voiding a folio transaction posts a reversing entry; the original row is never edited or deleted.
- Role-scoped dashboard with live figures (occupancy, arrivals/departures, pending approvals, receivables, housekeeping backlog) — every number is a live query, nothing is a stored duplicate total.
- A full automated test suite (see §7) covering auth for all three modes, the reservation approval/rejection/cancellation/no-show lifecycle, check-in/checkout including the double-booking guard and the AR-on-checkout rule, folio posting/voiding, and audit log writes.

### Built in Phase 2 (POS — Bar & Restaurant)

- **Central payment ledger** (`payments` table, `App\Services\PaymentLedgerService`) introduced ahead of schedule (originally slated for the Finance phase) specifically so POS payments — and folio payments/refunds, retrofitted in the same pass — never become orphaned financial records outside a single source of truth. Every payment from every module now writes exactly one row here, per spec section 16.
- **Menu management**: categories and items per outlet (bar/restaurant), gated behind `manage-menu` (Duty Manager and above).
- **Order lifecycle**: `draft → confirmed → closed` (or `cancelled`), matching spec section 13 exactly:
  - While **draft**, items can be freely added or removed.
  - Once **confirmed**, existing lines are locked (removal is blocked server-side, not just hidden in the UI) but new lines can still be appended ("add-only") right up to close.
  - **Closing** requires either an immediate payment method (posts to the central ledger) or deferral to a checked-in guest's room folio — deferral is only permitted for a guest with an active `Stay` (enforced server-side; a reservation alone, or a checked-out guest, cannot defer a bar/restaurant bill to a room).
  - Table status (`available`/`occupied`) is tracked and released automatically on close or cancel.
- Bar/Restaurant staff are restricted to their own outlet's POS by permission (`operate-bar-pos` / `operate-restaurant-pos`) — a Bar Staff account gets a 403 attempting the Restaurant POS route, and vice versa, even though both outlets share one controller/service.
- Every lifecycle transition (`order_opened`, `item_added`, `item_removed`, `order_confirmed`, `order_closed`, `order_cancelled`) writes an audit log row.
- Role-aware Blade UI for the order board, an individual order screen, and menu management.
- Demo menu/table data (`PosSeeder`) and a dedicated test suite (`tests/Feature/Pos/OrderTest.php`, 7 test methods — execution NOT VERIFIED here, same caveat as Phase 1; see §7).

### Built in Phase 3 (Inventory + FIFO costing)

- **A single FIFO engine** (`App\Services\Inventory\StockService`) that every other inventory workflow is built on top of — nothing else is allowed to write to `stock_batches` or `stock_movements` directly. `receiveStock()` creates a new batch; `issueStock()` always consumes the **oldest open batch first**, computing the real cost of what was actually issued from what was actually paid for it, and throws a clean business-rule error (never a silent negative number) if stock on hand is insufficient.
- **Purchase orders**: `draft → submitted → approved → received` (or `rejected`/`cancelled`), approval separated from ordering by permission (`manage-purchase-orders` vs `approve-purchase-order`) so a Duty Manager can raise a PO but only an Accountant/GM/Owner signs off on the spend.
- **Goods receiving**: against an approved PO (supports partial deliveries spread across several receipts, tracked per line) or fully ad-hoc for a small cash purchase that never had a PO. Every receipt line creates one real FIFO batch.
- **Department stock requisitions**: `pending → approved` (approval performs the actual FIFO issue) or `rejected`/`cancelled`. Approval is deliberately **all-or-nothing across every line in one DB transaction** — if any single product on the requisition doesn't have enough stock, nothing on the requisition is issued, including lines that would have succeeded on their own.
- **Physical stock counts**: starting one snapshots the live system quantity for every selected product at that instant; completing it posts a real FIFO adjustment (an inbound batch for a positive variance, a FIFO issue for a negative one) for every line whose counted quantity differs from the system quantity — never a raw quantity overwrite.
- **Wastage**: recording wastage never by itself touches stock — only an approval does (same approval-gate shape as Phase 1's Maintenance Ticket), so a disputed or mis-keyed report is simply rejected rather than needing to be "undone."
- **POS ↔ Inventory link**: a `recipe_items` bill-of-materials table connects a menu item to the products it consumes. Closing a POS order now deducts real FIFO stock for every line whose menu item has a recipe configured — and **blocks the close outright** (inside the same DB transaction as the payment/folio posting, so nothing is charged if it fails) when there isn't enough stock to fulfill it. A menu item with no recipe configured is simply untracked, so every Phase 2 outlet keeps working exactly as before.
- New permissions (`manage-products`, `manage-suppliers`, `manage-purchase-orders`, `approve-purchase-order`, `receive-goods`, `request-stock`, `approve-requisition`, `manage-stock-count`, `record-wastage`, `approve-wastage`) granted per the updated matrix in §9.
- Role-aware Blade UI for products/units, suppliers, purchase orders, goods receipts, requisitions, stock counts, and wastage, plus a per-menu-item recipe editor under POS → Menu.
- `InventorySeeder`: units, two suppliers, six demo products each given a real opening stock batch (through `StockService`, not a raw quantity write), and two Phase 2 menu items linked to recipes so the POS-close → FIFO-deduction path has something real to exercise out of the box.
- A dedicated test suite (`tests/Unit/Inventory/StockServiceTest.php` + 5 more Feature test files — 19 test methods total — execution NOT VERIFIED here, same caveat as every phase before it; see §7).

### Built in Phase 4 (Finance completion)

- **Accounting-day open/lock model** (`App\Services\Finance\AccountingDayService`): the system never backdates a transaction — every posting happens "now" — so there is only ever one day that matters, today's, which auto-opens on first use. `assertOpen()` is the single enforcement point, and it lives inside `PaymentLedgerService::record()` (built in Phase 2), so it automatically covers every folio payment/refund and every POS payment without any of those call sites needing to remember to check. Locking is one-way by design — there is deliberately no `reopen()` — consistent with the system-wide rule that corrections are always new transactions, never edits to history (the same rule already applied to `folio_transactions`/`stock_movements`/`payments`).
- **Cash reconciliation** (`App\Services\Finance\CashReconciliationService`): `expected_amount` is always computed **live** from the central `payments` ledger (cash in minus cash out for the business date) — never accepted as user input — so a reconciliation is a genuine check against the ledger, not a form that could be filled in to agree with itself. Locking today's day requires today's reconciliation to already exist.
- **Expense approval workflow** (`App\Services\Finance\ExpenseService`): `pending → approved → paid` (or `rejected`/`cancelled`). The approval tier required is read from the configurable `expense_approval_threshold` setting **at approval time**, not frozen when the expense was requested — an expense at or above threshold additionally requires `approve-large-expense` (Accountant/GM/Owner), on top of the base `approve-expense` a Duty Manager also holds. Marking an approved expense paid is the one step that actually moves money — it posts through the same central `PaymentLedgerService` as every other module, and is therefore itself subject to the accounting-day lock.
- **Refund approval workflow** (`App\Services\Finance\RefundService`): recording a refund request never by itself touches the folio or any ledger — only approving one calls `FolioService::postRefund()` (a Phase 1 method whose docblock has said "requires approval upstream" since it was written), which posts both the folio adjustment and the central ledger entry in one DB transaction.
- New permissions (`manage-expenses`, `approve-expense`, `approve-large-expense`, `pay-expense`, `request-refund`, `approve-refund`, `record-cash-reconciliation`, `lock-accounting-day`) granted per the updated matrix in §9.
- Role-aware Blade UI: an Expenses screen (request/approve/reject/cancel/pay, with above-threshold rows visually flagged), a Refunds screen (request/approve/reject), a Day Close screen (record today's reconciliation, then lock), and a "Request refund" form added to the existing stay/folio screen.
- A dedicated test suite (`tests/Feature/Finance/ExpenseTest.php`, `RefundTest.php`, `AccountingDayTest.php` — 18 test methods total — execution NOT VERIFIED here, same caveat as every phase before it; see §7).

### Built in Phase 5 (HR & Payroll)

- **Employee profiles** (`employee_profiles`, one row per staff account, kept separate from authentication): job title, employment type, hire/termination dates, basic salary, and payment details. An employee with no profile — or a profile with no `basic_salary` set — is simply not payroll-eligible yet; nothing crashes, they're just skipped when payroll runs. Terminating an employee (`App\Services\Hr\EmployeeService::terminate()`) also deactivates their login in the same DB transaction, so a terminated employee can no longer sign in.
- **Attendance**: self clock-in/clock-out (`App\Services\Hr\AttendanceService`), one row per user per calendar day. A manager's manual correction and an approved leave request's automatic `on_leave` marking go through the exact same service, so there's a single writer for the whole table no matter which path touched it.
- **Leave workflow** (`App\Services\Hr\LeaveService`): `pending → approved` (deducts the balance and marks attendance for every date in range, atomically) or `rejected`/`cancelled` (touches neither) — the same approval-gate shape as Phase 1's Maintenance Ticket and Phase 3's Wastage. Leave types are configurable (name, paid/unpaid, days per year, spec section 23: never hardcoded); a type with `max_days_per_year = 0` (e.g. unpaid leave) is simply not balance-tracked. The balance check itself is read-only until approval — merely *requesting* leave that later gets rejected never creates or touches a balance record.
- **Configurable statutory deductions** (`App\Services\Hr\PayrollDeductionService`): a real, editable bracket engine, not a hardcoded tax formula. A deduction type is either `flat_percent` (a single rate on the whole amount, e.g. a social-security contribution) or `progressive_brackets` (a real progressive scale — only the slice of gross pay inside each band is taxed at that band's rate, exactly like a standard income-tax computation). Owner/GM add, edit, and deactivate both deduction types and their brackets from the UI.
- **Payroll run lifecycle** (`App\Services\Hr\PayrollService`): `draft → approved → paid`.
  - `generate()`/regenerate (draft only) builds one itemized payslip per active, payroll-eligible employee: basic salary, an unpaid-absence deduction computed from real `attendance_records` (`status = absent`) divided by the configurable `payroll_working_days_per_month` setting, every active statutory deduction, and the resulting net pay — all written as real `payslip_lines`, never a single opaque total.
  - `approve()` locks every payslip to `finalized` — nothing in an approved run can be regenerated; a correction belongs in the *next* run, never a rewrite of this one (the same "never edit history" rule as `folio_transactions`/`stock_movements`/`payments`).
  - `pay()` posts every payslip's net pay through the **same central `PaymentLedgerService`** every other module uses — which means payroll payout is subject to the accounting-day lock exactly like a folio or expense payment (see §9 for the deliberate separation of duties between who generates, who approves, and who pays a run).
- New permissions (`manage-employees`, `clock-own-attendance`, `record-attendance`, `request-leave`, `approve-leave`, `manage-payroll`, `approve-payroll-run`, `pay-payroll-run`) granted per the updated matrix in §9 — `clock-own-attendance` and `request-leave` are given to every one of the 8 roles, since clocking in and requesting leave are self-service actions for all staff, not a privileged one.
- Role-aware Blade UI: an Employees screen, an Attendance screen (self clock-in/out plus a manager's manual-entry form), a Leave screen (request/approve/reject/cancel plus leave-type configuration), a Deductions screen (deduction types and their brackets), a Payroll Runs list/detail screen (generate → approve → pay), and a self-service "My Payslips" screen open to every authenticated user regardless of permissions (scoped to their own records, never gated by a permission check, since ownership is the only rule that applies).
- `HrSeeder`: an employee profile for every Phase-1 demo user (so the payroll-run demo has real payroll-eligible employees immediately), three demo leave types, and two demo statutory deduction types — one flat-percent, one progressive-bracket — so both calculation paths have something real to exercise out of the box.
- A dedicated test suite (`tests/Feature/Hr/EmployeeTest.php`, `AttendanceTest.php`, `LeaveTest.php`, `PayrollTest.php`, `tests/Unit/Hr/PayrollDeductionServiceTest.php` — 28 test methods total — execution NOT VERIFIED here, same caveat as every phase before it; see §7).

### Built in Phase 6 (Reporting)

- **Five reporting services**, each reading live from the ledgers built in phases 1–5 rather than maintaining any duplicate stored total (spec section 24):
  - `App\Services\Reporting\FinancialReportService`: `revenueByChargeType()` (accrual revenue, from `folio_transactions`, excluding voided charges), `cashFlow()` (actual cash in/out, from the central `payments` ledger, split by `source_module`), `expenseSummary()` (requested vs. actually-paid, by category), and `receivablesAging()` (open Accounts Receivable bucketed into 0–30 / 31–60 / 61–90 / 90+ day bands by age).
  - `App\Services\Reporting\OperationsReportService`: `occupancy()` (day-by-day occupancy rate, ADR, and RevPAR computed from real `stays`/`folio_transactions`, counting a room occupied on a given date only while its stay is genuinely active that day) and `arrivalsAndDepartures()`.
  - `App\Services\Reporting\InventoryReportService`: `stockValuation()` (real FIFO batch value, not a recomputed average), `wastageCost()` (only **approved** wastage counts — recording alone never moves stock, same rule as Phase 3), `lowStock()`.
  - `App\Services\Reporting\HrReportService`: `payrollCost()` (only payslips actually `paid` count as a real cost — a draft or approved-but-unpaid run is a plan, not an expense), `attendanceSummary()`, `leaveBalances()`.
  - `App\Services\Reporting\KpiDashboardService`: delegates to the two services above rather than recomputing anything independently, so the dashboard can never show a number that disagrees with its own detail report.
- **Deliberate accrual-vs-cash distinction**: revenue (from folio charges) and cash flow (from the payment ledger) are presented as two separate, clearly-labeled figures rather than blended into one "revenue" number, because they are genuinely different accounting concepts — documented in `FinancialReportService`'s docblock and called out again as a footnote on the KPI dashboard view.
- A single `App\Http\Controllers\Reporting\ReportController` with a shared `range()` helper (defaults to month-to-date, overridable via `?start=&end=` query params, swapped automatically if given in reverse order).
- 7 role-aware Blade views: a permission-gated report hub, a KPI dashboard, and one screen each for financial, occupancy, arrivals/departures, inventory, and HR reports, plus a shared date-range filter partial.
- 4 new **view-only** permissions (`view-financial-reports`, `view-operational-reports`, `view-inventory-reports`, `view-hr-reports`) granted per the updated matrix in §9 — reporting needs no approve/request pairs since it is read-only, unlike every transactional module before it.
- A "Reports" section added to the main navigation, visible only to a user holding at least one of the four report permissions.
- A dedicated test suite (`tests/Feature/Reporting/FinancialReportTest.php`, `OperationsReportTest.php`, `InventoryReportTest.php`, `HrReportTest.php` — 22 test methods total — execution NOT VERIFIED here, same caveat as every phase before it; see §7).

### Built in Phase 7 (Security audit pass)

A manual, static code review across the entire Phase 1–6 codebase against the eight areas named in the original spec: SQL injection, XSS, CSRF, IDOR, mass assignment, file upload handling, rate limiting, and secret handling. **Full writeup: `SECURITY_AUDIT.md`** — this is a summary.

- Four categories were already clean with no changes needed: **mass assignment** (all 48 models declare `$fillable`/`$guarded`, zero unvalidated data ever reaches a write), **raw SQL injection** (the only raw-SQL fragments anywhere are 3 hardcoded `selectRaw` aggregate expressions in Phase 6's reporting service, zero user input concatenated into any query), **XSS** (exactly one `{!! !!}` raw echo in the whole view tree — a TOTP QR code rendered as SVG paths, not user text — everything else uses Blade's auto-escaping), and **CSRF** (every one of 89 forms was parsed programmatically; every POST/PUT/PATCH/DELETE form has `@csrf`, zero exceptions).
- **File upload handling** doesn't apply — grepped the whole codebase and confirmed no upload feature exists yet in Phases 1–6.
- **IDOR/authorization — 3 real findings, all fixed**: (1) any employee could cancel any other employee's pending leave request, since `request-leave` is granted to every role but `LeaveService::cancel()` never checked ownership — fixed with an owner-or-approver check. (2) A single Duty Manager account could request *and* approve its own leave request or small expense, since Duty Manager (unlike every other approval pair in the system) holds both the request- and approve-tier permission for those two workflows — an existing Phase 4 test had literally encoded this as correct behavior. Fixed with a same-actor guard in `LeaveService::approve()`, `ExpenseService::approve()`, and (for consistency) `RefundService::approve()` — a hard business rule that can't be bypassed by holding both permissions, the same way the accounting-day lock can't be. (3) Every employee could view every other employee's attendance log via `hr.attendance.index`, since `clock-own-attendance` (the permission gating that route) is granted to all 8 roles — fixed by scoping the full roster to `record-attendance` holders only.
- **Rate limiting — 1 gap, fixed**: password login, the TOTP challenge, and PIN's account-lockout counter were all already correctly throttled, but `/login` (username lookup) and `/login/totp/setup` (first-time MFA enrollment confirmation) had no throttling at all. Fixed by wrapping the whole login route group in Laravel's `throttle:20,1`.
- **Secret handling — 2 gaps, fixed**: password/PIN hashing and TOTP secret encryption were already correct and everything sensitive is in `$hidden`, but `SESSION_SECURE_COOKIE` had no explicit default (session cookies could travel over plain HTTP even on an HTTPS deployment) — fixed by defaulting it to `true` in `.env.example`. Separately, `.env.example` described a deploy-helper security gate (`ENABLE_DEPLOY_HELPER`/`DEPLOY_TOKEN`) referencing a controller file that doesn't exist yet anywhere in the codebase — corrected the comment so it can't be mistaken for a real, currently-enforced control.
- 9 new/updated test methods across `ExpenseTest`, `RefundTest`, `LeaveTest`, `AttendanceTest`, and `LoginTest` covering all 6 fixes, including rewriting one pre-existing test that had encoded the self-approval bug as correct behavior (execution NOT VERIFIED here, same caveat as every phase before it — see §7).
- Route-verification script re-run after all fixes: still 132 named routes, zero duplicates, zero unresolved references.

### Built in Phase 8 (Shared-hosting deployment helper)

A one-time, browser-based installer/migration runner for hosts with no SSH and no server-side Composer (spec section 41, README §10 step 5) — the last buildable item from the original roadmap. Full usage instructions are in §10 step 5; this is what's actually in the code.

- **`App\Http\Controllers\DeployHelperController`**, reachable at `/deploy-helper`, gated by two independent things, both required: `ENABLE_DEPLOY_HELPER=true` in `.env`, and a `token` request value that timing-safe-matches (`hash_equals`) a real `DEPLOY_TOKEN` — the exact placeholder value shipped in `.env.example` is refused outright even if somehow left in place. Either check failing returns a plain `404`, not `403` — a wrong guess looks exactly like the route doesn't exist.
- **A fixed, hardcoded menu of a few safe commands only**: `migrate --force`, `db:seed --force`, and `config:clear`/`cache:clear`/`route:clear`/`view:clear`. Deliberately **no** generic "run any artisan command" action (that would turn a recovery tool into remote code execution the moment the token ever leaked) and **no** destructive command (`migrate:fresh`, `migrate:rollback`, `db:wipe` are all absent on purpose).
- **Seeding is refused if the `users` table already has a row**, unless explicitly confirmed — most of this project's seeders `create()` unconditionally rather than `firstOrCreate()`, since they're meant to run exactly once on a fresh install, so a blind re-seed would duplicate every demo account and reference row.
- **Registered outside Laravel's normal `web` middleware group** (`routes/deploy.php`, loaded via `bootstrap/app.php`'s `withRouting(..., then: ...)` callback) — no session, no CSRF, no cookie handling. This is load-bearing, not an oversight: this project's own `.env.example` defaults to `SESSION_DRIVER=database`/`CACHE_STORE=database`, so on a truly fresh, unmigrated production database, the normal `web` middleware group would throw a "table doesn't exist" error on `sessions`/`cache` before a single migration ever ran — defeating the entire point of a pre-migration installer. Authorization is handled entirely inside the controller itself instead of via route/`auth` middleware, for the identical reason (the `users`/permission tables may not exist yet either).
- **Self-disabling, best-effort**: a "Disable now" button attempts an atomic read → regex-replace → write-to-temp → rename of `.env`'s `ENABLE_DEPLOY_HELPER` line back to `false`, and reports plainly if that failed (e.g. the host's file permissions don't allow it) rather than silently doing nothing — with the exact manual fallback instructions shown either way.
- **Logs every action to Laravel's own file log** (`storage/logs/laravel.log`), not the `audit_logs` table — that table may not exist yet the first time this runs, and there's no authenticated `User` to attribute an entry to.
- A dedicated test suite (`tests/Feature/DeployHelperTest.php` — 9 test methods, covering the 404-when-disabled/wrong-token/placeholder-token cases, the status page, migrate's idempotency, the seed-refusal-unless-forced guard, the safe cache-clear command list, and the disable action reporting a result either way — execution NOT VERIFIED here, same caveat as every phase before it; see §7).
- **NOT VERIFIED against a real shared-hosting account** — see the caveat in §10 step 5 for exactly what that means and doesn't mean.

### Not yet built

Nothing buildable remains. The one open item is §11 roadmap item 9 (end-to-end verification against a real live deployment), which by its nature requires a human with an actual deployed instance — not something any amount of further work in this sandbox can complete. (A GitHub Actions CI workflow, `.github/workflows/tests.yml`, already exists in this repo — written to spec section 35 — but no run of it has ever actually been triggered, so treat it with the same NOT VERIFIED caveat as everything else in §7 until a real push/PR exercises it.) See §11.

---

## 2. Architecture decision

| Option considered | Verdict |
|---|---|
| Laravel + PHP + MySQL/MariaDB | **Chosen.** Best balance of shared-hosting compatibility, ecosystem maturity, and developer productivity for this kind of transactional business app. |
| Slim/Symfony + MySQL | Rejected for this project — more assembly required for the same result, no material benefit for a single-property monolith. |
| Non-PHP stack | Rejected — the hard requirement is ordinary shared hosting with no permanently-running Node/Python process; PHP/MySQL is the only stack guaranteed to fit that everywhere. |

**Laravel version:** 12.x (not the newer Laravel 13) — deliberately, because Laravel 13 requires PHP 8.3+ while Laravel 12 supports PHP 8.2–8.5. Cheap shared hosts (InfinityFree included) are not always guaranteed to offer the newest PHP version on every plan; Laravel 12 keeps the widest hosting compatibility without giving up a current, supported framework release.

**No Fortify.** Laravel Fortify assumes one uniform login flow for every user. This system needs three different flows selected by role (password+MFA / password-only / PIN), which Fortify does not model. Two-factor auth is implemented directly against `pragmarx/google2fa` (the same library Fortify itself wraps) inside `App\Services\Auth\TwoFactorAuthService`, giving full control over the three-mode flow.

**UI stack:** server-rendered Blade + Tailwind via CDN `<script>` tag. No Vite/npm build step exists in this repository — there was nothing to compile, so nothing was scaffolded. If a future phase needs a JS build step, it should be a one-time local/CI build whose *output* is committed or uploaded, never a process that runs continuously on the host.

---

## 3. Requirements

```text
Required PHP version:      8.2 (also runs on 8.3–8.5)
Required extensions:       pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, gd (or imagick)
Recommended PHP version:   8.2 or 8.3 for the widest shared-hosting compatibility
Database:                  MySQL 8.0+ or MariaDB 10.6+ (InfinityFree currently offers MySQL 8.0 / MariaDB 11.4)
Composer:                  2.x (needed locally/in CI — see §6, shared hosting itself needs no Composer)
```

---

## 4. Installation (local development)

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env` and point `DB_*` at a real MySQL/MariaDB database you control, then:

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

Visit the URL `artisan serve` prints and sign in with one of the demo accounts in §8.

> **NOT VERIFIED IN THIS SESSION.** These exact commands were written and reviewed (every migration/model/controller file was PHP-lint-checked with `php -l` and cross-referenced by hand for consistent method/relationship/route names), but `composer install` and `php artisan migrate/test` were never actually executed here — this cloud sandbox's network policy blocks Packagist (Composer's package registry), so no dependency could be downloaded to run anything against. Run the commands above yourself (or in CI) to get a real pass/fail result; §7 explains this in detail.

---

## 5. Configuration

Business policy is **not hardcoded** — it lives in the `settings` table (`App\Models\Setting`, read through `App\Services\SettingsService`), seeded from `.env` values on first install by `SettingsSeeder` and editable afterward without a code deploy:

| Setting key | .env seed variable | Meaning |
|---|---|---|
| `currency` | `HMS_CURRENCY` | ISO code used throughout (default `XAF`) |
| `tax_rate_percent` | `HMS_TAX_RATE_PERCENT` | VAT/tax rate on taxable sales |
| `deposit_percent` | `HMS_DEPOSIT_PERCENT` | Default reservation deposit % |
| `expense_approval_threshold` | `HMS_EXPENSE_APPROVAL_THRESHOLD` | Expenses at/above this need `approve-large-expense`, not just `approve-expense` (Phase 4, `App\Services\Finance\ExpenseService`) |
| `session_timeout_minutes` | `HMS_SESSION_TIMEOUT_MINUTES` | Idle session timeout — actually wired into `config('session.lifetime')` at boot |
| `pin_max_attempts` | `HMS_PIN_MAX_ATTEMPTS` | Failed PIN attempts before lockout |
| `pin_lockout_minutes` | `HMS_PIN_LOCKOUT_MINUTES` | PIN lockout duration |
| `payroll_working_days_per_month` | `HMS_PAYROLL_WORKING_DAYS_PER_MONTH` | Divides basic salary to get a daily rate for the unpaid-absence payroll deduction (Phase 5) |

All amounts are XAF (Central African CFA franc), a zero-decimal currency in practice; columns are still `decimal(12,2)`/`decimal(14,2)` for accounting-grade precision and to keep the schema currency-agnostic later.

---

## 6. Database setup

All migrations are hand-written MySQL/MariaDB-targeted Laravel schema files — **zero PostgreSQL syntax** (no `SERIAL`, `JSONB`, `DO $$` blocks, `RETURNING`, or Postgres-specific triggers anywhere in this codebase; verified by inspection, not inherited from any prior project). Every table has explicit primary keys, foreign keys with sensible `onDelete` behavior, unique constraints where the business rule demands them, and indexes on the columns actually queried (status columns, date ranges, folio/room lookups).

Run in order (Laravel resolves the order automatically from filenames):

```bash
php artisan migrate        # creates every table
php artisan db:seed        # roles/permissions, departments, settings, demo users, room categories/rooms, sample guests
```

`php artisan migrate:fresh --seed` rebuilds everything from scratch — safe in development, **never run it against a production database with real guest/financial history.**

---

## 7. Testing

A full Feature/Unit test suite exists under `tests/`:

```text
tests/Feature/Auth/LoginTest.php              — all 3 login modes, TOTP enrollment + challenge, PIN lockout, logout, inactive-user block (updated post-9.8: now proceeds into the two-step flow like a real account, per the enumeration fix below, instead of a "username" session error), the whole login flow is IP-rate-limited (Phase 7 security-audit fix)
tests/Feature/Auth/LoginEnumerationTest.php   — post-9.8 fix round: an unknown/inactive username is routed forward through the same two-step flow as a real one instead of erroring at identify(), reaches a real pin/password page, always fails the next step with a generic message, is routed to the same step consistently for the same input, a real account still logs in normally (regression guard), password/pin attempt routes 419 without a prior identify() call
tests/Feature/FrontOffice/ReservationTest.php — create/approve/reject/cancel/no-show, double-booking guard, permission enforcement; fourth post-9.8 fix round (§20.3): front desk can now approve and reject a pending reservation (new `approve-reservation` grant), a role genuinely without that permission (bar staff) is still forbidden; follow-up round (§20.7): approving an already-confirmed reservation throws instead of silently succeeding (the concurrency fix that came with posting a claimed deposit to the ledger at approval); fifth post-9.8 fix round (§20.8.1): the create-reservation page ships no `<select>` of every guest at all — the view has no `guests` variable — and a guest arriving via `?guest_id=` is still shown pre-filled by name through the same search-as-you-type box the walk-in screen uses
tests/Feature/Public/WebsiteBookingTest.php — fourth post-9.8 fix round (`PHASE9_AUDIT_AND_PLAN.md` §20.4): the public, unauthenticated booking page renders and accepts a submission that always lands as a `pending`/`source = website` reservation attributed to the seeded system user; a returning guest is matched by phone instead of duplicated; a deposit screenshot/claimed amount/reference is stored and surfaced to staff without ever creating a `payments` row; file-type/required-field/date-logic validation; the honeypot silently no-ops a spam submission; the endpoint is rate-limited; staff can approve/reject a website-sourced reservation exactly like any other pending one; follow-up round (§20.7): a claimed deposit with a method is posted to the central ledger automatically on approval with the right amount/method/source_module/guest attribution and zero payments before approval; approving a deposit-bearing reservation requires an open Front Office shift (throws and leaves it pending otherwise); a reservation with no claimed deposit needs no shift to approve; a claimed amount with no method is never auto-posted; approving an already-approved reservation throws and never double-posts the deposit
tests/Feature/FrontOffice/ReservationDepositTest.php — real-lodging-charges round (`PHASE9_AUDIT_AND_PLAN.md` §15): a deposit is recorded through the central payments ledger with the correct amount/method/source_module/guest_id and increments the reservation's running deposit total (bcadd, not float addition); more than one instalment accumulates correctly; a deposit cannot be recorded on a cancelled reservation; a role without post-folio-payment is forbidden; a non-positive amount is rejected
tests/Feature/FrontOffice/CheckInCheckOutTest.php — check-in from reservation + walk-in, occupied-room guard, checkout + Accounts Receivable rule; real-lodging-charges round: check-in posts the full rate×nights room charge to the folio (both reservation-based and walk-in) and records how many nights it covered, a previously-recorded reservation deposit is applied as a folio credit at check-in without being ledgered a second time, checkout automatically charges only a genuine extension beyond what was already charged, checkout never auto-credits an early departure; third post-9.8 fix round (§18.1, §19.2, §19.4): check-in requires an open Front Office shift (checkout stays deliberately ungated), a walk-in can be billed to a company at check-in, and an unpaid checkout for a company-billed stay tags the resulting receivable with that company (traced through to `Company::totalOutstanding()`)
tests/Feature/FrontOffice/FolioTest.php       — charge/payment posting, void-by-reversal (never deletes), closed-folio guard
tests/Feature/FrontOffice/AuditLogTest.php    — audit rows written on approval/check-in, IP capture
tests/Feature/SmokeTest.php                   — root redirect, auth gate, health check
tests/Feature/Pos/OrderTest.php               — full order lifecycle, add-only lock after confirm, folio deferral (active stay only), central ledger write on cash close, outlet permission isolation, audit log writes; post-9.8 fix round: a draft order cannot be closed at all (no payment/folio/stock side effect, stays draft) — the confirm-before-close lifecycle fix, plus every folio-deferral test now confirms before closing
tests/Feature/Pos/RecipeStockDeductionTest.php — closing an order deducts FIFO stock for recipe ingredients, blocks the close on insufficient stock, unrecipeed menu items stay untracked
tests/Unit/Inventory/StockServiceTest.php     — FIFO consumes oldest batch first with correct cost, insufficient-stock guard changes nothing, receiving creates a real batch + audit row
tests/Feature/Inventory/PurchaseOrderTest.php — draft→submit→approve→receive lifecycle, partial receiving keeps a PO approved (not received) until every line is fully received, approve/receive guard rails; Phase 9.4: an Accountant (holds approve-purchase-order but not manage-purchase-orders) can reach the purchase-orders list, a role with neither permission cannot; post-9.8 fix round: a line item from a different PO cannot be received through this PO's route (no receipt/stock/quantity effect)
tests/Feature/Inventory/RequisitionTest.php   — approval performs a real FIFO issue, all-or-nothing across every line when any one product is short, cannot double-approve; Phase 9.4: a Duty Manager (holds approve-requisition but not request-stock) can reach the requisitions list, a requester-only role (bar staff) still can too, a role with neither permission cannot
tests/Feature/Inventory/StockCountTest.php    — snapshot on start, positive variance posts a real inbound adjustment batch, negative variance posts a real FIFO issue, cannot complete with uncounted lines
tests/Feature/Inventory/WastageTest.php       — recording never touches stock, approval deducts via FIFO, rejection leaves stock untouched, permission enforcement
tests/Feature/Finance/ExpenseTest.php         — small expense approvable by a *different* Duty Manager; a Duty Manager cannot approve their own expense request even below the large-expense threshold (Phase 7 security-audit fix); large (at/above threshold) expense blocked for Duty Manager but approvable by Accountant; marking paid posts to the central ledger; a pending expense cannot be paid; rejection requires a reason; third post-9.8 fix round (§18.3): paying an expense now requires picking a specific `financial_account_id` rather than a generic method string; fifth post-9.8 fix round (§20.8.3-4): the Accountant can now raise an expense request too (new `manage-expenses` grant) but still cannot approve their own request (same per-user guard as everyone else); paying an approved expense from the Bank account posts `bank_transfer`, never the invalid `bank` (the enum-mismatch regression fix)
tests/Feature/Finance/RefundTest.php          — requesting never moves money; approval posts to both the folio and the central ledger; rejection leaves both untouched; front desk (lacking approve-refund) cannot approve their own request; a GM cannot approve their own refund request even though they hold both permissions (Phase 7 security-audit fix); cannot request against a closed folio; sixth post-9.8 fix round (§20.9): approving now requires a real `financial_account_id`, and the posted method comes from that account, not the requester's original method guess (requesting mobile_money, approved from Bank, posts `bank_transfer`); approving with no account picked at all is rejected before it reaches the service
tests/Feature/Finance/AccountingDayTest.php   — third post-9.8 fix round (§18.1): every folio payment here runs with an open Front Office shift so the locked-day assertions stay isolated from the new shift gate; fifth post-9.8 fix round, day-close rebuild (§20.8.4): the day-lock gate now requires every *active* `FinancialAccount` (Cash, Bank, MTN, Orange, ...) reconciled for *today* specifically — recording only the old legacy whole-hotel cash count is no longer enough, locking is blocked while even one active account is unreconciled, reconciling the same account twice in one day is rejected, a locked day still blocks new folio payments and the expected-cash figure still matches the ledger, and the rebuilt Day Close screen lists every active account with its live balance and today's reconciled/outstanding status
tests/Feature/Hr/EmployeeTest.php             — onboarding creates a profile, resubmitting for the same user updates it rather than duplicating it, termination deactivates the login, permission enforcement
tests/Feature/Hr/AttendanceTest.php           — clock in/out lifecycle, cannot clock in twice or clock out without clocking in, a manager's manual correction for someone else, permission enforcement, a staff member cannot see a colleague's row on the shared roster while a Duty Manager can (Phase 7 security-audit fix)
tests/Feature/Hr/LeaveTest.php                — request within balance, over-balance request rejected, approval deducts the balance and marks attendance for every date in range, rejection touches neither, front desk (lacking approve-leave) cannot approve their own request, cancelling a pending request, a different employee cannot cancel someone else's pending request while a manager still can (Phase 7 security-audit IDOR fix), a Duty Manager cannot approve their own leave request even though they hold both permissions (Phase 7 security-audit fix), an unpaid (untracked) leave type is never balance-checked
tests/Feature/Hr/PayrollTest.php              — payslip generation computes absence deduction/gross/statutory/net correctly, regenerating a draft run replaces (not duplicates) its payslips, approval locks a run against further regeneration, separation of duties (generate/pay vs approve), paying posts to the central ledger, a locked accounting day blocks paying a run, generating with no eligible employees fails cleanly; fourth post-9.8 fix round (§20.2 audit finding): an already-paid run cannot be paid again even under a near-simultaneous double call, and posts exactly one payment (the missing lockForUpdate()-inside-transaction guard found and fixed by this round's "money not double recorded" audit)
tests/Unit/Hr/PayrollDeductionServiceTest.php — flat-percent deduction math, progressive brackets only tax the slice within each band, an all-zero-rated bracket produces no deduction, an inactive deduction type is ignored
tests/Feature/Reporting/FinancialReportTest.php    — revenue-by-type excludes voided charges and respects the date range, cash flow splits in/out by source module, expense summary splits requested from actually-paid, receivables aging buckets by age, permission enforcement (view-financial-reports); Phase 9.3: the Income Statement is total revenue minus total requested expenses and can show a net loss, permission enforcement; third post-9.8 fix round (§18.1, §18.3): the cash-flow test opens a Front Office shift before its folio payment, and expense-payout calls now pass a real `FinancialAccount` instead of a method string
tests/Feature/Reporting/OperationsReportTest.php   — occupancy counts only rooms actually occupied on each day, a checked-out stay stops counting, ADR = room revenue ÷ room-nights sold, arrivals/departures scoped to range, permission enforcement (view-operational-reports); Phase 9.3: Guest Stay History lists only closed stays checked out in range with real charge/payment totals (excluding still-active and out-of-range stays), and flags a stay that was sent to Accounts Receivable; Phase 9.5: the CSV export downloads with the correct content-type and real rows, permission enforcement; third post-9.8 fix round (§18.1): the guest-stay-history test opens a Front Office shift before its folio payment
tests/Feature/Reporting/InventoryReportTest.php    — stock valuation matches real FIFO batches, wastage cost only counts approved wastage, low stock lists only products below reorder level, permission enforcement (view-inventory-reports)
tests/Feature/Reporting/HrReportTest.php           — payroll cost only counts payslips actually paid (not draft/approved), attendance summary counts each status per employee, permission enforcement (view-hr-reports)
tests/Feature/Reporting/WeeklyOwnerSummaryTest.php — Phase 9.4: the summary window runs 6 days before through the anchor date, it combines real revenue/expense figures for exactly that window (excluding a charge outside it), permission enforcement (view-financial-reports), the ?week_ending= parameter selects a past week; Phase 9.5: the printable version renders for a permitted role and is forbidden for one without view-financial-reports
tests/Feature/Reporting/PaymentSummaryReportTest.php — Phase 9.5: lists real payments from both a folio payment and an expense payout, permission enforcement (view-financial-reports), the print view renders, the CSV downloads with the correct content-type and contains the real amount; third post-9.8 fix round (§18.1, §18.3): both folio-payment tests open a Front Office shift first, and the expense payout passes a real `FinancialAccount`
tests/Feature/Reporting/ExpenseReportTest.php — Phase 9.5: lists individual expenses (not just category totals) with their status, permission enforcement, the print view renders, the CSV contains the real expense number and amount
tests/Feature/Reporting/ManagementReportTest.php — Phase 9.5: combines KPI and financial-breakdown figures on one page, permission enforcement, the print view renders
tests/Feature/PrintableDocumentsTest.php      — Phase 9.5: every print route's permission gate (reservation confirmation, guest invoice, final hotel bill, payment receipt, bar/restaurant receipt, daily cash closing), the final bill is blocked by HmsException until the stay is actually closed and printable once it is, a payment receipt 404s for a non-payment transaction, a Cashier without manage-guests can still print a receipt for a payment they posted, a POS receipt is reachable only by the matching outlet role (checkout call updated for CheckOutService's removed post-final-night parameter, real-lodging-charges round §15)
tests/Unit/SettingsServiceTest.php            — settings default fallback + read/write round trip
tests/Unit/Sync/HasSyncUlidTest.php           — Phase 9.7.1: a ULID is auto-assigned on create and has the real 26-char Crockford-base32 shape, two records get different ULIDs, the trait works on a second unrelated model, a pre-set ULID (direct attribute assignment) survives save() unchanged, ulid is absent from every $fillable, the whereUlid scope finds the right row, Setting is confirmed to not use the trait
tests/Unit/Sync/DeviceAuthServiceTest.php     — Phase 9.7.2, revised in 9.7.4 for the shared-secret model: registering a device returns a usable device + a plaintext secret, the secret is encrypted at rest (not plaintext) but decrypts back to the exact original value, shared_secret is hidden from array/JSON output, authentication succeeds with the right key+secret and fails for a wrong secret/unknown key/revoked device, a successful authentication stamps last_seen_at, importing a device records the exact given key/secret and it authenticates, credentialsFor() returns what this installation presents outbound and throws for a device with no secret on file
tests/Unit/Sync/SyncRegistryModelsTest.php    — Phase 9.7.2: device→checkpoint/batch relations and the checkpointFor() direction lookup, batch→item and item→batch, an item's conflict lifecycle including resolution, a sync log with and without an associated device
tests/Feature/Console/RegisterSyncDeviceTest.php — Phase 9.7.2, revised in 9.7.4: the sync:register-device command creates a device and prints its key/secret, the --endpoint option is stored on the device, --import-key/--import-secret record the exact given values and the device authenticates, --import-key without --import-secret fails cleanly
tests/Unit/Sync/SyncableModelsTest.php        — Phase 9.7.3: exactly 48 unique real classes are listed, Setting is confirmed absent, isSyncable() matches the list
tests/Feature/Console/BackfillSyncUlidsTest.php — Phase 9.7.3: sync:backfill-ulids assigns a ulid to a query-builder-inserted "legacy" row on two different tables, leaves an already-assigned ulid alone, is safe to run twice
tests/Unit/Sync/SyncServiceTest.php           — Phase 9.7.3 (+1 in 9.7.4): a first-ever outbox build includes existing data, a row with no ulid is excluded, an unchanged row isn't resent after acknowledgement, a post-acknowledgement change is picked up next time, an inbound create and an inbound no-conflict update both apply, a local edit survives a conflicting incoming change untouched (and is recorded in sync_conflicts), replaying an identical batch ulid is a no-op even with different payload data, a delete operation soft-deletes Guest, a delete operation on a non-soft-delete model is skipped with a reason instead of hard-deleting, an unknown syncable type fails only that item without aborting the rest of the batch, a malformed non-object item fails cleanly instead of throwing a TypeError
tests/Feature/Sync/SyncTransportTest.php      — Phase 9.7.4: push is unauthorized without valid credentials, a valid push applies a batch and reports the right status breakdown, a missing batch_ulid is a 422, pull returns a batch without advancing the checkpoint, acknowledge advances the checkpoint and is confirmed idempotent on a repeat call, acknowledging an unknown batch_ulid is a 404, a revoked device is locked out of every endpoint
tests/Unit/Integrity/DataIntegrityCheckerServiceTest.php — Phase 9.6: an empty database passes all 7 checks with zero issues; one test per check corrupts exactly that invariant (a drifted folio balance, an AR row pointing at a deleted folio, an AR row owing more than its original amount, a stay with no folio, a room marked occupied with no active stay, an active stay in a non-occupied room, a negative stock batch, a purchase order marked received that isn't fully received) and confirms only the matching check fails
tests/Feature/Console/RunIntegrityCheckTest.php — Phase 9.6: `integrity:check` exits 0 and prints "found no issues" on a clean database, exits non-zero and prints the specific failing check plus "found real issues" otherwise
tests/Feature/DeployHelperTest.php            — 404 when the feature flag is off, 404 with a wrong or the shipped placeholder token, status page with the correct token, migrate is idempotent and reports output, seeding is refused once a user exists unless explicitly forced, the safe cache-clear command list runs, the disable action reports a result either way; Phase 9.6: the integrity-check step renders PASS on a clean database, renders an ISSUE badge when a real inconsistency exists, and still 404s without a valid flag+token; seventh post-9.8 fix round (§20.11): updating roles & permissions alone, and setting up financial accounts alone, are each never refused even with an existing user (unlike the generic seed action); running the financial-accounts seed twice never duplicates or resets an account that already exists, balance included; both new actions are still 404 without the flag+token
tests/Feature/FrontOffice/RoomAuthorizationTest.php     — Phase 9.0: view-rooms permission enforcement, manage-rooms still required separately to create a room
tests/Feature/FrontOffice/StayAuthorizationTest.php     — Phase 9.0: manage-guests permission enforcement on the active-stays list and a guest's folio, the financial figure never appears in a forbidden response body
tests/Feature/FrontOffice/MaintenanceAuthorizationTest.php — Phase 9.0: report-maintenance permission enforcement on the ticket list
tests/Feature/DashboardAuthorizationTest.php            — Phase 9.0: the open-receivables financial figure is hidden from front-line roles, the dashboard itself stays reachable by every role; Phase 9.4: the pending-approvals widget appears only for a role holding at least one approve-* permission, the weekly financial summary widget is gated on view-financial-reports specifically (not the broader view-operational-reports)
tests/Feature/Dashboard/PendingApprovalsServiceTest.php — Phase 9.4: purely permission-driven, one test per approve-* branch (reservations, expenses via either approve-expense or approve-large-expense, refunds, maintenance restricted to tickets that take a room out of service, purchase orders, requisitions, wastage, leave, payroll), a role with none of these permissions sees an empty list, Owner sees every item; fourth post-9.8 fix round (§20.3): the reservations-approval negative control updated to bar staff, now that front desk also holds approve-reservation and correctly sees that item
tests/Feature/Admin/UserAdminTest.php         — Phase 9.2: creating password/PIN accounts, rejecting a password account with no password, permission enforcement, editing role/department, deactivate/reactivate, self-deactivation blocked, password reset refused on a PIN account, PIN reset clears any lockout, unlock-PIN leaves the PIN itself unchanged
tests/Feature/Admin/DepartmentAdminTest.php   — Phase 9.2: create/update/deactivate/reactivate, duplicate department codes rejected, permission enforcement
tests/Feature/Admin/SettingsAdminTest.php     — Phase 9.2: grouped settings screen renders, updating decimal/integer/string settings in one request, a non-editable setting cannot be changed through the endpoint, logo upload (and rejecting a non-image file), permission enforcement
tests/Feature/Admin/RoomCategoryAdminTest.php — Phase 9.2: create/update a category's rate, deactivating a category leaves existing rooms' assignment untouched, manage-settings required (manage-rooms alone is not enough), permission enforcement
tests/Feature/Pos/ComplimentaryOrderTest.php  — complimentary items (out-of-band addition, PHASE9_AUDIT_AND_PLAN.md §9): a comped item is free while another item on the same order stays billable (unit_price preserved for reporting, line_total zeroed), comping without a reason is rejected, an entirely-comped order closes with no payment method/stay and zero payment rows, a non-comp order still requires one (regression guard), a comped item still deducts real stock via its recipe, comping writes a distinct pos.item_comped audit action
tests/Feature/FrontOffice/StayComplimentaryTest.php — complimentary stays (out-of-band addition, PHASE9_AUDIT_AND_PLAN.md §9): duty_manager can mark an active stay complimentary (front_desk gets 403), marking without a reason or against a closed stay is rejected, a manual room_charge is refused against a comp stay's folio while every other charge type still posts normally, unmarking restores normal room-charge behavior; real-lodging-charges round (§15): checkout never charges a complimentary stay even for what would otherwise be a genuine extension, checkout automatically charges a normal stay that was never charged at check-in (regression guard, replaces the old manual post-final-night checkbox test), marking a stay complimentary after a real check-in reverses the room charge already posted to its folio via a genuine reversing adjustment
tests/Feature/Pos/OrderCrossOutletTest.php    — Phase 9.8 security re-audit fix: a Bar Staff account gets a 404, not success, substituting a Restaurant/Front Desk order's ID into a Bar URL for every action (view, add/remove item, confirm, close, cancel, print receipt), plus a same-outlet regression guard; post-9.8 fix round: a cross-outlet pos_table_id or menu_item_id (and an inactive menu item) is rejected at both request validation and the service layer (including two direct-service-call tests bypassing validation entirely)
tests/Unit/Http/ExportsCsvTest.php            — Phase 9.8 security re-audit fix: a CSV cell starting with =/+/-/@ is apostrophe-prefixed (formula-injection neutralization), an ordinary cell is unaffected
tests/Feature/Pos/AdditionalOutletsTest.php   — second post-9.8 fix round (§17.1): Bucareaux staff can run a full order lifecycle and close it with cash, the payment is attributed to the Bucareaux department; Bar Outside staff cannot operate the main Bar outlet and vice versa (same outlet-ownership guard as OrderCrossOutletTest, for the two new outlets)
tests/Feature/Finance/CashDisbursementTest.php — second post-9.8 fix round (§17.4): a manager's ticket can be disbursed against by a cashier and is marked used; a disbursement cannot exceed its ticket's authorized amount; a ticket-less disbursement without a named authorizer is rejected; a ticket-less disbursement with one succeeds; only an open (not cancelled) ticket can be disbursed against; third post-9.8 fix round (§18.3): every disbursement here now specifies a `financial_account_id` instead of a generic method string
tests/Feature/Finance/ShiftTest.php           — second post-9.8 fix round (§17.5): opening a shift, taking a cash payment, and closing it with the exact declared amount yields zero variance; a shortfall yields a negative variance; a department cannot have two open shifts at once; manage-own-shift does not itself restrict which department a shift is opened for; rewritten for the third post-9.8 fix round (§18.2): the shift's department is always the logged-in user's own `users.department_id`, never a submitted value, and a user with no department assigned gets a clear error instead of opening a shift for nothing; fourth post-9.8 fix round (§20.1): Accountant, General Manager, and Duty Manager can no longer open a shift at all (403), and all three can still reach shift reports (view-shift-reports untouched); fifth post-9.8 fix round (§20.8.4): the shift print-out now also breaks its non-cash income down by financial account, not just as one lump figure
tests/Feature/Finance/ShiftGateTest.php       — third post-9.8 fix round (§18.1): dedicated negative-path coverage — a missing shift blocks a folio payment, a check-in, and a POS order for each of the five gated departments (Front Office/Bar/Restaurant/Bar Outside/Bucareaux); opening the right shift unblocks it; a shift open for one gated department does not unblock a different one; checkout and an expense payout are never blocked even with zero shifts open anywhere
tests/Feature/Finance/FinancialAccountTest.php — third post-9.8 fix round (§18.3): an accountant can create a financial account; a duplicate account code is rejected; `currentBalance()` reflects the opening balance plus every ledgered payment (a cash folio payment auto-attributes to the seeded Cash account); reconciling computes the variance between the live expected balance and a declared one; an already-inactive account cannot be deactivated twice; sixth post-9.8 fix round (§20.9): Duty Manager can now create a financial account too (new grant), a role that still genuinely lacks the permission (front desk) is still forbidden; sixth post-9.8 fix round, follow-up (§20.10): an accountant (and a duty manager) can edit an existing account's name/code/type/opening balance; editing into another account's code is rejected; a role without the permission cannot edit; a deactivated account can be reactivated (the way back that deactivate() never had)
tests/Feature/Finance/AccountsReceivableTest.php — third post-9.8 fix round (§19.1-19.2): a partial settlement reduces the outstanding balance and leaves it open, a full settlement marks it paid, an over-payment is rejected, an already-paid debt cannot be settled again, Bar Staff cannot settle a debt, settling a debt never requires an open shift; a company's total outstanding debt sums across every stay it was billed for while excluding a fully-paid one and a different company's debt; Front Desk can register a new company, Bar Staff cannot
tests/Feature/FrontOffice/FacilityAccessTest.php — second post-9.8 fix round (§17.7): free access is granted to a checked-in guest's active stay and posts no payment; refused for a non-active stay; a walk-in's paid access posts a payment attributed to Front Office; a rate belonging to a different facility is rejected; third post-9.8 fix round (§18.1): every test here opens a Front Office shift first
tests/Feature/Reporting/DepartmentReconciliationReportTest.php — second post-9.8 fix round (§17.6): a closed Bar order's total appears as both Bar sales and Bar cash-in for the day; the print and CSV variants are reachable; a Cashier (view-shift-reports but not view-financial-reports) can still reach the report; fifth post-9.8 fix round (§20.8.4): each department row now also breaks its non-cash income down by financial account, shown on-screen, in print, and as its own CSV column
tests/Feature/Console/CheckUserAccessTest.php — second post-9.8 fix round (§17.2): the hms:check-access diagnostic exits 0 for a user who holds the permission, and exits 1 both for an unknown email and for a role that doesn't hold it
tests/Feature/Admin/SystemDataTest.php — seventh post-9.8 fix round, follow-up (§20.12): Owner can view the Master Admin data page with real row counts; General Manager (who holds almost every other permission) is still forbidden — the deliberate carve-out; every other role is forbidden; resetting without the typed confirmation phrase, or without confirming a backup was taken, is rejected and clears nothing; a real reset clears guests/reservations/payments but leaves staff/roles/departments/rooms/financial-accounts untouched; an occupied room is freed but a maintenance room is left alone; every active sync device is deactivated (the hybrid-sync defense); exactly one audit log entry is written per reset; the backup download never includes a staff member's real password hash; the export download is a zip
tests/Feature/FrontOffice/PaymentModuleTest.php — the general-purpose Payments module (not tied to a folio/order/expense/payroll run): front desk can record a general payment with or without a guest attached; fifth post-9.8 fix round (§20.8.3): the Accountant can now record a general payment too (new `record-payment` grant); a role without record-payment (bar staff) is forbidden on both the list and the store route
tests/Unit/RolesAndPermissionsSeederTest.php  — fifth post-9.8 fix round (§20.8.2): the seeder itself clears the Spatie permission cache as its last step, proving a freshly seeded grant takes effect immediately instead of waiting out the cache's TTL — the root-cause fix for the standing "front desk still can't update reservation status" report
tests/Unit/FinancialAccountModelTest.php      — fifth post-9.8 fix round (§20.8.4): `FinancialAccount::paymentMethod()` translates the account type `bank` to the valid payment-method value `bank_transfer`, and passes `cash`/`mobile_money`/`card` straight through unchanged — the regression guard for the enum mismatch that broke every Bank-account expense payment and cash disbursement
```

(The Phase 7 security-audit fixes didn't add new test *files* — they added/updated methods inside the Auth/Finance/Hr files already listed above, called out individually in each file's description; see `SECURITY_AUDIT.md` for the full findings writeup. The Phase 9.8 re-audit similarly added 2 methods to `tests/Unit/Sync/SyncServiceTest.php` and 1 to `tests/Unit/Sync/SyncConflictResolverTest.php`, proving User credentials can never leave an installation via sync or be overwritten by an incoming/conflict-resolved payload — see `SECURITY.md` §3.3 Finding 2 and `TEST_REPORT.md` §3.)

Run them with:

```bash
php artisan test
```

By default (`phpunit.xml`) tests run against an **in-memory SQLite** database for speed — this is standard Laravel practice, not a shortcut specific to this project. Because the production target is MySQL/MariaDB specifically, also run the suite once against a real MySQL database before trusting it in production:

```bash
DB_CONNECTION=mysql DB_DATABASE=hms_test DB_HOST=127.0.0.1 DB_USERNAME=root DB_PASSWORD=secret php artisan test
```

### Honesty about what has and hasn't run

**NOT VERIFIED.** Every test file above — Phase 1, 2, and 3 alike — was written and carefully cross-checked by hand (model relationships, route names, permission grants, service method signatures, and factory field requirements were all traced manually, and every PHP file in the project passed `php -l` syntax linting after each phase), but **no test was actually executed** in this session. The reason is environmental, not a shortcut: this cloud sandbox's egress policy blocks `repo.packagist.org` / `getcomposer.org`, so `composer install` cannot fetch `laravel/framework` or any dependency here, and there is consequently no `vendor/autoload.php` to run PHPUnit against. GitHub itself is reachable in this sandbox (that's how the Laravel skeleton and exact dependency versions in `composer.json` were sourced and cross-checked), but Composer's own registry is not.

For Phase 3 specifically, the route-name and model-relationship cross-checking went further than a manual read-through: a small Python script reconstructed every route's full dotted name from `routes/web.php` (tracking nested `->name()->group()` prefixes the way Laravel actually resolves them) and diffed it against every `route(...)` call in every new controller and Blade view — zero unresolved route names, zero accidental duplicate route names. That script's output is not a test run and doesn't substitute for one (it can't catch a wrong relationship name, a bad SQL type, or a logic bug), but it is a real, reproducible check that actually executed, which is why it's called out here rather than folded into "traced manually."

The same script was re-run for Phase 4 (after fixing a bug in the script itself: route path parameters like `/orders/{order}` contain literal braces that a naive character-by-character brace-depth counter can misread as PHP block delimiters — the walker now advances past each matched `Route::` statement as a whole instead of re-scanning its text char-by-char). It caught a real authoring mistake before this delivery: an early draft of the Finance routes defined `finance.expenses.index` twice (once inside the `manage-expenses` permission group, once as the intended shared OR-permission route for every expense-related tier) — a genuine duplicate route name that Laravel would have silently let the second definition win. It was fixed by removing the inner definition, and the final run confirms **102 total named routes, zero duplicates, zero unresolved `route(...)` references** across the whole Phase 1–4 codebase (controllers, views, and tests included). This is exactly the kind of mistake a real `php artisan route:list` plus a full test run would also have caught — the point of calling it out is that a script like this narrows, but does not eliminate, the value of actually running the suite.

One specific thing worth flagging about the Phase 2 POS tests: `OrderTest` deliberately zeroes `tax_rate_percent` in its `setUp()` so subtotal/tax/total math comes out in exact round numbers — tax computation itself is already covered separately by `SettingsServiceTest`/the folio tests, so this isn't hiding a gap, but it's the kind of detail that only a real test run confirms actually behaves as commented. `RecipeStockDeductionTest` (Phase 3) does the same for the same reason.

Run `composer install && php artisan test` yourself — on your own machine, in GitHub Actions, or any normal hosting/CI environment with unrestricted internet — and treat that as the real first result. If anything fails, it's a genuine bug to fix, not a discrepancy with a claim made here, because no passing/failing claim is made here.

---

## 8. User accounts (demo/staging data — rotate before real use)

`UserSeeder` creates exactly one account per role. **These are demo credentials for evaluation only; change every password/PIN before any real deployment.**

| Role | Username | Email | Auth | Credential |
|---|---|---|---|---|
| Owner | `owner` | owner@hms.test | Password + TOTP | `OwnerPass!2026` (TOTP enrollment happens on first login) |
| General Manager | `gm` | gm@hms.test | Password + TOTP | `ManagerPass!2026` |
| Duty Manager | `duty` | duty@hms.test | Password only | `DutyPass!2026` |
| Accountant | `accountant` | accountant@hms.test | Password + TOTP | `AcctPass!2026` |
| Cashier | `cashier1` | cashier1@hms.test | PIN | `1234` |
| Front Desk | `frontdesk1` | frontdesk1@hms.test | PIN | `1234` |
| Bar Staff | `bar1` | bar1@hms.test | PIN | `1234` |
| Restaurant Staff | `rest1` | rest1@hms.test | PIN | `1234` |
| Bar Outside Staff *(added §17.1)* | `barout1` | barout1@hms.test | PIN | `1234` |
| Bucareaux Staff *(added §17.1)* | `buca1` | buca1@hms.test | PIN | `1234` |

Password/MFA accounts sign in at `/login`; PIN accounts sign in the same way — the login page asks for a username first and routes to the correct next step (password, PIN, or TOTP) automatically based on that account's `auth_type`.

## 9. Role → permission matrix (Phase 1 + 2 + 3 + 4 + 5 + 6 scope)

| Permission | Owner | GM | Duty Mgr | Accountant | Cashier | Front Desk | Bar | Restaurant |
|---|:-:|:-:|:-:|:-:|:-:|:-:|:-:|:-:|
| manage-guests / manage-reservations | ✅ | ✅ | ✅ | | | ✅ | | |
| approve-reservation *(Front Desk added — fourth post-9.8 fix round, see PHASE9_AUDIT_AND_PLAN.md §20.3)* | ✅ | ✅ | ✅ | | | ✅ | | |
| manage-rooms | ✅ | ✅ | ✅ | | | | | |
| update-room-status *(added post-9.8 "corrections" round — see PHASE9_AUDIT_AND_PLAN.md §14.1)* | ✅ | ✅ | ✅ | | | ✅ | | |
| checkin-guest / checkout-guest | ✅ | ✅ | ✅ | | | ✅ | | |
| post-folio-charge | ✅ | ✅ | ✅ | | | ✅ | | |
| post-folio-payment | ✅ | ✅ | ✅ | ✅ | ✅ | ✅ *(added §14.8)* | | |
| record-payment *(new general-purpose Payments module — see §14.8; Accountant added — fifth post-9.8 fix round, see PHASE9_AUDIT_AND_PLAN.md §20.8.3)* | ✅ | ✅ | ✅ | ✅ | | ✅ | | |
| void-folio-transaction | ✅ | ✅ | ✅ | ✅ | | | | |
| manage-housekeeping | ✅ | ✅ | ✅ | | | ✅ | | |
| report-maintenance | ✅ | ✅ | ✅ | | | ✅ | ✅ | ✅ |
| approve/resolve-maintenance | ✅ | ✅ | ✅ | | | | | |
| manage-menu | ✅ | ✅ | ✅ | | | | | |
| operate-bar-pos | ✅ | ✅ | ✅ | | | | ✅ | |
| operate-restaurant-pos | ✅ | ✅ | ✅ | | | | | ✅ |
| operate-front_desk-pos *(added post-9.7.4 — see PHASE9_AUDIT_AND_PLAN.md §8)* | ✅ | ✅ | ✅ | | | ✅ | | |
| manage-products / manage-suppliers | ✅ | ✅ | ✅ | | | | | |
| manage-purchase-orders | ✅ | ✅ | ✅ | | | | | |
| approve-purchase-order | ✅ | ✅ | | ✅ | | | | |
| receive-goods | ✅ | ✅ | ✅ | | | | | |
| request-stock | ✅ | ✅ | | | | | ✅ | ✅ |
| approve-requisition | ✅ | ✅ | ✅ | | | | | |
| manage-stock-count | ✅ | ✅ | ✅ | | | | | |
| record-wastage *(hidden from every role post-9.8 "corrections" round — module kept, nobody holds this permission anymore; see §14.7)* | | | | | | | | |
| approve-wastage *(hidden from every role, same as above — see §14.7)* | | | | | | | | |
| manage-expenses *(Accountant added — fifth post-9.8 fix round, see PHASE9_AUDIT_AND_PLAN.md §20.8.3)* | ✅ | ✅ | ✅ | ✅ | | | | |
| approve-expense | ✅ | ✅ | ✅ | ✅ | | | | |
| approve-large-expense | ✅ | ✅ | | ✅ | | | | |
| pay-expense | ✅ | ✅ | ✅ | ✅ | ✅ | | | |
| request-refund | ✅ | ✅ | ✅ | | | ✅ | | |
| approve-refund | ✅ | ✅ | | ✅ | | | | |
| record-cash-reconciliation | ✅ | ✅ | ✅ | | ✅ | | | |
| lock-accounting-day | ✅ | ✅ | | ✅ | | | | |
| manage-employees | ✅ | ✅ | | | | | | |
| clock-own-attendance / request-leave *(restricted post-9.8 — see PHASE9_AUDIT_AND_PLAN.md §13; previously every role)* | ✅ | ✅ | | ✅ | | | | |
| record-attendance / approve-leave *(restricted post-9.8 — previously Duty Mgr too)* | ✅ | ✅ | | | | | | |
| manage-payroll | ✅ | ✅ | | ✅ | | | | |
| approve-payroll-run | ✅ | ✅ | | | | | | |
| pay-payroll-run | ✅ | ✅ | | ✅ | | | | |
| view-financial-reports | ✅ | ✅ | | ✅ | | | | |
| view-operational-reports | ✅ | ✅ | ✅ | | | | | |
| view-inventory-reports | ✅ | ✅ | ✅ | ✅ | | | | |
| view-hr-reports | ✅ | ✅ | | ✅ | | | | |
| manage-settings / manage-users / view-audit-log | ✅ | ✅ | | (audit only) | | | | |
| manage-sync *(added Phase 9.7.6 — sync dashboard + conflict resolution)* | ✅ | ✅ | ✅ | | | | | |
| mark-stay-complimentary *(added post-9.7.6 — see PHASE9_AUDIT_AND_PLAN.md §9)* | ✅ | ✅ | ✅ | | | | | |

Owner bypasses every check via a `Gate::before` wildcard (`App\Providers\AppServiceProvider`); every other role is enforced exactly as tabulated, in route middleware (`permission:...`) and policies (`ReservationPolicy`, `MaintenanceTicketPolicy`) — server-side, not by hiding buttons. The two POS permissions are outlet-specific by name, so the POS controller checks `operate-{outlet}-pos` dynamically per request rather than via a static middleware string (see `OrderController::authorizeOutlet()`), but the effect is identical: server-side, per-request, not a hidden button.

The inventory approval split is deliberate: a Duty Manager can raise and submit a purchase order (`manage-purchase-orders`) but cannot approve their own spend — that requires `approve-purchase-order`, held by the Accountant (and GM/Owner). Requisitions and wastage run the other way around: Bar/Restaurant staff can request stock or report wastage (`request-stock` / `record-wastage`) but only the Duty Manager (and above) can approve either, which is what actually moves stock. No dedicated Policy classes were added for these gates — they're plain permission checks (`manage-purchase-orders` vs `approve-purchase-order`, etc.), the same coarser-grained approach already used for Guests/Rooms/Housekeeping/POS in Phases 1–2, since (as with `ReservationPolicy`) a Policy class here would only re-wrap a permission check with no extra per-object logic to justify the indirection.

Phase 4's Finance permissions follow the same separation-of-duties pattern, plus one additional tier for money that crosses a configurable size threshold. A Duty Manager can request an expense and approve a small one (`manage-expenses` / `approve-expense`) but cannot approve one at or above the configurable `expense_approval_threshold` setting — that needs `approve-large-expense`, held only by the Accountant, GM, and Owner, and checked fresh against the setting's *current* value at the moment of approval rather than whatever it was when the expense was requested. Cashier and Duty Manager can both mark an approved expense paid (`pay-expense`) since either might be the one physically handing over cash, but only the Accountant (and GM/Owner) can approve a refund or lock the accounting day — Front Desk and Duty Manager can *request* a refund or *record* a cash count, but closing the books is deliberately kept out of the hands of whoever is also the one requesting money go out or counting the drawer they themselves work from day to day. As with Inventory, no dedicated Policy classes exist for these gates; they're the same permission-string checks used everywhere else.

Phase 5's HR permissions originally split into two tiers, with `clock-own-attendance` and `request-leave` given to **every** role, Bar/Restaurant Staff included, on the reasoning that clocking in for your own shift and asking for time off aren't privileged actions. **That reasoning was overridden by a direct project-owner decision post-9.8** (`PHASE9_AUDIT_AND_PLAN.md` §13): HR & Payroll, self-service included, is now visible and usable only by Owner, General Manager, and Accountant. Cashier, Front Desk, Bar Staff, Restaurant Staff, and Duty Manager hold no HR permission at all anymore — no clocking in, no leave requests, no payslips view, and (for Duty Manager specifically) no more recording others' attendance or approving/rejecting leave. If this hotel still needs those roles' hours tracked, it now has to happen some other way. Among the three roles that still have HR access, the rest of the separation of duties is unchanged: `manage-payroll` (Accountant/GM/Owner) can create a run and generate or regenerate its payslips while still draft; `approve-payroll-run` is deliberately withheld from the Accountant — held only by GM/Owner — so the person who prepared the numbers is never also the one who signs off on them; `pay-payroll-run` (Accountant/GM/Owner again) then actually moves the money, subject to the same accounting-day lock as every other payment. `manage-employees` (creating profiles, terminating staff, defining leave types and statutory deduction brackets) is Owner/GM only — HR policy configuration is kept out of the Accountant's hands entirely, unlike Finance's approval tiers, since it's a different kind of authority (who works here and under what terms, not how much a given transaction is worth).

Phase 6's reporting permissions are deliberately the simplest tier in the whole matrix: four single view-only permissions, one per report domain, with no approve/request pair because reporting never changes anything — it only reads what earlier phases already recorded. `view-financial-reports` and `view-hr-reports` go to the Accountant (plus GM/Owner) since payroll cost and revenue/cash figures are financial information; `view-operational-reports` goes to the Duty Manager (plus GM/Owner) since occupancy/ADR/RevPAR are what a Duty Manager actually runs the floor on day to day; `view-inventory-reports` goes to both the Duty Manager and the Accountant (plus GM/Owner) since stock valuation and wastage cost matter to whoever is managing the floor and whoever is managing the books. Cashier, Front Desk, Bar Staff, and Restaurant Staff get none of the four — reporting in this system is managerial by design, not because their day-to-day work doesn't touch these figures, but because none of the four report domains are actions those roles perform.

The large "corrections" fix round (`PHASE9_AUDIT_AND_PLAN.md` §14) added two new permissions and hid two existing ones, all by direct project-owner decision rather than a security-audit finding: `update-room-status` (Owner/GM/Duty Manager/Front Desk) lets Front Desk flip a non-occupied room's status straight from the room board, kept separate from `manage-rooms` for the same reason `view-rooms` already is; `record-payment` (same four roles) opens the new general-purpose Payments module for money that isn't a guest's room/POS bill (rent, a one-off visitor payment). `record-wastage`/`approve-wastage` are excluded from every role's grant now, Owner/GM included — the wastage module's table/model/data are untouched, only its permission grants and nav link are gone, so it is fully reversible later.

**The second post-9.8 fix round (`PHASE9_AUDIT_AND_PLAN.md` §17) added nine more permissions**, not reflected as new columns in the table above to keep it from growing indefinitely — the full grant is in `RolesAndPermissionsSeeder`, summarized here: `operate-bar_outside-pos`/`operate-bucareaux-pos` (their own staff roles, §17.1); `authorize-cash-disbursement` (Owner/GM/Duty Manager/Accountant — raise a disbursement ticket) and `record-cash-disbursement` (same four, plus Cashier — actually pay cash out, ticketed or not, §17.4); `manage-own-shift` (Front Desk/Cashier/Bar/Restaurant/Bar Outside/Bucareaux staff — open/close their own department's shift) and `view-shift-reports` (Accountant/Cashier/Duty Manager and up — see every department's shifts and the department reconciliation report, §17.5-17.6); `grant-facility-access` (Front Desk and up — Gym & Pool day-to-day) and `manage-facilities` (Owner/GM/Duty Manager — configure facilities and their rates, §17.7). Owner and General Manager held all nine automatically at the time, same as every other permission — **this is no longer true for `manage-own-shift` specifically**, see the note directly below.

**The fourth post-9.8 fix round (`PHASE9_AUDIT_AND_PLAN.md` §20) removed `manage-own-shift` from Accountant, General Manager, and Duty Manager** — none of the three can open or close a shift anymore (Accountant never held it in the first place); all three keep `view-shift-reports`, so shift history and reconciliation are still fully visible to them. Owner is unaffected (bypasses every permission check via `Gate::before`). This is the one permission in the whole matrix Owner and GM do **not** both hold, so it's called out here explicitly rather than left to be inferred from "Owner and GM hold every permission," which is otherwise still accurate.

**The fifth post-9.8 fix round (`PHASE9_AUDIT_AND_PLAN.md` §20.8.3) added no new permissions, but gave the Accountant two it never held before: `manage-expenses` (raise an expense request, not just approve/pay one — the same per-user self-approval guard still applies) and `record-payment` (the general-purpose Payments module, alongside Front Desk).** Disbursement-raising needed no new grant — the Accountant already held `manage-financial-accounts` and the disbursement-ticket permissions from the third post-9.8 round. Separately, this round fixed a real bug in how every permission grant in this system takes effect: `RolesAndPermissionsSeeder` now clears the Spatie permission cache itself as its last step (§20.8.2), so re-running it — as every round in this changelog already instructs — is guaranteed to apply immediately rather than sometimes sitting cached and invisible until the cache's TTL expires. This is why re-seeding roles is called out as **required** in this round's changelog entry even though the table above shows only two small additions.

**The sixth post-9.8 fix round (`PHASE9_AUDIT_AND_PLAN.md` §20.9) gave Duty Manager `manage-financial-accounts`** — the one role that still could not create or edit a financial account (Accountant, General Manager, and Owner already could). No other role gained it. Separately, and with no new permission involved: refund approval now requires picking the specific financial account the refund is actually paid back out of (mirroring `pay-expense`'s existing requirement), closing the last money-moving action in this system that could still post to the ledger with a freely-typed method instead of a real, attributable account — see §20.9 for the full list of every payment path this now applies to.

A same-round follow-up (§20.10) added no new permission at all — it gave the same `manage-financial-accounts` holders (Accountant, Duty Manager, General Manager, Owner) an actual way to edit an account after creating it and to undo an accidental deactivation, neither of which existed before even though the permission to do so already did.

**A seventh post-9.8 fix round follow-up (§20.12) added exactly one new permission, `manage-system-data`, held only by Owner** — the Master Admin reset/backup/export screen. This is the one permission in the whole matrix deliberately excluded from General Manager despite GM otherwise holding every permission except `manage-own-shift` (which is now `manage-own-shift` **and** `manage-system-data`) — a full, irreversible data wipe is kept to a single role, not "every back-office role" like the rest of this system's admin capabilities.

---

## 10. Deployment to InfinityFree (or equivalent shared hosting)

Two hard constraints on free-tier InfinityFree, confirmed via their own documentation while building this: **no SSH, and no server-side Composer** (no shell access to run it). That means dependencies must be installed *before* upload, not on the host.

1. **Build locally** (or in CI, where Packagist is reachable):
   ```bash
   composer install --no-dev --optimize-autoloader
   cp .env.example .env   # then edit with your real MySQL credentials and a fresh APP_KEY
   php artisan key:generate
   ```
2. **Create the MySQL database** in the InfinityFree control panel (or your host's panel) and note the host/db/user/password it gives you — free-tier hosts almost always require a non-`127.0.0.1` DB host value.
3. **Upload** the whole project (including the now-populated `vendor/` folder — this is the one case where `vendor/` *should* be uploaded, since the host cannot generate it) via the control panel's File Manager or FTP client, to a location outside the public web root if your plan allows it (InfinityFree's structure typically only exposes `htdocs/`) — point `htdocs/` at this project's `public/` directory, or copy `public/`'s contents into `htdocs/` and adjust `index.php`'s `require` paths to the project root one level up, whichever your plan's folder layout allows.
4. **Never expose** `.env`, `/app`, `/config`, `/database`, `/resources`, `/tests`, or `vendor/`'s non-served files to the web — only `public/` (or its uploaded equivalent) should be reachable by URL.
5. **Run migrations without SSH.** Since there is no terminal, either (a) run `php artisan migrate --seed` locally against the **host's** database using its externally-reachable DB hostname/port if the host allows remote MySQL connections, or (b) use the built-in web-based deploy helper (Phase 8, `app/Http/Controllers/DeployHelperController.php`):
   - Set `ENABLE_DEPLOY_HELPER=true` and a long random `DEPLOY_TOKEN` in your uploaded `.env` (never the example placeholder value — the helper refuses that exact string even if the flag is true).
   - Visit `https://your-domain/deploy-helper?token=<your-DEPLOY_TOKEN>`. From there: run migrations (`migrate --force`, safe to click more than once), optionally seed demo/reference data (refused automatically if the `users` table already has rows, unless you explicitly confirm you want to re-seed), and clear stale config/route/view caches if a previous deploy left any.
   - **On a hotel that's already live** (real staff, real guests, real payments already recorded), most changelog entries below that say "re-run `RolesAndPermissionsSeeder`" or "re-run `FinancialAccountSeeder`" mean a terminal command you don't have on this kind of host. Use the deploy helper page's own **"Update roles & permissions"** and **"Set up the four starter financial accounts"** buttons instead (added seventh post-9.8 fix round, §20.11) — both are always safe to click, any number of times, even with real data already in the system, unlike the big "Run seeders" button above them. If a permission grant "didn't seem to take effect," or an account dropdown (Payments, Refunds, Expenses, Day Close) shows no accounts to pick from, this is almost always the fix.
   - **Disable it the moment you're done** — click "Disable now" on the page (it attempts to flip `ENABLE_DEPLOY_HELPER` back to `false` in `.env` for you and tells you plainly if your host's file permissions didn't allow that) or edit `.env` yourself. Leaving it enabled is a real exposure: anyone who ever learns your `DEPLOY_TOKEN` can re-run migrations/seeders against your live database for as long as it stays `true`.
   - This is intentionally a **fixed menu of a few safe commands only** (migrate, db:seed, and cache/config/route/view:clear) — there is no generic "run any artisan command" action and never should be one, and nothing destructive (`migrate:fresh`, `migrate:rollback`, `db:wipe`) is exposed.
   - **NOT VERIFIED against a real shared-hosting account.** This was written and reviewed carefully (it deliberately runs outside Laravel's normal session/CSRF middleware so it still works before a single table exists — see `routes/deploy.php`'s docblock), and `tests/Feature/DeployHelperTest.php` exercises its logic against this project's own (already-migrated) test database, but the specific claim "this genuinely works against a truly empty production MySQL database on InfinityFree-like hosting, including whatever file permissions that host gives PHP for rewriting `.env`" cannot be confirmed from this sandbox. Try it against a real fresh database before relying on it, and keep the manual fallback in (a) in mind if anything about your host's environment doesn't cooperate.
6. **Set production values**: `APP_ENV=production`, `APP_DEBUG=false`, a freshly generated `APP_KEY`, real `DB_*` credentials, and rotate every demo password/PIN from §8 before anyone else can reach the site.
7. **Smoke test manually** (spec section 41) once live: site loads → login works for at least one account of each auth type → dashboard loads with real (zero) data → create a guest → create + approve a reservation → check in → post a folio charge and a payment → check out → confirm the AR record appears for any unpaid balance → log out. This has to be done against the live deployment by a human with access to it; it cannot be verified from here.

---

## 11. Roadmap (remaining phases, suggested order)

1. ~~**POS** (Bar/Restaurant): menu, tables, order lifecycle (draft → confirmed → closed), folio posting for checked-in guests only, receipts.~~ **Done — Phase 2.** ("Receipts" as a printable/PDF view was not separately built; the order screen itself shows the same line-item/total breakdown a receipt would.)
2. ~~**Inventory**: products/units/suppliers/purchase orders/goods receipts/stock batches, department requisitions, stock counts, wastage — then **FIFO costing** on top of real stock movements.~~ **Done — Phase 3 (this delivery)**, including linking POS menu items to stock deduction via `recipe_items` (deliberately deferred out of Phase 2 until this phase existed to link to).
3. ~~**Finance**: expense approval workflow (configurable threshold, already scaffolded in `settings`), refund approval workflow, reconciliation, and the accounting-day open/lock/correction-only-via-new-transaction model.~~ **Done — Phase 4 (this delivery).** (The central payment ledger itself — the foundation this phase builds on — was completed early, in Phase 2, so every module's payments already post to one place.)
4. ~~**HR & Payroll**: employees, attendance, leave, payroll runs, configurable statutory deduction brackets, payslips.~~ **Done — Phase 5 (this delivery).**
5. ~~**Reporting**: financial statements, occupancy/operations reports, inventory reports, HR/payroll reports, KPI dashboards — all reading live from the ledgers built in phases 1–4, never a duplicated total.~~ **Done — Phase 6 (this delivery).**
6. ~~**Security audit pass**: dedicated review for SQL injection, XSS, CSRF, IDOR, mass assignment, file upload handling, rate limiting coverage, and secret handling across the whole (by-then-complete) codebase.~~ **Done — Phase 7 (this delivery)** — see `SECURITY_AUDIT.md` for the full writeup; 6 real findings fixed (3 authorization/IDOR, 1 rate-limiting gap, 2 secret-handling/config gaps), 4 of the 8 categories were already clean, file uploads don't apply (no such feature exists).
7. ~~**CI/CD**: GitHub Actions pipeline — checkout → PHP/Composer → `composer validate` → `composer audit` → migrate+seed a test DB → `php artisan test` → fail the build on any failure.~~ **Workflow file already built** (`.github/workflows/tests.yml`) — still needs an actual push/PR to this repo's GitHub remote to produce a real (green or red) run; nothing has triggered it yet.
8. ~~**Shared-hosting deployment helper**: the safe, self-disabling browser-based installer described in §10 step 5.~~ **Done — Phase 8 (this delivery)** — see §10 step 5 and `app/Http/Controllers/DeployHelperController.php`. NOT VERIFIED against a real shared-hosting account (see the caveat in §10) — this sandbox can review and unit-test the logic but cannot try it against an actual fresh production database.
9. **End-to-end verification**: the full checklist in the original brief's section 42, run against a real deployment. This is the one item on this list that is *inherently* impossible to complete from inside this sandbox no matter how much further work happens here — it requires a human with access to a live, deployed instance. Everything else in this README has been built and reviewed as thoroughly as a sandboxed environment allows; this step is a deliberate handoff, not a gap in effort. See §10 step 7 for the exact checklist to run once you have a live deployment.

---

## 12. Troubleshooting

- **"could not find driver" on migrate** — the `pdo_mysql` PHP extension isn't enabled; enable it in `php.ini` or ask your host to.
- **Locked out of a PIN account during testing** — `pin_locked_until` on the `users` row clears itself after `pin_lockout_minutes`, or an Owner/GM can clear it manually via `php artisan tinker` (`User::find($id)->update(['failed_pin_attempts' => 0, 'pin_locked_until' => null])`) until an admin UI for this exists.
- **TOTP codes never validate** — check the server's clock (`date`); TOTP is time-based and drifts of more than ~30–60 seconds between the server and the authenticator app will fail every code.
- **419 Page Expired** on forms — usually a stale CSRF token after `session_timeout_minutes` was changed; refresh the page.

---

## 13. Changelog

**Screenshot-review fixes: revenue recognition for Bar/Restaurant, shift-variance notes, a 500 error, ADR/RevPAR labels, and a guest-history shortcut (`CORRECTION_ROUND_34_DELIVERY.md` §18-22)**
- **All Transactions 500 error, fixed** — a hardcoded route name in the view didn't match its actual
  registered (nested) name; the page threw the instant it had a real row to render (§18).
- **ADR & RevPAR now show their full names** — "Average Daily Rate" / "Revenue Per Available Room" —
  alongside the acronym on every screen and printable report that shows them (§19).
- **A shift can no longer close with an unexplained cash variance** — direct request: "before the closure
  any shift if there is any variance, the person in shift must explain with a note." Enforced in
  `ShiftService::close()` itself (a friendly flashed error, not a raw failure), with the close-shift form
  now prompting for the explanation the moment a declared amount disagrees with the live expected figure
  (§20).
- **Direct "Guest history" link from the Active Stays list** — no more needing to find a guest's own
  profile first; the existing, already-permission-gated Guest Transaction History screen is now one click
  away from the stay itself (§21).
- **Manually-recorded Bar/Restaurant income is now recognised as real department revenue** — direct
  request: since Bar and Restaurant have no POS device yet, their staff hand their full day's takings to the
  accountant, who records it on the general payment screen; that money only ever showed up as generic
  "other income" before, never as real Bar/Restaurant revenue on the Financial Report, KPI Dashboard,
  Income Statement, Weekly Owner Summary, or Department Payment Reconciliation report. The payment screen
  can now explicitly say which outlet department a handover belongs to, and every one of those reports now
  recognises it correctly — counted exactly once, never fabricated as a POS order, and never retroactively
  reclassifying anything already recorded (§22).

**Deploy fix: a second Laravel Cloud deployment failed on a missing `bootstrap/cache` directory (`CORRECTION_ROUND_34_DELIVERY.md` §17)**
- Root cause was a packaging mistake on my side, not a codebase bug: the delivery zip's build command used
  an overly broad exclude pattern (`-x "*.git*"`) that unintentionally stripped every `.gitignore`
  placeholder file project-wide — including the ones that keep `bootstrap/cache/` and several `storage/`
  subdirectories present in a git checkout or zip extraction, since git cannot track an empty directory on
  its own. Fixed by removing the redundant exclude flag and rebuilding the zip with all 12 placeholders
  restored (§17).

**Deploy fix: a fresh MySQL database failed on `financial_account_reconciliations`'s composite index (`CORRECTION_ROUND_34_DELIVERY.md` §16)**
- Reported from a real Laravel Cloud first deploy for a new hotel/site: `php artisan migrate --force` failed
  with `SQLSTATE[42000]... Identifier name '...' is too long`. Root cause: an unnamed composite index whose
  auto-generated name (76 characters) exceeds MySQL's 64-character identifier limit — never caught before
  because every prior deploy either already had the table or ran on Postgres, which truncates instead of
  erroring. Fixed with an explicit short index name, and the migration now safely repairs the exact
  half-finished state (table created, index missing) that failure leaves behind — redeploying this code is
  enough, no manual database changes needed. Every other migration in the project was scanned for the same
  class of bug; nothing else was over the limit (§16).

**The income-source list is now managed, not fixed — accountant/GM can create/update, only Owner can delete (`CORRECTION_ROUND_34_DELIVERY.md` §15)**
- Direct request, a fast follow-up to the dropdown just below: "the list should not be fixed the
  accountant, or finance officer (update, create) and the [owner] should be able to update, create and
  delete it." The 9-item starter list moved from a hardcoded PHP constant into a proper managed table
  (`income_sources` / `IncomeSource`) with its own screen (Finance → Income Sources, linked straight from
  the payment screen's dropdown). Accountant and General Manager can add or rename entries
  (`manage-income-sources`); only the Owner can delete one (`delete-income-sources`) — the same
  "delete is more destructive, so it's Owner-only" split already used for deleting a user or voiding a
  transaction. Deleting an entry never touches a payment already recorded under that name — see §15 for why
  that's safe (§15).

**Income source on the general payment screen is now a dropdown (`CORRECTION_ROUND_34_DELIVERY.md` §14)**
- Direct request: "let the income source be a dropdown of the income sources if other then state... identify
  all the income sources." Replaced the free-text "Income source" field on the general "record a payment"
  screen with a `<select>` built from a fixed, self-identified list of genuinely ad-hoc income categories
  (`Payment::INCOME_SOURCES` — rent, event hall hire, guest advance, commission, damage/breakage, lost and
  found, sponsorship/advertising, sale of scrap, miscellaneous), scoped to exclude income already tracked
  through its own dedicated flow elsewhere (room/folio charges, POS sales, gym/pool facility access,
  reservation deposits). An "Other (please state)" option reveals a free-text field for anything not
  listed. No schema change and no stricter server-side validation — `income_source` stays the same free-text
  column and requirement it already was, so every value the dropdown can submit still passes, and every
  value already in the database still displays correctly (§14).

**Owner can void (never edit-in-place) any transaction; payments can record a discount (`CORRECTION_ROUND_34_DELIVERY.md` §12–13)**
- Direct request: "the owner is suppose to be able to edit all transaction[s]." This system has no in-place
  edit for any posted transaction, for any role — and rewriting a posted amount would violate this
  engagement's own standing rule and silently invalidate every reconciliation computed since. Clarified
  first: the answer is void-then-correct, the same reversible mechanism folio charges already use. Because
  every payment, refund, expense payout, disbursement, and payroll payout already flows through the one
  central `payments` ledger, one fix covers all of them: a new Owner-only "All Transactions" screen lets the
  Owner void any of them (reason required, nothing ever overwritten), and every account balance,
  reconciliation, and report that sums the ledger now correctly excludes a voided row (§12).
- Direct request: "payments can involve discount if granted." Clarified first (there's a separate, existing
  approval-gated discount workflow for room rates — this is not that): the general "record a payment"
  screen can now note an optional discount (amount + reason, no approval needed) — the recorded amount
  always stays the real cash received; the discount is shown alongside it as gross vs net (§13).

**Every incoming payment needs an income source; expenses' cash outflows are now complete on the Income Statement (`CORRECTION_ROUND_34_DELIVERY.md` §11)**
- Direct request: "all in payment must be attached to an income source ... and out payment related to
  expenses also represented in the income statement too." The general "record a payment" screen (rent, a
  one-off visitor payment, etc.) now requires an Income Source, and — this was the real gap — that income
  had never appeared on the Income Statement at all; it now does, grouped by source (§11.1–11.2).
- Audit of every place real cash leaves the building found two more categories invisible on the Income
  Statement: a Finance Disbursement with no linked Expense, and every payroll payout (salaries had zero
  representation before). Both now show up, without double-counting a disbursement that DOES reference an
  Expense (§11.2).
- Found but NOT fixed this round, flagged for a dedicated follow-up: a walk-in POS sale paid on the spot
  (never deferred to a guest's folio) is still invisible on the Income Statement's revenue side — see §11.3
  for why closing it properly is a bigger design decision than this round's scope.

**Owner can delete a user account, and a scrolling follow-up (`CORRECTION_ROUND_34_DELIVERY.md` §10)**
- Direct request: "the owner is suppose to be able to delet user." `User` already used `SoftDeletes` (same
  as Guest), so this is a reversible remove-from-active-list, never a hard delete — nothing referencing the
  67 foreign keys pointing at `users.id` is touched. New "Removed users" list with Restore, and a new
  Owner-only `delete-users` permission kept separate from `manage-users` (General Manager still can't delete
  a user). Guards: you can't delete your own account, and you can't delete the last remaining Owner (§10.1).
- Follow-up to §9.1's scrolling fix: on any page with a long list next to a short "add/create" side panel
  (26 views — the Restaurant Menu screen, Expenses, Finance Disbursements, most HR/Inventory list screens),
  that panel now stays pinned to the top of the content area (and scrolls on its own if it's ever taller
  than the screen) instead of scrolling away with the list — three independent scroll regions per page:
  nav, list, and side panel (§10.2).

**Scrolling, auto-numbering, breakfast CRUD, purchase reports, and a financial-linking audit (`CORRECTION_ROUND_34_DELIVERY.md` §9)**
- Nine separate requests in one round — see §9 for the full write-up; §9.9 is a direct audit of the
  "double records" concern and is worth reading in full if you're reviewing this round's finance changes.
- Sidebar no longer disappears on a long page at desktop widths — the nav and content pane now scroll
  independently, fixed once in the shared layout so it applies to every module automatically (§9.1).
- Facility codes and Product SKUs are now generated automatically instead of freehand-typed; a Folio now
  carries a real `invoice_number`, generated at check-in and printed on the invoice (§9.2).
- Complimentary breakfast gained Edit and Delete buttons, plus a "delivered to room" flag — "Delete" restores
  the stock it consumed but, since it really was served, never frees up a second same-day redemption (§9.3).
- The general Goods Receipt form can now reference a Purchase Order directly (the schema already supported
  it; only the ad-hoc form's UI was missing the picker) (§9.4).
- New Purchase Report (screen/print/CSV) comparing purchase-order value against goods actually received,
  under Reports → Purchases (§9.5).
- Cash Disbursement is now labeled Finance Disbursement everywhere in the UI, and can optionally reference
  the specific Expense it's paying out — without ever posting that payment to the ledger twice (§9.7).
- A shift now requires declaring its opening cash float, which folds into its expected-closing/variance
  figures; an open shift's expected balance now updates live on screen every 20 seconds (§9.8).
- Full audit of financial-account linkage across every money-moving module confirmed a single insert-only
  ledger with no double-posting risk found; closed the one real gap (a disbursement/expense left with no
  department doesn't get folded into that department's shift reconciliation, though the money itself was
  always posted correctly) by defaulting both forms to the actor's own department (§9.9).

**Owner can now edit/delete every category and non-financial master-data record — financial transactions untouched (`CORRECTION_ROUND_34_DELIVERY.md` §8.7)**
- Direct request: "make sure the owner/main user has the access to edit, delete and update all categories
  and every input, except financial inputs." Added edit and/or delete to every category and master-data
  screen that was missing one: Room Category (delete only — edit already existed), Department (delete only),
  Menu Category, Menu Item, Unit of Measure, Product, Supplier, Facility, Facility Rate, Leave Type (first
  CRUD screen this table has ever had), and Payroll Deduction Type/Bracket. Guest and Company also gained
  edit; Guest's "delete" is a reversible soft-delete (`guests.restore` undoes it) so every stay/reservation/
  payment keeps working unchanged. No new permissions were added — each action reuses the same permission
  that already gates that entity's own create action, and the Owner already bypasses every permission check
  regardless. Every actual financial transaction (expenses, refunds, purchase orders, payments, payroll
  runs, financial accounts) still only exposes its existing controlled workflow — none gained a raw edit or
  delete. Deleting anything with real history is refused in favor of deactivating it, exactly like the Room
  board already does. ~30 new/extended tests across 10 test files.

**Owner can now edit and delete rooms (`CORRECTION_ROUND_34_DELIVERY.md` §8.6)**
- New Owner-only `edit-room-details` permission: edit a room's number, category, floor, description, and
  active flag from the room board; delete a room only if it has never been used (otherwise deactivate it).
  Category change/deactivation is refused while a guest is in the room or an upcoming reservation is
  assigned to it. Audited.

**Accountant: can now create purchase orders, suppliers, and employee records (`CORRECTION_ROUND_34_DELIVERY.md` §8.5)**
- Direct request: "I want the accountant to be able to create purchase order, have access to employee, so as
  to create employee and also the suppliers too." Added `manage-purchase-orders`, `manage-suppliers`, and
  `manage-employees` to the Accountant role. Since the accountant already held `approve-purchase-order`,
  this newly puts create-and-approve for the same purchase order in the hands of one role — the same
  same-role combination this system already prevents for expenses/refunds, so
  `PurchaseOrderService::approve()` gained the identical per-user self-approval guard those already use. As
  a hard business rule rather than a permission check, this also now applies to Owner/General Manager, who
  previously had no code-level guard against approving a PO they created themselves.

**Fixed: every online booking submission 404'd — a missing seeded system user, not a routing bug — plus a
branded 404 page and reservation-deposit refunds now showing in the guest transaction history
(`CORRECTION_ROUND_34_DELIVERY.md` §8)**
- Diagnosed live against the production site: `ReservationService::createFromWebsite()` attributes every
  website-sourced reservation to a fixed, seeded "Website Booking" system account, looked up with
  `firstOrFail()` — and on this install, only `db:seed --class=RolesAndPermissionsSeeder` had ever actually
  been run, so that account (seeded by a later phase's `WebsiteBookingSeeder`) never existed, and
  `ModelNotFoundException` renders as a bare 404 with no hint why. Now self-heals via `firstOrCreate()` with
  the seeder's exact values, so this can't recur regardless of seeding history — see §8.1 for the immediate
  one-command live fix and the full diagnosis. Also added a branded, mobile-responsive
  `resources/views/errors/404.blade.php` (previously none existed, so any 404 fell back to Laravel's bare
  default page — which turned out to be what actually looked "not responsive on my phone," not the booking
  form itself, which checked out fine at a 375px viewport — see §8.2). Separately, a reservation deposit's
  refund (already a fully-built feature, `ReservationService::disposeDeposit()`, from an earlier round's
  defect #8 — see §8.3) was invisible in the guest transaction history report, print view, and CSV export;
  all three now show it alongside the original deposit receipt (§8.4).

**Walk-in Check-in: route a discounted rate to a named approver instead of blocking check-in (`CORRECTION_ROUND_34_DELIVERY.md` §2.9)**
- Requested directly: "a place where the front desk or receptionist selects to identify who approved the
  discount" on the Walk-in Check-in form. Not implemented as a bare name dropdown — this codebase's
  discount-approval feature was built specifically around the rule "selecting another employee's name is
  NOT proof of approval," and a plain picker would have quietly reopened that gap. Put the design choice to
  the client directly; the option chosen was: the receptionist selects an eligible approver (Owner/General
  Manager/Duty Manager/Marketing Manager) and the guest is checked in immediately, while the selected person
  gets it as a pending item in their own "My Discount Approvals" queue and must approve or reject it
  themselves from their own login afterward. Reuses the existing pending-request machinery
  (`DiscountApprovalService::requestApproval()`) that already backed the reservation page's "Send for
  approval" action — only walk-in check-in is now allowed to proceed on a still-pending approval; a
  reservation-based check-in is unaffected and still blocks exactly as before. A user who isn't actually an
  eligible approver still can't be routed to (the request is independently re-verified and the whole
  check-in rolls back if it fails). No migration, no new permission. 5 new tests in
  `tests/Feature/FrontOffice/WalkInDiscountApprovalRoutingTest.php`.

**Fixed: missing `storage/framework/{cache,sessions,views}` placeholder directories — 500 error on every page of a fresh deployment (`CORRECTION_ROUND_34_DELIVERY.md` §3.8)**
- Found while diagnosing a live "500 Internal Server Error" on `/login` on a brand-new production deploy.
  Root cause was a packaging gap present in the project archive since before this correction round —
  `storage/framework/cache/`, `storage/framework/cache/data/`, and `storage/framework/sessions/` had no
  placeholder file, and git/zip both silently drop truly empty directories, so a genuinely fresh checkout
  never had them. Laravel resolves Blade's compiled-view path via `realpath(storage_path('framework/views'))`,
  which returns `false` for a missing directory, and the Blade compiler treats that as fatal — throwing on
  the very first view render of any request, i.e. every page. Not reproducible via `artisan route:list` or
  a raw service call, only via an actual rendered request, which is what made it look like a
  deploy-specific mystery rather than a missing-file gap. Fixed by adding the same `.gitignore` placeholder
  Laravel's own skeleton uses to all three directories (matching the one `storage/framework/testing/`
  already had), so they now survive packaging. See §3.8 for the live, no-redeploy fix for anyone already
  hitting this.

**Master Admin — Data: an Owner-only Import button, mirroring Backup/Export/Reset (`CORRECTION_ROUND_34_DELIVERY.md` §2.8)**
- Requested directly: an import button, restricted to the master/owner account. Added to the same
  Owner-only Master Admin — Data screen as Backup/Export/Reset (same `manage-system-data` permission — no
  new permission needed, since that one is already Owner-exclusive). Supports both of this screen's own
  export formats: the JSON "Full backup" file, and the CSV "Extract data" zip. Scope is deliberately narrow
  and mirrors `reset()`'s own allowlist exactly: only the operational history tables (guests, reservations,
  payments, POS orders, etc.) are ever restored — `users` (present in a JSON backup, but with its login
  credentials already stripped) and `rooms`/`financial_accounts` (present in a CSV export "for context")
  are never touched, so there's no login-credential gap to solve and no risk of colliding with existing
  setup data. Refuses outright unless the operational tables are currently empty — no merge, no silent
  overwrite — so the supported flow is a brand-new installation being seeded for the first time, or the
  same installation right after using Reset. Every import immediately runs the existing data-integrity
  checker and shows the Owner anything that doesn't line up (most likely cause: restoring onto a different
  installation whose rooms/staff/financial-account ids don't match the backup's). No migration and no new
  permission — code-only. 7 new tests in `tests/Feature/Admin/SystemDataTest.php`, including a genuine
  round-trip (real fixtures → real backup → real reset → real import → data confirmed back) and direct
  proof that `users`/`rooms`/`financial_accounts` are never restored even though two of the three source
  formats contain them.

**34-defect correction round + six new requirements, audited against the original `hms-14.zip` (`CORRECTION_ROUND_34_DELIVERY.md`)**
- A full audit-and-correct pass against 34 numbered defects across reservations/deposits/room allocation,
  room status/maintenance/housekeeping, POS/guest charges, folio accounting/refunds, and
  checkout/receivables/reporting, plus six new requirements: a consolidated guest transaction-history
  report (its own `view-guest-history` permission, five-role-restricted); one complimentary breakfast per
  occupied unit per business date (real FIFO stock deduction, DB-level duplicate-redemption backstop); a
  500 XAF room-delivery charge (structurally excluded from stock deduction, audited waive/correct
  actions); a Marketing Manager role and dashboard (mapped onto existing permissions wherever one already
  fit, explicitly denied every POS/menu/inventory-selling right, verified by direct unauthorized-request
  tests); approved discounts on check-in; and the hotel-logo/favicon fix (root cause: no
  `public/storage` symlink had ever been created — fixed via a new Deploy Helper button — plus a
  previously entirely-missing browser favicon). Two genuine, previously-undetected concurrency bugs were
  found and fixed while building this round's real two-process database concurrency test harness (not
  merely anticipated by it): `ReservationService::recordDeposit()` could double-increment `deposit_paid`
  under a genuine race even though the central ledger correctly deduped to one `Payment`, and
  `FolioService::postPayment()` could create a duplicate `FolioTransaction` and double-decrement a folio's
  balance under the same class of race — both fixed this round. 11 new migrations (see the delivery doc's
  §3 for the required order and the one pre-deploy remediation step for defect #18's new unique
  constraint), 5 new permissions, 1 new role, 39 new test methods across 6 new test files. Full change
  summary mapped to every defect/requirement number, migration/upgrade instructions with existing-data
  remediation, the final role-permission matrix, and an honest test-results/limitations section (this
  sandbox cannot run Composer/PHPUnit — see the delivery doc) are all in `CORRECTION_ROUND_34_DELIVERY.md`.

**Seventh post-9.8 fix round, follow-up: a Master Admin data-management screen — reset, backup, export (`PHASE9_AUDIT_AND_PLAN.md` §20.12)**
- Requested, verbatim: "I WANT TO CLEAR ALL THE EXISTING DATA IN THE DATABASE TO START AFRESSH," followed by "I NEED A BUTTON IN THE MASTER ADMIN THAT CAN RESET ALL DATA AND THAT WHICH CAN EXTRACT THE DATA AND THAT WICH CAN TRIGER BACKUP, YOU SHOULD NOT FORGET THIS IS A HYBRID SYSTEM." A new Owner-only screen, **Danger Zone → Master Admin — Data**, with three actions. **Full backup** downloads a complete technical copy of every table (staff passwords/PINs deliberately left out) as one JSON file, for disaster recovery. **Extract data** downloads a zip of spreadsheets (CSV) covering guests, reservations, stays, payments, refunds, debt, expenses, POS orders, rooms, and financial accounts — for the owner's own records. **Reset** permanently clears every guest, reservation, stay, payment, expense, POS order, and every other piece of day-to-day history — but deliberately leaves staff logins, roles, departments, the room list, room categories, and financial accounts completely untouched, so the system is immediately usable again afterward; it requires typing a confirmation phrase and confirming a backup was taken first, and there is no undo. Because this system can run as two syncing installations (a "hybrid" setup), a naive reset would be dangerously incomplete on its own: hard deletes never propagate through sync, so the very next sync cycle could quietly pull the wiped data right back in from the other installation. Reset now automatically switches off every registered sync connection as part of the same action, specifically to prevent that — see §20.12 for the full reasoning. New permission `manage-system-data`, deliberately excluded from General Manager's otherwise-almost-everything grant, held only by Owner. One new migration is **not** needed — no schema changed, only new tables this action reads/clears that already existed. **Run `php artisan db:seed --class=RolesAndPermissionsSeeder --force`** (or the Deploy Helper's "Update roles & permissions" button from the previous round) to pick up the new permission.

**Seventh post-9.8 fix round: a safe way to actually apply a permissions/accounts fix on a no-SSH host (`PHASE9_AUDIT_AND_PLAN.md` §20.11)**
- Reported as "when i click the dropdown it does not give the Cash/Bank/MTN/Orange." The most likely real cause: on shared hosting with no terminal, the only way to run `FinancialAccountSeeder` (or `RolesAndPermissionsSeeder`, which every permission-grant round in this changelog has said to re-run) was the Deploy Helper's one generic "Run seeders" button — which correctly refuses to run once the hotel has real staff/guests in it, because most of the other seeders it also runs are NOT safe to repeat. That left genuinely no safe way to apply either of those two specific seeders' fixes on a live, no-SSH host — a real, previously-invisible gap that could explain more than one "the fix didn't seem to take effect" report across earlier rounds, not just this one. The Deploy Helper page now has two more buttons, deliberately exempt from the "already has users" refusal: "Update roles & permissions" and "Set up the four starter financial accounts" — both call only that one specific seeder, both are provably safe to click any number of times against a live database (`RolesAndPermissionsSeeder` only ever `firstOrCreate()`s roles/permissions and re-syncs each role's grant list; `FinancialAccountSeeder` only ever `firstOrCreate()`s an account by its code, leaving any account that already exists — including its balance — completely untouched). No migration, no schema change — new deploy-helper routes and buttons only.

**Sixth post-9.8 fix round, follow-up: financial accounts can now actually be edited, and debt settlement confirmed already real-account-based (`PHASE9_AUDIT_AND_PLAN.md` §20.10)**
- After seeing the previous round's changes, two clarifications: "where i am suppose to chose account is supose to be (Cash/Bank/MTN/Orange) accounts even when debts are beein settled it is suppose to be thesame because they are the reale account" — confirmed: debt settlement has selected from the real Cash/Bank/MTN/Orange accounts (never a bare method) since the third post-9.8 round, unchanged; no code gap there. "the accountant should be able to add, modify and edit real accounts" — add already existed, but a genuine gap was found: there was no way to actually edit an account after creating it (fix a typo'd name, correct the type, adjust the opening balance), and no way back from deactivating one by mistake. Both are fixed now: Financial Accounts gained an Edit screen (name/code/type/opening balance, same shape as the existing Departments edit screen) and a Reactivate action, available to Accountant, Duty Manager, General Manager, and Owner — the same roles that can already create one. No migration needed — no schema changed, only new routes/screens for data that already existed.

**Sixth post-9.8 fix round: every payment now tied to a real financial account, and Duty Manager can create/edit financial accounts (`PHASE9_AUDIT_AND_PLAN.md` §20.9)**
- Two requests. First: "all payments are to be made in the accounts that control the payment methods" — an audit of every place money moves in this system found one remaining gap. Folio payments, POS closes, facility-access sales, expense payouts, cash disbursements, accounts-receivable settlements, payroll, reservation deposits, and general payments already required picking a specific `FinancialAccount` (Cash/Bank/MTN/Orange/...), with the posted method always derived from that account rather than typed freehand — refund approval was the one holdout, still using a bare method string typed by whoever *requested* the refund rather than whoever actually pays it back out. Approving a refund now requires picking the real account the money is leaving from, exactly like paying an expense already does; the requester's original method guess is kept on the record purely as a note of what was asked for, and never itself reaches the ledger. Second: "the accountant, manager, should have the right to create new account and or modify account" — the Accountant, General Manager, and Owner could already create/edit/reconcile financial accounts; Duty Manager was the one role that couldn't, and now can. One new migration, additive/nullable — **run `php artisan migrate --force`**, then **`php artisan db:seed --class=RolesAndPermissionsSeeder --force`** (carries the Duty Manager grant).

**Fifth post-9.8 fix round: searchable guest box, front-desk permission-cache fix, accountant expense/disbursement/payment grants, and a real day-close & cash reconciliation rebuild (`PHASE9_AUDIT_AND_PLAN.md` §20.8)**
- Sent as one message covering four requests. The staff create-reservation screen's guest field — previously a
  `<select>` built from every guest in the database, unpaginated and capped at 200 — is now a search-as-you-type
  text box backed by the same `guests.search` endpoint the walk-in check-in screen already used; a guest arriving
  via a just-created guest's own "Reservation" button is still shown pre-filled by name (§20.8.1). The "front
  desk still can't update reservation status" report turned out not to be a re-request for an already-correct
  grant — it was a real, systemic bug: Spatie's permission cache was never being cleared when
  `RolesAndPermissionsSeeder` ran, so a freshly granted or re-granted permission could sit invisible for up to the
  cache's full TTL after every seed. The seeder now clears the Spatie permission cache itself as its very last
  step, so any grant it makes — this one and every future one — takes effect immediately (§20.8.2). The
  Accountant can now raise an expense request directly (`manage-expenses`) and record a general payment
  (`record-payment`), on top of the approve/pay rights it already had — closing "expense entries... disbursement
  entries, payment entries" (disbursement-raising was already covered by the accountant's existing
  `manage-financial-accounts`-adjacent grants from the third post-9.8 round) (§20.8.3). The Day Close screen is
  rebuilt from the ground up: it now lists every real financial account (Cash, Bank, MTN Mobile Money, Orange
  Money, and any others the owner adds) with its live expected balance and whether it's been reconciled *today*
  specifically, and the accounting day can only be locked once every active account is reconciled for today — not
  just the old whole-hotel cash-only count, which is what made the feature feel like "it is just there" and never
  actually captured Bank/MTN/Orange. The old whole-hotel cash reconciliation still exists (nothing is ever
  deleted) but no longer gates the day lock, and is now clearly labeled "Legacy" in its own collapsed section.
  Shift print-outs and the Department Reconciliation report both gained a same-day per-account breakdown, so a
  shift's non-cash income is visible by account too, not just as one lump "non-cash" figure. While tracing this,
  a genuine, previously-shipped, unrelated production bug was also found and fixed: the Bank account's internal
  `type` value (`bank`) was never a valid `payments.method`/`cash_disbursements.method` value (only
  `bank_transfer` is) — every attempt to pay an expense or record a disbursement from the Bank account was
  throwing a hard database error, in production, since the third post-9.8 round introduced financial accounts.
  Fixed with a translation helper, `FinancialAccount::paymentMethod()`, rather than a schema change (§20.8.4). One
  new migration, additive/nullable — **run `php artisan migrate --force`**, then **`php artisan db:seed
  --class=RolesAndPermissionsSeeder --force`** (required this time: it carries the two new accountant grants and
  is also what self-clears the permission cache) — no other seeder needs re-running. After deploying, every
  financial account will show "Outstanding" on the Day Close screen until it's reconciled once under the new
  system — expected on the first day after this deploy, not a bug.

**Follow-up: public booking page redesign fix, and claimed deposits posted to the ledger at approval (`PHASE9_AUDIT_AND_PLAN.md` §20.7)**
- Fixed a real design bug on the public `/book` page: every input was missing Tailwind's `border` utility (only a
  border *color* was set, never a border *width*), so every field rendered with no visible box at all — fields now
  match the internal forms' visible borders and padding, and the two-column sections stack on a narrow phone
  screen instead of staying squeezed side by side. Separately: a guest's claimed deposit is now posted to the
  central ledger automatically the moment staff approve the reservation, instead of always needing a separate
  manual "Record deposit" step — a new "How did you send it?" field on the public form is what makes this honest
  rather than guessed (the system never invents a payment method it wasn't told). Approving a deposit-bearing
  website reservation now requires an open Front Office shift, the same rule that already applied to recording a
  deposit manually. A real concurrency gap in reservation approval (found because this change made it a genuine
  double-payment risk for the first time) was fixed the same way every other money-moving action in this system
  already protects itself. One new migration, additive only — **run `php artisan migrate --force`**, no seeder
  re-run needed.

**Post-deploy hotfix: `financial_account_reconciliations` migration no longer fails on a retry (`PHASE9_AUDIT_AND_PLAN.md` §20.6)**
- A real deploy hit "table already exists" on this one migration — most likely a shared-hosting request timeout
  that let MySQL finish creating the table but cut the connection before Laravel recorded the migration as done,
  so a retry tried to create it again. The migration now checks `Schema::hasTable()` first and safely skips if
  it's already there, instead of erroring. Nothing else changed. Just redeploy this file and run `php artisan
  migrate --force` again.

**Fourth post-9.8 fix round: shift scope-down, a payroll double-payment audit fix, front-desk reservation approval, and website booking requests (`PHASE9_AUDIT_AND_PLAN.md` §20)**
- Sent as one message covering four requests; the fourth (website bookings) was clarified with three follow-up
  questions before building — see §20 for the exact answers, which are the real specification for what got built.
  Accountant, General Manager, and Duty Manager can no longer open a shift at all (`manage-own-shift` removed from
  their grants); all three keep `view-shift-reports`, so shift history stays fully visible (§20.1). The open-ended
  "make sure money is not double recorded" request was treated as a real code audit — every ledger call site was
  traced and the single-writer invariant (`PaymentLedgerService::record()` is the only place a `payments` row can
  be created) re-confirmed intact; one genuine gap was found and fixed: `PayrollService::pay()` was missing the
  lockForUpdate()-inside-transaction guard every other money-moving action in this codebase already has, so two
  near-simultaneous "pay this run" requests could have posted two payments for one run — now closed (§20.2). Front
  Desk now holds `approve-reservation` alongside its existing `manage-reservations`, the same combination Duty
  Manager already had, with no self-approval guard needed since none exists for reservations anywhere in this
  system (§20.3). A new public, unauthenticated booking page (`/book`) lets a guest request a reservation directly
  from the hotel's own website — no online payment, just a request, optionally with an uploaded screenshot/amount/
  reference of a deposit they say they already sent; every submission always lands as a normal `pending`
  reservation for staff to review, exactly like a phone or walk-in booking, with a "Website" badge and the guest's
  deposit claim (clearly marked "not yet verified") shown on the reservation detail page; staff copy the booking
  page's URL from an info box on the Reservations screen to paste into their own website (§20.4). One new
  migration, purely additive — **run `php artisan migrate --force`**, then **`php artisan db:seed
  --class=RolesAndPermissionsSeeder --force`** (picks up both the shift-permission removal and the front-desk
  approval grant) and **`php artisan db:seed --class=WebsiteBookingSeeder --force`** (creates the one-time system
  user website bookings are attributed to).

**Third post-9.8 fix round: mandatory shift gating, reconciled financial accounts, and client debt settlement (`PHASE9_AUDIT_AND_PLAN.md` §18-19)**
- Sent as one message covering four requests, clarified with four follow-up questions before building (see §18-19
  for the exact answers, which are the real specification for what got built). Front Desk, Bar, Restaurant, Bar
  Outside, and Bucareaux can no longer record **any** transaction — a POS order, a folio charge or payment, a
  check-in, a paid facility sale — without an open shift for that department first; every other department's
  shifts (Cashier, Duty Manager, etc.) stay exactly as informational as before (§18.1). Opening a shift no longer
  offers a department dropdown at all — it's always the logged-in user's own department, automatically (§18.2).
  Four real, reconciled financial accounts now exist out of the box — Cash, Bank, MTN Mobile Money, Orange Money —
  and the owner can add more at any time; a cash/bank/card payment auto-attributes to the right one, and
  reconciling an account means an accountant types in the real balance from the statement/app and the system
  computes the variance against what the ledger says it should be, never the other way around (§18.3). Paying an
  expense or recording a cash disbursement now **requires** picking the specific financial account the money is
  leaving from — `method` is derived from that account, so the two can never disagree (§18.3). A guest's or a
  company's unpaid balance from a previous stay can now be settled with a real payment recorded through the
  central ledger — partial or full, never blocked by a missing shift, since collecting old debt shouldn't depend
  on today's shift being open (§19.1). A stay can optionally be checked in "billed to" a company, so its debt
  rolls up at the company level across every stay it was billed for, not just one guest at a time (§19.2).
  Write-off/forgive was offered and explicitly declined, so it was not built this round (§19.3). Eight new
  migrations, all additive/nullable — **run `php artisan migrate --force`**, then **`php artisan db:seed
  --class=RolesAndPermissionsSeeder --force`** (two new permissions: `manage-financial-accounts`,
  `manage-accounts-receivable`) and **`php artisan db:seed --class=FinancialAccountSeeder --force`** (idempotent —
  seeds the four starter accounts only if they don't already exist).

**Second post-9.8 fix round: two new POS outlets, cash disbursement, shift-based cash reconciliation, Gym & Swimming Pool, a department reconciliation report, every report made printable (`PHASE9_AUDIT_AND_PLAN.md` §17)**
- Six requests sent together, four clarified with follow-up questions before building (see §17 for the exact
  answers, which materially changed the design from what was first proposed). "The bar outside" and "the
  bucareaux" are now two more POS outlets (own menu, own sales, own staff role), following the exact same
  outlet-parametrized pattern Front Desk was added under — confirmed as separate sales/stock, drawing from the
  same shared central stock pool Bar and Restaurant already share (§17.1). The front-desk room-status control
  still not appearing was independently re-verified correct in code a third time; a new `php artisan
  hms:check-access {email}` command lets the owner self-diagnose the live permission/cache state directly on the
  production server (§17.2). Every payment now carries a `department_id`, derived automatically for existing
  payment types (§17.3) — the foundation for the two features below. Cash disbursement is now separate from
  Expenses: a manager raises a ticket in advance (the normal path), or a disbursement is recorded without one
  provided the authorizing superior's name is captured directly on it — exactly the two-path flow described, not
  either of two simpler shapes first proposed (§17.4). Cash reconciliation is now also available **per shift, per
  department** (open a shift, declare counted cash at close, expected cash computed live from the ledger) —
  corrected from an initially-proposed daily model, since "they work in shifts" (§17.5) — sitting alongside,
  not replacing, the existing whole-hotel daily cash reconciliation. A new Department Reconciliation report shows
  each department's sales/income alongside its cash in/out and its closed shifts, for Front Desk, Bar, Restaurant,
  Bar Outside, and Bucareaux (§17.6). New Gym & Swimming Pool facilities: free access for checked-in guests, paid
  registration/slot access for walk-ins sold at the front desk (§17.7). Eight reports that previously had no
  printable version now do — KPI Dashboard, Financial, Income Statement, Occupancy, Arrivals & Departures, Guest
  Stay History, Inventory, HR (§17.8). Six new migrations, all additive/nullable — **run `php artisan migrate
  --force`**, then **`php artisan db:seed --class=RolesAndPermissionsSeeder --force`** (new permissions/roles),
  and on a fresh/staging install also `--class=DepartmentSeeder` and `--class=FacilitySeeder` (both idempotent).
- **Hotfix, same round:** the first live run of `db:seed --class=RolesAndPermissionsSeeder` failed with
  `PermissionDoesNotExist: There is no permission named 'operate-bar_outside-pos' for guard 'web'` —
  `RolesAndPermissionsSeeder::PERMISSIONS` was missing two entries (`operate-bar_outside-pos`,
  `operate-bucareaux-pos`) that later grant lists already referenced. Fixed by adding both to `PERMISSIONS`; a
  cross-check script confirmed no other permission used in a grant is missing from the master list. **If you
  already redeployed and hit this error, just re-download the updated files and re-run the same seed command —
  it's idempotent and safe to re-run.**

**Printable-receipt redesign: fewer headers on the folio receipt, a real thermal-printer format for POS (`PHASE9_AUDIT_AND_PLAN.md` §16)**
- Sent with a screenshot of the guest invoice: the printed folio receipt showed three header blocks (the shared
  document layout's own, plus each of the two printed copies' own) where only two — one per copy — were wanted.
  The shared layout's header is now skippable per-document and skipped specifically for the folio receipt, freeing
  up space and matching "just 2 headers for the both receipts." Separately, bar/restaurant/front-desk POS order
  receipts now render on a dedicated narrow (80mm) layout designed for an actual thermal receipt printer — a
  single column, no wide table, nothing that can overflow the roll — instead of reusing the full-A4 back-office
  document layout. A reported "front desk can't update room status" issue was investigated and traced to a
  live-deployment data question (the permission may not have been re-seeded after the round that introduced it, or
  the room in question is occupied, which deliberately hides that form) rather than a code defect — see
  `PHASE9_AUDIT_AND_PLAN.md` §16.1 for exactly what to check.

**Removed: the room's separate "clean/dirty" housekeeping status (`PHASE9_AUDIT_AND_PLAN.md` §15.7)**
- "Remove the clean and the dirty status" — rooms used to track two separate things: a real operational status
  (Available/Occupied/Maintenance/Out of service) and a separate cosmetic "housekeeping status"
  (Clean/Dirty/Inspected) shown next to it on the room board, set automatically at checkout, when a maintenance
  ticket was resolved, and when a housekeeping task was completed. That whole cleanliness label and its
  auto-tracking is now gone (`rooms.housekeeping_status` dropped via migration) — the room board now just shows a
  room's real status. The separate Housekeeping module (the task list where staff create, start, and complete
  cleaning/inspection jobs) is completely unaffected. **Run `php artisan migrate --force`** after deploying this
  round (see §15's migration note below — it now runs alongside that round's own migration).

**Real lodging charges, reservation deposits as real payments, automatic checkout (`PHASE9_AUDIT_AND_PLAN.md` §15)**
- Sent by the project owner after using the "corrections" round in real use: "let the total cost be in the folio...
  the advance on reservation are also payments... make some modifications following global hotel (hospitality)
  standards." Reservations can now take a deposit/advance payment (`POST /reservations/{id}/deposit`) that is
  recorded through the same central payments ledger as every other payment in this system, not just a cosmetic
  number. Check-in now posts the **full anticipated stay cost** (rate × nights) to the guest's folio immediately —
  previously the folio only carried the nightly rate as informational text and started every stay at a balance of
  0.00 — and applies any reservation deposit as a credit right away, so the guest's true outstanding balance is
  accurate from the moment they check in, not just at checkout. Checkout no longer takes a manual "post final
  night" checkbox: it now automatically charges only a genuine *extension* (nights actually stayed beyond what was
  already charged), and never auto-charges or auto-refunds an early departure. Marking a checked-in stay
  complimentary now actually reverses any room charge already posted to its folio (via the existing
  never-edit-history reversing-entry mechanism), since that charge may now already exist by the time a stay is
  marked complimentary. One new migration (`stays.nights_charged`, purely additive) — **run `php artisan migrate`
  after deploying this round.** See `PHASE9_AUDIT_AND_PLAN.md` §15 for the full before/after and every judgment
  call, and `TEST_REPORT.md` §2.5 for the new/changed test coverage.

**Large post-go-live "corrections" round — ~16 requests in one batch (`PHASE9_AUDIT_AND_PLAN.md` §14)**
- Sent by the project owner after using the system for real for the first time. Room board: Front Desk can now
  update a room's status directly (new `update-room-status` permission), and "Add room" moved above the grid.
  Check-in: the guest picker is a live search box instead of a 200-guest-capped dropdown, a guest with no match
  is created inline as part of check-in, and creating a guest from its own page now offers "Check In"/"Reservation"
  buttons. VAT/tax is switched off everywhere and never shown on a receipt again (the `tax_rate_percent` setting
  is kept but now has no effect, by design). Every account can change its own PIN (`/my-pin`); PIN *reset* was
  already Owner/General-Manager-only before this round. Bar/Restaurant/Front-Desk POS is now a click-to-add-to-cart
  interface with a search box on the open-orders list. The hotel logo and name now show prominently at the top of
  the sidebar. The folio receipt shows cost-per-night and a per-department bill summary, and its printable version
  is redesigned as two identical copies per A4 sheet with a signature line, region, and logo. The wastage module
  is hidden from every role, Owner/GM included (data untouched, reversible). A new general-purpose Payments module
  lets Front Desk record any payment that isn't a guest's room/POS bill. Nav links, buttons, and menu tiles across
  the app gained consistent hover/press/selected states, and the sidebar collapses behind a hamburger menu on
  narrow screens, alongside ~20 other pages' 3-column layouts becoming responsive. See `PHASE9_AUDIT_AND_PLAN.md`
  §14 for the full, itemized before/after and `TEST_REPORT.md` §2.4 for the new/changed test coverage.

**HR visibility restriction — only Owner, General Manager, and Accountant see HR now (`PHASE9_AUDIT_AND_PLAN.md` §13)**
- Project-owner-directed change, made after the first real deployment: the HR & Payroll module is no longer
  partly self-service for every role. `clock-own-attendance` and `request-leave` — previously granted to all
  eight roles — and Duty Manager's separate `record-attendance`/`approve-leave` grants are removed from everyone
  except Owner, General Manager, and Accountant. Cashier, Front Desk, Bar Staff, Restaurant Staff, and Duty
  Manager can no longer clock in/out, request leave, view their own payslips, or see the "HR & Payroll" section
  in the nav at all (previously rendered unconditionally regardless of permission) — every one of those routes
  now 403s for them instead. `GET /hr/my-payslips`, which previously had no permission gate at all, now requires
  `clock-own-attendance`. `tests/Feature/Hr/AttendanceTest.php` and `LeaveTest.php` were rewritten to match — see
  `PHASE9_AUDIT_AND_PLAN.md` §13 for the full before/after and the explicit trade-off this accepts (those five
  roles' attendance now has to be tracked some other way, if it's tracked at all).

**First real deployment attempt — a genuine routing bug found and fixed (`PHASE9_AUDIT_AND_PLAN.md` §12)**
- A real Laravel Cloud deploy ran `composer install` successfully, then crashed on framework boot with
  `array_merge(): Argument #2 must be of type array, string given` in `routes/web.php`'s POS route group. Root
  cause: `Route::prefix('pos/{outlet}')->where('outlet', 'bar|restaurant|front_desk')` used the single-route
  two-argument `where()` form on a group, which the group-level `where()` doesn't support — it silently kept only
  the first argument, storing the plain string `'outlet'` instead of an array, which broke the moment Laravel
  merged this group's attributes with its parent's. Since every request boots the router, this was fatal to the
  entire application, not just POS. Fixed to the array form: `->where(['outlet' => 'bar|restaurant|front_desk'])`.
  This is the first real, external confirmation that a real run can catch what manual review and `php -l` cannot —
  see `TEST_REPORT.md`'s update at the top of the document.

**Post-9.8 fix round — Concurrency, ownership invariants, login enumeration, POS lifecycle**
- A user-directed follow-up task, not a fresh audit: six specific, pre-identified flaws, each fixed with a
  focused change and a regression test. Full writeup in `PHASE9_AUDIT_AND_PLAN.md` §11; here's the short version:
  1. **Duplicate money/stock movements from concurrent requests** — `ExpenseService::markPaid`,
     `RefundService::approve`, and `OrderService::close` now `lockForUpdate()` the row inside their existing
     transaction and re-check every eligibility rule against the *locked* copy before any side effect, so a
     racing duplicate request blocks, sees the already-settled state, and fails cleanly instead of paying/posting
     twice.
  2. **Purchase-order receiving didn't check line ownership** — a `purchase_order_item_id` from a different PO
     could be received through any other PO's receive route. Fixed in both `PurchaseOrderController::receive()`
     and `GoodsReceiptService::receive()`: a mismatch is rejected before any receipt/stock/quantity effect.
  3. **POS outlet ownership gaps** — `OrderService::open()` didn't check a supplied `pos_table_id`'s outlet, and
     `addItem()` didn't check a `menu_item_id`'s outlet or active status. Both are now enforced in the service
     layer (so a direct call can't bypass it) and mirrored in request validation.
  4. **`add_front_desk_outlet_support` migration was MySQL-only**, using `ALTER TABLE ... MODIFY COLUMN ... ENUM`
     — incompatible with the SQLite database `phpunit.xml` configures for tests, which made the whole suite
     unrunnable under its own configured test database. Made driver-aware: MySQL behavior unchanged; SQLite
     widens via Laravel's native (no doctrine/dbal) `Schema::table()->column->change()` column rebuild.
  5. **Login enumeration** — `LoginController::identify()`'s redirect target revealed whether a username/email
     belonged to an active account and whether it used PIN or password login. Fixed: an unknown/inactive username
     now proceeds through the same two-step flow as a real one (a deterministic pseudo-type picks the step), and
     that step always fails with the same generic message, timing, and rate limiting a real wrong credential
     produces.
  6. **POS lifecycle — a `draft` order could be closed directly**, skipping the required `confirmed` step and
     settling/charging/deducting stock early. `OrderService::close()` now requires the new `Order::isConfirmed()`
     specifically (folded into the same rewrite as fix #1, since both needed the locked-row restructuring).
- New tests: `tests/Feature/Auth/LoginEnumerationTest.php` (7 methods, new file); 5 new methods in
  `OrderCrossOutletTest`; 1 new method in `OrderTest` (`test_a_draft_order_cannot_be_closed`); 1 new method in
  `PurchaseOrderTest`; 7 pre-existing POS tests updated to confirm an order before closing it. Net: 63 test files,
  375 methods (up from 62/361) — see `TEST_REPORT.md` §2.2/§3.1. **Written, execution NOT VERIFIED here** —
  `composer install` was retried and still fails with the same `repo.packagist.org` 403 policy denial; standing
  sandbox limitation, see `TEST_REPORT.md`.
- Real, reproducible evidence: `php -l` clean across the whole `app/`/`database/`/`resources/`/`tests/` tree;
  `verify_routes.py` still reports 0 duplicate route names and 0 unresolved `route()` references (no routes were
  added or renamed this round).
- No authorization, audit logging, accounting-day locking, or existing approval control was loosened — every
  change adds a rejection or replaces a distinguishable failure with an indistinguishable one (the login fix). No
  unrelated behavior changed.

**Phase 9.8 — Documentation, a final security re-audit, and packaging (Phase 9 now fully complete)**
- Per `PHASE9_AUDIT_AND_PLAN.md` §6's original plan for this sub-phase: five new documents —
  `INSTALLATION.md` (local dev, shared-hosting production, and the two-node hybrid install sequence),
  `HYBRID_ARCHITECTURE.md` (the full sync-engine design), `USER_GUIDE.md` (every role's day-to-day workflows),
  `SECURITY.md` (this sub-phase's re-audit, consolidated with — not replacing — `SECURITY_AUDIT.md`'s Phase 7
  audit and Phase 9.0 addendum), and `TEST_REPORT.md` (the complete, honest test inventory: 62 files, 361
  methods, zero executed, with exact commands to get real numbers) — plus an honest Readiness Rating appended as
  `PHASE9_AUDIT_AND_PLAN.md` §10.
- **A dedicated security re-audit ran first**, following this project's standing practice (Phase 7, then the
  Phase 9.0 addendum) of fixing what a re-audit finds rather than only writing it down. Scoped to everything built
  since the Phase 9.0 addendum: admin screens, reports, dashboard, the hybrid sync engine (given the most
  attention as the newest, most security-sensitive surface in the project), Front Desk POS, and complimentary
  items/stays. Found and fixed 3 genuine gaps plus 1 defensive hardening item:
  1. **Cross-outlet IDOR in `Pos\OrderController`** — every action authorized against the URL's `{outlet}`
     string, never against the route-bound `$order`'s actual outlet (an unscoped route-model binding), letting
     any outlet's POS staff view/modify/close/cancel another outlet's orders by substituting an order ID —
     directly contradicting the controller's own docblock. Fixed: `authorizeOutlet()` now also verifies
     `$order->outlet === $outlet`; `removeItem()` additionally verifies the item belongs to the bound order.
  2. **Sync engine — User credentials could leave an installation, and be overwritten, via a sync payload** — the
     most serious finding. `SyncService::exportableAttributes()` bypassed `User::$hidden`, so `password`/
     `pin_hash`/`totp_secret`/`totp_recovery_codes` travelled in every outbound sync payload and could sit in
     cleartext JSON in `sync_conflicts`, visible to any `manage-sync` holder (Duty Manager included); combined
     with `applyUpsert()`'s unrestricted `forceFill()`, a compromised `sync_devices.shared_secret` could directly
     rewrite another account's credentials — full account takeover. Fixed with a new
     `SyncableModels::PROTECTED_ATTRIBUTES` mechanism applied at every point data crosses an installation
     boundary (export, apply, and conflict-resolution apply) — three independent layers, not just one. Full
     design writeup in `HYBRID_ARCHITECTURE.md` §6. The practical effect: user accounts are still provisioned
     identically on both installations by the seeder, but credentials are now managed **per-installation** from
     that point on.
  3. **Hotel-logo upload accepted unsanitized SVG** (the first file-upload feature in this codebase — Phase 7's
     "not applicable" conclusion no longer holds). A crafted `logo.svg` with inline `<script>` would be accepted
     and served at a direct URL — a stored-XSS vector if that URL were ever opened directly. Fixed: dropped `svg`
     from the upload's `mimes:` allowlist.
  4. *(defensive hardening, not an exploitable gap given existing permission gates)* **CSV exports had no
     formula-injection neutralization** — a cell starting with `=`/`+`/`-`/`@` is interpreted as a formula by
     Excel/Sheets/LibreOffice when opened. Fixed in `ExportsCsv::csvResponse()`: any such leading character is now
     apostrophe-prefixed, forcing plain-text display with no visible change to the value.
- New tests: `tests/Feature/Pos/OrderCrossOutletTest.php` (6 methods) and `tests/Unit/Http/ExportsCsvTest.php`
  (3 methods); 2 new methods in `tests/Unit/Sync/SyncServiceTest.php` and 1 in
  `tests/Unit/Sync/SyncConflictResolverTest.php` proving the credential-protection fix from three angles
  (outbound payload, an adversarial incoming payload, and conflict resolution). **Written, execution NOT VERIFIED
  here** — standing sandbox limitation, see `TEST_REPORT.md`.
- Real, reproducible evidence: `php -l` clean across all 306 non-Blade PHP files (up from 304); `verify_routes.py`
  still reports 190 named routes, unchanged, 0 duplicates, 0 unresolved references (every fix this sub-phase
  changed authorization/validation logic, not routing).

**Complimentary items & complimentary stays (added post-9.7.6, out of band with the sync-engine plan)**
- Added at the user's explicit request — "some complimented [complimentary] drink orders at the bar and also
  some special guest lodging" — then scoped precisely via two follow-up clarifications: comps are **per-item**,
  not per-order ("the complimentary is free but the main d[r]ink is not"), and a comp stay waives **only the room
  charge** ("room stay complimentary, extras still billed"). Full design decision and evidence in
  `PHASE9_AUDIT_AND_PLAN.md` §9.
- `OrderItem` gains `is_complimentary`/`comp_reason`. `OrderService::addItem()` accepts a comp flag + mandatory
  reason and stores the comped line's `line_total` as `0.00` while preserving `unit_price` for reporting —
  `recalculateTotals()` needed **zero changes**, since it already just sums `line_total`. No new permission: comping
  reuses the existing `operate-{outlet}-pos` permission every outlet staff member already holds, matching the
  existing `removeItem()` "just needs a reason" precedent. Audit action is `pos.item_comped`, distinct from the
  ordinary `pos.item_added`.
- `OrderService::close()`'s payment-method-or-stay requirement is now skipped when the order's total is exactly
  `0.00` (`bccomp()`, the same precise-decimal check already used elsewhere in this codebase) — a fully-comped
  order can close with no payment and no ledger entry; a non-zero order still enforces the original rule
  unchanged (regression-guarded by a dedicated test).
- `Stay` gains `is_complimentary`, `complimentary_reason`, `marked_complimentary_by`, `marked_complimentary_at`.
  New permission `mark-stay-complimentary` (62nd permission — see the updated matrix in §9), granted to Duty
  Manager/GM/Owner and **deliberately not** Front Desk, mirroring this project's separation-of-duties pattern
  elsewhere (e.g. `approve-expense` vs `manage-expenses`) — a bigger revenue decision than a POS comp gets its own
  gate. New `StayController::markComplimentary()`/`unmarkComplimentary()` actions and routes
  (`stays.mark-complimentary`/`stays.unmark-complimentary`), each audit-logged.
- Two deliberately different enforcement points, matching this project's existing "checkout must never be
  blocked" principle: `FolioService::postCharge()` **throws** a clear error if staff manually try to post a
  `room_charge` against a comp stay's folio (a human mistake worth stopping); `CheckOutService`'s own automatic
  final-night `room_charge` post is **silently skipped**, never throws, for a comp stay (an automatic post must
  never block checkout). Every other charge type — bar/restaurant/front_desk/other_service — is untouched by
  either mechanism and posts exactly as normal.
- Views: a purple "Comp" badge on comped POS order-item rows and on the active-stays list; a "Complimentary stay"
  badge + waiver note on a stay's own page; a mark/unmark-complimentary form gated by `@can('mark-stay-complimentary')`;
  the room-charge dropdown option and the checkout "post final night" checkbox both disable with an explanatory
  note on a comp stay. Caught and fixed along the way: the "Post charge" dropdown on a stay's page was missing the
  `front_desk_charge` option entirely (a pre-existing gap from the §8 Front Desk petty-sales work, unrelated to
  this feature but found while touching the same view).
- New `tests/Feature/Pos/ComplimentaryOrderTest.php` (6 methods) and `tests/Feature/FrontOffice/StayComplimentaryTest.php`
  (9 methods), **written, execution NOT VERIFIED here** — standing sandbox limitation, see `PHASE9_AUDIT_AND_PLAN.md`
  §7/§9. Each file includes at least one explicit regression guard (a non-comp order still requires a payment
  method/stay; a non-comp stay still posts its final-night charge normally) since this feature touches shared,
  heavily-exercised code (`OrderService`, `CheckOutService`).
- Real, reproducible evidence: `php -l` clean across all 304 non-Blade PHP files (up from 301); `php -l` and
  balanced directive counts on the three edited Blade views; `verify_routes.py` now reports **190 named routes**
  (188 → 190, exactly the 2 new `stays.mark-complimentary`/`stays.unmark-complimentary` routes), no duplicates, no
  dead references.

**Phase 9.7.6 — Hybrid sync engine: sync dashboard, conflict resolution, and the deferred integrity check (Phase 9.7 now fully complete)**
- New `App\Http\Controllers\Admin\SyncDashboardController`, gated by a new `manage-sync` permission (Owner/GM/Duty
  Manager), routed `admin/sync` / `admin.sync.*` (deliberately distinct from `routes/sync.php`'s own `sync.*`
  names, which belong to the machine-to-machine transport, not this human-facing screen) — the same
  `admin/*`-prefixed, permission-middleware pattern already used for Users/Departments/Settings/Room Categories.
  `GET admin/sync` lists every registered device (active/revoked, last-seen, both checkpoints' last-synced times,
  pending-conflict count); `GET admin/sync/conflicts` lists every unresolved conflict with local vs. incoming
  data shown side by side; `POST admin/sync/conflicts/{conflict}/resolve` is the only place a conflict can
  actually be resolved.
- New `App\Services\Sync\SyncConflictResolver` — the sole writer of `sync_conflicts.resolved_*` (mirroring the
  `AuditLogger`/`SyncLogger` single-writer convention). Four resolutions, matching the column's enum set back in
  Phase 9.7.2 but unused until now: `kept_local` (no data change — the conflicting apply never touched the local
  row in the first place), `kept_incoming` (the only one that mutates data: applies the held-back incoming
  payload, using the exact same excluded-attribute list `SyncService::applyUpsert()` itself uses), `merged` (a
  human already reconciled it by hand outside this flow — no automatic field merge is attempted), and `ignored`
  (leave both versions as history). Every resolution writes an audit log entry.
- `DataIntegrityCheckerService` gains an 8th check, `noStaleSyncConflicts()`: flags a conflict unresolved more
  than 7 days after detection — deliberately deferred from Phase 9.6 (see that entry below), since `sync_conflicts`
  didn't exist until Phase 9.7.2. Neither `integrity:check` nor the deploy helper's integrity page needed any
  change to pick this up — both already iterate whatever the service's `run()` returns.
- New permission `manage-sync` (see the updated matrix in §9), and a gated nav link placed in the existing
  Administration section.
- 3 new test files, 17 new/added test methods: `tests/Unit/Sync/SyncConflictResolverTest.php` (7 — every
  resolution's data-mutation behavior, an already-resolved conflict rejected, an unknown resolution rejected,
  `kept_incoming` against a since-deleted local row fails loudly), `tests/Feature/Admin/SyncDashboardTest.php` (6
  — real data rendered on both pages, resolving via HTTP actually applies the incoming version, Duty Manager vs.
  Front Desk access, a `@dataProvider` sweep of every other role), plus 2 new methods in the existing
  `DataIntegrityCheckerServiceTest.php` (a stale conflict is detected; a recent-but-unresolved one and an
  old-but-resolved one both correctly do not count).
- Real, reproducible evidence: `php -l` clean across all 301 non-Blade PHP files (up from 297); `verify_routes.py`
  now 188 named routes (185 → 188, exactly the 3 new `admin.sync.*` routes), no duplicates, no dead references.
  **All 6 of Phase 9.7's sub-phases are now done — only Phase 9.8 (documentation + final packaging) remains for
  the whole of Phase 9.**

**Phase 9.7.5 — Hybrid sync engine: scheduled push/pull entry point (`sync:run`)**
- New `App\Services\Sync\SyncRunner::runFor(SyncDevice $device)` — no new sync logic, purely HTTP orchestration
  on top of the already-tested `SyncService` (9.7.3) and the already-tested transport (9.7.4): **push** builds
  this installation's outbox for the device and, only if it's non-empty, POSTs it to the device's own `/sync/push`
  and acknowledges locally once confirmed; an empty outbox is never sent over the network at all, but is still
  acknowledged locally so the checkpoint advances and the next run doesn't re-scan every syncable table from
  scratch. **pull** always calls the device's `/sync/pull` and `/sync/acknowledge`, even when the reply has zero
  items — skipping the acknowledge on an empty reply would leave the counterpart's own outbound checkpoint
  stuck, forcing it to re-send its entire dataset on every future pull.
- New `php artisan sync:run` (auto-discovered, no `Kernel.php` registration) — the real cron entry point: runs
  against every `is_active` device that has an `endpoint_url` set (one with no endpoint can still receive an
  incoming call from its counterpart; it just can't initiate one from here, and is skipped with a plain note
  rather than silently ignored), supports `--device=` to target specific device_key(s), prints a per-device
  pushed/pulled count, and exits non-zero if any device's push or pull failed — the same cron/CI contract
  `integrity:check` (Phase 9.6) already established.
- Uses Laravel's `Http` facade (Guzzle-backed, already a `laravel/framework` dependency — no new Composer
  package) for the three outgoing calls.
- A real bug this sub-phase's own `php -l` check caught immediately: the command's docblock originally included
  a literal crontab example containing `*/5 * * * *`, and PHP's block-comment parser treats the first `*/` it
  encounters as the comment's closing delimiter regardless of intent — the docblock closed early and the rest
  became a parse error. Reworded (there is no escape sequence for `*/` inside a `/* */` comment) rather than
  patched around.
- New `tests/Unit/Sync/SyncRunnerTest.php` (6 methods) and `tests/Feature/Console/SyncRunTest.php` (5 methods),
  **written, execution NOT VERIFIED here** — standing sandbox limitation, see `PHASE9_AUDIT_AND_PLAN.md` §7.
  Every `Http::fake()` call includes an explicit wildcard fallback returning a deterministic failing response, so
  a URL-pattern-matching surprise would show up as a clear local assertion failure rather than an attempted real
  network call.
- Real, reproducible evidence: `php -l` clean across all 297 non-Blade PHP files (up from 293); `verify_routes.py`
  still 185 named routes, no duplicates, no dead references (unchanged — this sub-phase calls the existing
  `/sync/*` endpoints as an HTTP client, it registers no new ones).

**Front Desk petty sales (added post-9.7.4, out of band with the sync-engine plan) — new outlet on the existing POS module**
- Added at the user's explicit request: "the front desk (receptionist) will also be doing petty sale of things
  like juice, water, snacks, biscuits etc." Not part of the original 8-role spec (`front_desk` had no POS
  permission), and not part of the Phase 9.7 sync-engine scope — recorded here as its own entry, full design
  decision and evidence in `PHASE9_AUDIT_AND_PLAN.md` §8.
- The existing Bar/Restaurant POS module (`Pos\OrderController`/`OrderService`, `Order`, `OrderItem`, `MenuItem`)
  was already outlet-parametrized (dynamic `operate-{outlet}-pos` permission, `WHERE outlet = ?` everywhere), so a
  third outlet is additive: no new controller, service, or authorization mechanism. `front_desk` orders never set
  `pos_table_id` (already nullable) since a reception counter has no seating.
- **Real bug found and fixed while doing this**: `OrderService::close()` hardcoded the folio-deferral charge type
  to exactly `bar_charge`/`restaurant_charge` (`$order->outlet === 'bar' ? ... : ...`), which would have silently
  mis-posted a Front Desk deferral as a restaurant charge. Generalized to `"{$order->outlet}_charge"` —
  backward-compatible for the two existing outlets, correct for any new one. `FolioTransaction::CHARGE_TYPES` and
  `FolioController::charge()`'s validation both gained `front_desk_charge` to match.
- New migration widens the MySQL `ENUM` columns on `menu_categories`/`menu_items`/`pos_tables`/`orders.outlet` and
  `folio_transactions.type` (raw `ALTER TABLE ... MODIFY COLUMN`, since Laravel's schema builder can't widen an
  `enum()` in place without `doctrine/dbal`) — every existing value preserved, `front_desk`/`front_desk_charge`
  appended.
- New permission `operate-front_desk-pos` (61st permission), granted to Owner/GM (via the existing `$all`/`array_diff`
  grants), Duty Manager (parity with its existing bar/restaurant POS access), and Front Desk — see the updated
  matrix in §9. `routes/web.php`'s `pos/{outlet}` constraint widened from `bar|restaurant` to
  `bar|restaurant|front_desk` (route *names* unchanged).
- `PosSeeder` gained a `front_desk` → "Petty Sales" category (Bottled Water, Fruit Juice, Soft Drink, Biscuits,
  Snacks) with no `PosTable` rows (counter service, no tables). Nav gained a permission-gated "Front Desk Sales"
  link. Three view titles that used `ucfirst($outlet)` (would have rendered "Front_desk POS") switched to
  `Str::headline($outlet)` ("Front Desk POS"), backward-compatible for the existing two outlets.
- New `tests/Feature/Pos/FrontDeskPettySaleTest.php` (4 methods, **written, execution NOT VERIFIED here** — see
  §7/§8 of the plan doc for the standing no-PHPUnit sandbox limitation): open/add/confirm/close a table-less order
  with cash, defer a petty sale onto a guest's room folio and confirm it posts specifically as `front_desk_charge`
  (the regression this guards against), and role-scoping in both directions (Bar Staff 403s on Front Desk POS,
  Front Desk 403s on Bar POS).
- Real, reproducible evidence: `php -l` clean across all 293 non-Blade PHP files (up from 291); `verify_routes.py`
  still reports 185 named routes, no duplicates, no dead references (unchanged, since only the `{outlet}` route
  *parameter*'s matching pattern widened, not any route name).

**Phase 9.7.4 — Hybrid sync engine: device model correction + push/pull/acknowledge transport**
- **Correction to Phase 9.7.2, made before anything here ever ran against a real database**: designing the
  transport surfaced a real flaw in the original device model — `secret_hash` (bcrypt) can verify a secret
  presented to it but can never be retrieved again, so an installation would have had no way to present its own
  secret when it INITIATES a call to its counterpart. `sync_devices.secret_hash` + `is_local` are replaced with a
  single `shared_secret` column, encrypted at rest via Laravel's `encrypted` model cast (retrievable with the
  app's own APP_KEY, unlike a hash) — one shared secret per counterpart relationship, known by both sides. There
  is no more "self" row: every row is symmetric.
- `DeviceAuthService::register()` still generates a fresh device_key + secret and prints it once; a new
  `import()` method records an EXACT given key/secret pair instead of generating one, for the other installation's
  operator to use once they've been handed the values `register()` printed — deliberately not a network handshake
  (there is no automatic way for two installations to establish trust with each other; a human still hand-carries
  the secret between them, the same trust model already used for the deploy helper's `DEPLOY_TOKEN`). A new
  `credentialsFor()` returns what this installation should present when it calls a given counterpart.
  `sync:register-device` gained `--import-key`/`--import-secret`; its old `--local` option is gone.
- New `routes/sync.php` (required from `bootstrap/app.php`'s `then:` callback, outside the `web` middleware
  group — the same reasoning as `routes/deploy.php`: this is server-to-server JSON traffic with no browser and no
  session on either end, so CSRF verification would only ever reject a legitimate request) and
  `App\Http\Controllers\Sync\SyncTransportController`, three endpoints:
  - `POST /sync/push` — applies the caller's outbound batch here via the already-tested `SyncService::applyIncomingBatch()`, returns a per-status (applied/conflicts/skipped/failed) breakdown. Conflicts/failures in the batch are a normal 200 response, not a transport error.
  - `POST /sync/pull` — builds and returns this installation's outbound batch via `SyncService::buildOutboxBatch()`, without advancing the checkpoint yet.
  - `POST /sync/acknowledge` — confirms a pulled batch was received, advancing the checkpoint via `SyncService::acknowledgeOutboxBatch()`. Idempotent: re-acknowledging an already-applied batch is a silent no-op.
  Every route authenticates via `DeviceAuthService::authenticate()` with a generic 401 on any failure (never a
  hint about which half was wrong); the controller contains no sync logic of its own, only auth + delegation.
- One robustness fix flowed back into `SyncService` from exposing it to real network input for the first time:
  `applyIncomingBatch()` now guards against a malformed (non-object) item in the incoming array, recording it as
  its own failed item instead of letting a PHP `TypeError` abort every other item in the same batch.
- New `tests/Feature/Sync/SyncTransportTest.php` (7 methods, hitting the real HTTP routes) plus revisions to three
  existing test files for the corrected device model (see §7 for the full list). See `PHASE9_AUDIT_AND_PLAN.md`
  §7 for the complete evidence: `php -l` clean across 291 non-Blade PHP files (plus `bootstrap/app.php` checked
  separately), `verify_routes.py` (extended this sub-phase to also read `routes/sync.php`) confirming 185 named
  routes (182 → 185, exactly the 3 new ones), no duplicates, no dead references. As with every phase before it,
  none of the new/revised test methods have actually executed — no `vendor/`, Packagist still blocked.

**Phase 9.7.3 — Hybrid sync engine: SyncService (outbox, idempotent apply, conflict detection)**
- New `App\Services\Sync\SyncableModels::ALL` — the single canonical list of the 48 syncable model classes from
  Phase 9.7.1, so the outbox scan, the ulid backfill command, and any future caller share one list instead of
  each keeping their own copy that could drift out of sync with the migration.
- New `php artisan sync:backfill-ulids` — a necessary companion to Phase 9.7.1 that was missing until now:
  `HasSyncUlid` only assigns a ulid when a row is created, so every row that existed before that migration ran has
  `ulid = NULL` and would be invisible to the outbox scan below. Assigns one to every such row across all 48
  tables, once, via the query builder (not Eloquent, so it never fires 48 model classes' worth of events).
- New `App\Services\Sync\SyncService`, the engine itself:
  - `buildOutboxBatch(SyncDevice $device)` scans all 48 tables for rows changed since that device's outbound
    checkpoint, packages them into a `SyncBatch` + one `SyncItem` per row. Rows with no ulid yet are excluded
    (see the backfill command above) rather than assigned one mid-scan, which would risk two installations
    minting two different identities for the same pre-existing row. `acknowledgeOutboxBatch()` is a separate,
    explicit step — the checkpoint only advances once the (not-yet-built) transport confirms delivery.
  - `applyIncomingBatch(SyncDevice $fromDevice, string $batchUlid, array $items)` is genuinely idempotent: replaying
    the same `$batchUlid` a second time — proven by a test that even changes the payload data on the replay — is
    a confirmed no-op. Each item applies inside its own error boundary, so one bad/unknown item fails only that
    item, never the rest of the batch.
  - **The conflict rule**: before applying an incoming update, the local row's own `updated_at` is checked against
    the last time a batch from that same device was successfully processed. If the local row changed since then,
    the incoming change is held back entirely — the local row is never touched — and both versions are recorded
    in a new `SyncConflict` row for a human to resolve later (Phase 9.7.6's dashboard). Proven end-to-end: sync a
    row in, edit it locally, sync a conflicting change in from the same device, and confirm the local edit
    survives untouched.
  - Deletion is deliberately conservative: only the two soft-deletable models (Guest, User) propagate a delete
    automatically (the row still exists to carry a `deleted_at`); every other model has no tombstone at all, so
    this design cannot detect a hard delete on the sending side in the first place — `buildOutboxBatch()` never
    emits one for them, and if a delete operation somehow arrives anyway it is skipped with a plain-text reason
    rather than automatically hard-deleting a business/financial record.
  - Three more deliberate choices inside the apply step, all documented in the code: the local auto-increment
    `id` is never copied across installations; `updated_at` is left to Eloquent's own timestamp management
    (not copied from the sender) specifically because that is what keeps the conflict check meaningful and
    sidesteps clock skew between two physical servers; an existing row's `created_at` is never rewritten.
- 3 new test files, 18 new test methods total (see §7 for the full list). See `PHASE9_AUDIT_AND_PLAN.md` §7 for
  the complete evidence, including an explicit flag that this is the largest, most logic-dense file shipped in
  Phase 9 to date and the single highest-priority target for a real `php artisan test` run the moment `vendor/`
  exists: `php -l` clean across all 288 non-Blade PHP files, `verify_routes.py` confirming zero routes touched
  (still 182). As with every phase before it, the 18 new test methods are written and manually traced but **not
  executed** — no `vendor/`, Packagist still blocked.

**Phase 9.7.2 — Hybrid sync engine: sync registry tables + device authentication**
- 6 new tables (`sync_devices`, `sync_checkpoints`, `sync_batches`, `sync_items`, `sync_conflicts`, `sync_logs`),
  each with a matching `App\Models\Sync*` model: `sync_devices` is one row per local-server/cloud-VPS
  installation (`device_key` public, `secret_hash` never plaintext, `is_local` distinguishes this installation's
  own row from a counterpart's, `is_active` supports revocation); `sync_checkpoints` is one row per device per
  direction recording where sync last left off; `sync_batches` is one row per batch attempt keyed by its own
  `ulid` — the idempotency key a future apply step checks before ever re-applying a replayed batch;
  `sync_items` is one row per synced record within a batch, referencing it polymorphically by `syncable_type` +
  `syncable_ulid` (the same pattern `audit_logs.record_type`/`record_id` already uses, since the record can live
  in any of the 48 syncable tables from Phase 9.7.1); `sync_conflicts` is created when a future apply step finds a
  row changed on both sides since the last sync, holding both snapshots and a `resolution` field rather than
  silently overwriting; `sync_logs` is a general append-only event log, deliberately mirroring `audit_logs`' own
  shape and single-writer convention. None of these six uses the `HasSyncUlid` trait from 9.7.1 — they are the
  sync engine's own bookkeeping about itself, created once locally, never themselves replicated as business data.
- New `App\Services\Sync\DeviceAuthService`: reuses this codebase's existing `Hash::make()`/`Hash::check()` secret
  convention (the same one `users.password`/`users.pin_hash` already use) rather than the deploy helper's
  plaintext-env-var-plus-`hash_equals()` pattern — a deliberate strengthening, not a copy, made possible because a
  sync device's secret (unlike the deploy helper's token) never needs to work before the database schema exists.
  `register()` returns the plaintext secret exactly once and never persists it; `authenticate()` returns null
  (never throws, never reveals which half was wrong) for a bad key, bad secret, or revoked device.
- New `php artisan sync:register-device {name} {--local} {--endpoint=}` creates a device row and prints its
  `device_key` and one-time secret — this sub-phase only creates the local database row; it does not yet exchange
  anything over a network with the other installation (that's a later sub-phase, once the transport exists). New
  `App\Services\Sync\SyncLogger` (info/warning/error) is the sole writer of `sync_logs`, mirroring
  `App\Services\AuditLogger`'s existing single-writer shape.
- 3 new test files, 15 new test methods total (see §7 for the full list). See `PHASE9_AUDIT_AND_PLAN.md` §7 for
  the complete evidence: `php -l` clean across all 282 non-Blade PHP files, a script confirming every new
  migration's foreign-key target table exists by the time it's referenced, `verify_routes.py` confirming this
  sub-phase touched zero routes (still 182, unchanged). As with every phase before it, the 15 new test methods are
  written and manually traced but **not executed** — no `vendor/`, Packagist still blocked.

**Phase 9.7.1 — Hybrid sync engine: global identifiers (schema foundation)**
- Phase 9.7 (the hybrid sync engine, `PHASE9_AUDIT_AND_PLAN.md` §3.1) was re-scoped into 6 sub-phases before any
  code was written, per that section's own note that it was too large to fully pre-specify up front. This
  delivery is sub-phase 1 of 6: global identifiers, the foundation every later sub-phase depends on.
- New migration `2025_01_02_000100_add_ulid_to_syncable_tables.php` adds a nullable, unique `ulid` column
  (Laravel's `$table->ulid()` type — a fixed 26-character, time-sortable string) to **48 tables**, each guarded by
  `Schema::hasColumn()` so it's safe to re-run. Purely additive — no existing primary key, foreign key, or
  relationship anywhere in the schema was touched. The 48 are every table backing an `App\Models\*` class except
  one: `Setting` is deliberately excluded (settings are few, change rarely, and are administered per-installation
  for now — a scoped decision, documented in `PHASE9_AUDIT_AND_PLAN.md` §6, not an oversight). Framework tables
  (`cache`, `jobs`, `migrations`) and spatie/laravel-permission's role/permission tables are excluded too, since
  roles are fixed configuration provisioned identically on both installations by the seeder, not per-hotel data
  either side independently creates.
- New `App\Services\Sync\Concerns\HasSyncUlid` trait, applied to all 48 models: auto-assigns a ULID in a
  `creating` listener. `ulid` is never added to any `$fillable` array — it's system-generated only, assigned via
  direct attribute assignment (which bypasses mass-assignment protection, unlike `create()`/`fill()`), so nothing
  external can overwrite a row's sync identity by simply including a `ulid` key. The listener only assigns a new
  value `if empty($model->ulid)` — a value already set before save() survives, which is exactly what the sync
  engine's future apply step needs when replaying a row that already has a ULID from the other installation. A
  `scopeWhereUlid()` helper is included for that same future lookup.
- The trait was wired into all 48 model files with a small, idempotent, re-runnable script rather than by hand
  (kept in the scratchpad, not part of the shipped codebase) — then every result was verified mechanically rather
  than trusted: `php -l` on every touched file individually, a grep confirming exactly 48 of 49 models reference
  the trait (`Setting` the sole exclusion), and a grep confirming `ulid` appears in zero `$fillable` arrays.
- New test file `tests/Unit/Sync/HasSyncUlidTest.php`, 7 methods (see §7 for the full list). See
  `PHASE9_AUDIT_AND_PLAN.md` §7 for the complete evidence: `php -l` clean across all 264 non-Blade PHP files,
  `verify_routes.py` confirming this sub-phase touched zero routes (still 182, unchanged), and — as with every
  phase before it — the 7 new test methods are written and manually traced but **not executed**, since this
  sandbox still has no `vendor/` and Packagist is still blocked (re-confirmed again this sub-phase).

**Phase 9.6 — Data Integrity Checker**
- New `App\Services\Integrity\DataIntegrityCheckerService::run(): array` — 7 read-only checks, each reusing an
  existing model method instead of recomputing its own copy of the same logic: folio balances vs.
  `Folio::recomputeBalance()`'s ledger total; every `AccountsReceivable` ties to a real folio; no
  `AccountsReceivable` owes more than its `original_amount`; every stay has a folio; a room's `status` agrees with
  whether it actually has an active stay (checked both directions); no `StockBatch` has gone negative; every
  purchase order marked `received` satisfies `PurchaseOrder::isFullyReceived()`. Every check returns
  `{key, label, passed, issue_count, issues[]}`; nothing here writes to the database.
- New `php artisan integrity:check` (`app/Console/Commands/RunIntegrityCheck.php`, auto-discovered — no
  `Kernel.php` registration needed on Laravel 12), a real cron/CI-usable command that prints a PASS/FAIL line per
  check plus each issue found, and exits non-zero if anything failed. An early draft included a
  `--fail-on-warning` flag whose own description admitted it was a no-op — removed before this was called done,
  since shipping a flag that visibly does nothing is a small dishonesty this project is otherwise careful to avoid.
- Wired into the deploy helper (§3.10 of `PHASE9_AUDIT_AND_PLAN.md`) as a new read-only step:
  `DeployHelperController::integrityCheck()`, routed `GET deploy-helper.integrity-check` (GET, not POST, like
  migrate/seed/clear-caches — it never writes anything), checks `Schema::hasTable('folios')` first so it fails
  gracefully with a plain message on an unmigrated database rather than surfacing a raw SQL error, and renders a
  new `deploy-helper/integrity-result.blade.php` with a PASS/FAIL badge and issue list per check. The deploy
  helper's index page gained a "4. Run a data integrity check" section (the old step 4, "Disable this helper," is
  now step 5).
- 3 new test files, 14 new test methods total: `tests/Unit/Integrity/DataIntegrityCheckerServiceTest.php` (9 —
  one clean-baseline pass-everything case, one corrupting each of the 7 invariants in turn),
  `tests/Feature/Console/RunIntegrityCheckTest.php` (2 — exit code 0/non-zero and the matching console text),
  plus 3 methods appended to `tests/Feature/DeployHelperTest.php` (PASS rendering, an ISSUE badge on a real
  corrupted record, still 404s without a valid flag+token). See `PHASE9_AUDIT_AND_PLAN.md` §7 for the full
  evidence: `php -l` clean across all 261 non-Blade PHP files, Blade directive-balance clean on the 2
  new/edited views, `verify_routes.py` confirming 182 named routes (181 → 182, exactly the one new route),
  zero duplicates, zero dead route references. As with every phase before it, the 14 new test methods are
  written and manually traced but **not executed** — this sandbox has no `vendor/` and Packagist is blocked
  (re-confirmed again this phase), so `php artisan test` cannot run here; see §7 for the exact commands to run
  for real pass/fail numbers.

**Phase 9.5 — Printable documents + CSV export**
- New bare `resources/views/layouts/print.blade.php`: no sidebar/nav/session-flash chrome, hotel name/address/phone
  from `SettingsService` in the header, a "Print / Save as PDF" button, `@media print` CSS. Every one of the 11
  named documents from the master prompt's checklist extends this layout instead of `layouts.app`.
- Reservation Confirmation (`ReservationController::print()`, `reservations.print`), Guest Invoice + Final Hotel
  Bill (`StayController::printInvoice()`/`printFinalBill()`, sharing one `folio-print` partial — the same folio
  ledger at two points in a stay's lifecycle, never two independently-computed totals; the final bill genuinely
  throws `HmsException` until the stay's status is `closed`, not just a cosmetically-hidden link), Payment Receipt
  (`FolioController::printReceipt()`, 404s for anything that isn't a `payment`-type transaction, reachable by
  whoever holds `manage-guests` OR `post-folio-payment` since Cashier holds the latter but not the former),
  Restaurant Receipt + Bar Receipt (`OrderController::printReceipt()`, one view for both outlets, authorized via
  the same in-controller `authorizeOutlet()` every other order action already uses), Daily Cash Closing
  (`AccountingDayController::printCashClosing()`).
- Three new report screens in `ReportController` (each print-only variant + the interactive screen): Payment
  Summary and Expense Report are genuinely new — individual-row listings (every payment in the central ledger;
  every expense, not just its category total) backed by two new `FinancialReportService` methods
  (`paymentSummary()`, `expenseReport()`); Management Report combines `KpiDashboardService`'s headline tiles with
  the Financial report's categorized breakdowns on one page, computing nothing independently of those two services.
  Weekly Owner Summary (from Phase 9.4) got its own print view reusing the same service call.
- CSV export via a new `app/Http/Controllers/Concerns/ExportsCsv.php` trait — native `fputcsv` against a streamed
  response, genuinely zero new Composer dependencies. Wired to Payment Summary, Expense Report, and (retrofitted)
  Guest Stay History — the three reports that are row-per-line lists rather than aggregate/bucketed numbers, which
  is where a CSV export is an obvious fit; the bar-chart-shaped reports (Financial, KPI Dashboard, Occupancy) were
  deliberately left for a later pass rather than forcing an awkward CSV shape onto them in this one.
- **PDF export — resolved decision, not built as a dependency.** The master prompt's Phase 9.5 scope flagged PDF
  export as needing a new Composer package (e.g. `barryvdh/laravel-dompdf`). This sandbox cannot reach Packagist
  (same limitation as every phase — see §7), so adding that package to `composer.json` without ever being able to
  install or verify it would be an unverifiable claim of "done." Instead: every browser's native "Print → Save as
  PDF" produces a real PDF from the `layouts/print.blade.php` pages built this phase, today, with nothing further
  needed — that's what the "Print / Save as PDF" button in the layout's header actually does. A dedicated
  server-side PDF library (for unattended/bulk generation) remains a legitimate fast-follow for an environment with
  real Packagist access.
- 4 new test files (`tests/Feature/PrintableDocumentsTest.php` — 11 methods; `tests/Feature/Reporting/PaymentSummaryReportTest.php` — 4 methods; `tests/Feature/Reporting/ExpenseReportTest.php` — 4 methods; `tests/Feature/Reporting/ManagementReportTest.php` — 3 methods) plus methods appended to `OperationsReportTest.php` (+2, Guest Stay History CSV) and `WeeklyOwnerSummaryTest.php` (+2, its print view) — 26 new test methods, execution NOT VERIFIED here, same caveat as every phase before it; see §7. `php -l` clean on all 260 non-Blade PHP files (255 before this phase, +5: `ExportsCsv` + 4 test files) and on all 20 new/edited `.blade.php` files; directive-balance counts clean on the same 20 views; `verify_routes.py` confirms **181 named routes** (165 before, +16 — exactly the print/report/CSV routes added), zero duplicates, zero unresolved references.

**Phase 9.4 — Management Dashboard + Weekly Owner Summary**
- New `App\Services\Dashboard\PendingApprovalsService::forUser()`: checks all 9 `approve-*` permissions in the
  system (reservations, expenses via either `approve-expense` or `approve-large-expense`, refunds, maintenance,
  purchase orders, requisitions, wastage, leave, payroll) and returns a pending-item count + route for each one the
  caller personally holds — purely permission-driven, never role-name-driven, per the project's own stated
  "permission-scoped, not identity-scoped" design principle (`SECURITY_AUDIT.md` §5). Wired into
  `DashboardController`/`dashboard.blade.php` as a "Pending approvals" section that is naturally absent for any
  role with no approval duties (e.g. Cashier).
- Added a trailing-7-day financial summary widget to the dashboard, reusing
  `FinancialReportService::incomeStatement()` (the same method the Income Statement report uses) — gated
  specifically on `view-financial-reports`, deliberately narrower than the `view-financial-reports OR
  view-operational-reports` check already used for the Open Receivables card, since Duty Manager holds the latter
  but not the former.
- New `App\Services\Reporting\WeeklyOwnerSummaryService::forWeekEnding()`: the narrative-style "Weekly Owner
  Summary" the master prompt called for — combines `OperationsReportService::occupancy()` and
  `FinancialReportService::incomeStatement()`/`cashFlow()`/`receivablesAging()` for the 7 days ending a given date
  (defaults to today; `?week_ending=` selects a past week). Computes nothing independently, so it can never disagree
  with the detail reports it's built from. New `reports.weekly-owner-summary` route/view, linked from both the
  Reports index and the dashboard widget, carrying the same "management estimate, not a formal accounting
  statement" caveat as the Income Statement report.
- While verifying every route name the approvals widget references was actually reachable, found and fixed two
  real, pre-existing authorization dead ends (not introduced by this phase or any earlier one): `routes/web.php`'s
  `inventory.purchase-orders.index` was gated only on `manage-purchase-orders`, which Accountant does not hold
  (only `approve-purchase-order`) — Accountant could approve a PO by POST but never see a page listing one to
  approve. The identical problem existed for `inventory.requisitions.index` (gated only on `request-stock`) and
  Duty Manager (holds `approve-requisition`, not `request-stock`). Both fixed by extracting the `index` route out of
  its single-permission group into a standalone route accepting either permission
  (`manage-purchase-orders|approve-purchase-order`, `request-stock|approve-requisition`) — the same OR-permission
  pattern already used for `finance.expenses.index`/`finance.refunds.index`. Separately noted, but deliberately
  *not* changed: `approve-payroll-run` is currently held only by Owner/General Manager, which reads as an
  intentional control (payroll sign-off reserved above Accountant) rather than a bug, since Accountant can still
  reach `payroll-runs.index` via `manage-payroll` — nothing is unreachable there.
- 2 new test files (`tests/Feature/Dashboard/PendingApprovalsServiceTest.php`, 11 methods covering every permission
  branch; `tests/Feature/Reporting/WeeklyOwnerSummaryTest.php`, 5 methods) plus 9 authorization-regression methods
  appended to `DashboardAuthorizationTest.php`, `Inventory/PurchaseOrderTest.php`, and `Inventory/RequisitionTest.php`
  — 25 new test methods in total, execution NOT VERIFIED here, same caveat as every phase before it; see §7. `php -l`
  clean on all 255 non-Blade PHP files (251 before this phase, +4 new files); Blade directive-balance counts clean
  on all 3 new/edited views; `verify_routes.py` confirms **165 named routes** (164 before, +1 — the new
  `reports.weekly-owner-summary` route; the two route-gating fixes relocate an existing route rather than add one),
  zero duplicates, zero unresolved references — including the 10 route names referenced only dynamically (via a
  variable, not a literal string) inside `PendingApprovalsService` and the new report view, cross-checked by hand
  against `verify_routes.py`'s own reconstructed route table since its literal-string scanner cannot see those.

**Phase 9.3 — Financial reports: Income Statement + Guest Stay History**
- Cross-referencing `PHASE9_AUDIT_AND_PLAN.md` §3.4's checklist (Income Statement, Cash Flow, AR Aging, Guest Stay
  History, Revenue, Inventory) against the existing Reporting module found that Cash Flow, AR Aging, Revenue, and
  Inventory reports already existed (Phase 6) — only two were genuinely missing, so that's all this phase built.
- `FinancialReportService::incomeStatement()`: total revenue (accrual, from `revenueByChargeType()`) minus total
  expenses requested in the period (from `expenseSummary()`), explicitly documented as a **management-style
  approximation** per the resolved §4.1 decision — this system has no Chart of Accounts/General Ledger, so this is
  not a formal double-entry Income Statement, and the report screen itself carries that caveat visibly, not just in
  a code comment.
- `OperationsReportService::guestStayHistory()`: completed (closed) stays that checked out within a date range,
  with real per-stay charge/payment totals and an Accounts-Receivable flag — previously the only stay list in the
  system was the *active* stays screen, with no way to look up a guest's stay after checkout. Gated on
  `view-operational-reports`, which (per the Phase 9.0 principle) front-line roles including Front Desk do not
  hold, despite this report surfacing guest financial totals.
- New routes `reports.income-statement` and `reports.guest-stay-history`, both linked from the Reports index.
- 5 new test methods across the existing `FinancialReportTest.php`/`OperationsReportTest.php` (appended rather than
  new files, since these test the same two services those files already cover) — execution NOT VERIFIED here, same
  caveat as every phase before it; see §7. `php -l` clean on all 251 non-Blade PHP files (unchanged count — no new
  non-Blade PHP files this phase, only edits); `verify_routes.py` confirms **164 named routes** (162 before, +2
  exactly matching the 2 new routes), zero duplicates, zero unresolved references.

**Phase 9.2 — Administration + Settings + Departments + Room Categories UI**
- New `App\Http\Controllers\Admin\{UserController,DepartmentController,SettingsController,RoomCategoryController}`
  and `App\Services\Admin\{UserAdminService,DepartmentService}` — see `PHASE9_AUDIT_AND_PLAN.md` §3.2/§3.3 for why
  these didn't exist before (the permissions `manage-users`/`manage-settings`/`manage-departments` have been in
  `RolesAndPermissionsSeeder` since Phase 1, granted only to Owner/GM, but gated nothing until now).
- Users: create (PIN or password/TOTP), edit name/username/email/department/role, deactivate/reactivate
  (`is_active`, never a hard delete — past transactions/audit rows reference users by ID), reset password, reset
  PIN (also clears any lockout), and a standalone "clear PIN lockout" action — the admin-UI equivalent of the
  `php artisan tinker` workaround §12 (Troubleshooting) has documented since Phase 1. An admin cannot deactivate
  their own account. Every mutation is audit-logged (`admin.user_*`), unlike simpler CRUD (e.g. Guests) elsewhere
  in the project, because account/role/credential changes are exactly the "important mutation" `AuditLogger` exists
  for.
- Departments: full CRUD (create/edit/deactivate/reactivate) over the model that has existed since Phase 1.
- Settings: a grouped edit screen over every key in the `settings` table (finance/front_office/security/hr, plus a
  new `general` group for hotel name/address/phone/email), type-aware inputs (boolean/integer/decimal/string),
  `is_editable=false` rows shown read-only, and a logo upload (stored on the `public` disk — requires
  `php artisan storage:link` to have been run once).
- Room Categories: full CRUD over nightly rates/max-occupancy/description, gated on `manage-settings` rather than
  `manage-rooms` (a pricing decision, not day-to-day room operations) — deactivating a category never touches
  rooms already assigned to it.
- 4 new test files, 24 test methods (44 individual runs once `@dataProvider` role lists expand) — execution NOT
  VERIFIED here, same caveat as every phase before it; see §7. `php -l` clean on all 251 non-Blade PHP files in the
  tree (Blade template syntax is checked separately by directive-balance counting, since `php -l` does not
  meaningfully validate `.blade.php` files — see the Phase 9.2 evidence report for detail); `verify_routes.py`
  confirms **162 named routes, zero duplicates, zero unresolved references** (137 before this phase, +25 new
  Administration routes, exactly matching the routes added).

**Phase 9.0 — Authorization gap fixes (first sub-phase of the Phase 9 hybrid-HMS expansion)**
- Fixed 4 route-level authorization gaps found during the Phase 9 audit (predating Phase 9 itself — see
  `PHASE9_AUDIT_AND_PLAN.md` §3.8 and the "Phase 9.0 Addendum" in `SECURITY_AUDIT.md` for full detail):
  `rooms.index` (new `view-rooms` permission), `stays.index`/`stays.show` (`manage-guests`), `maintenance.index`
  (`report-maintenance`), and `DashboardController` (the `openReceivablesTotal` financial figure is now hidden
  from any role without `view-financial-reports`/`view-operational-reports`).
- 4 new test files, 12 test methods (42 individual runs once `@dataProvider` role lists expand) — execution NOT
  VERIFIED here, same caveat as every phase before it; see §7. `php -l` clean on all changed PHP files;
  `verify_routes.py` confirms 137 named routes, zero duplicates, zero unresolved references (unchanged from before
  this phase — only middleware was added, no routes were added/removed/renamed).
- `PHASE9_AUDIT_AND_PLAN.md` (new file): the full Phase 9 audit against the new master prompt's scope, the two
  major architectural decisions (financial reporting depth; hybrid deployment/environment assumptions) resolved
  with the project owner, and the phased implementation plan for the remaining Phase 9.1–9.8 work.

**Phase 8 — Shared-hosting deployment helper** (this delivery, and the last buildable item on the roadmap — see §1 and §11)
- `App\Http\Controllers\DeployHelperController`: a one-time, browser-based installer/migration runner at `/deploy-helper`, gated by `ENABLE_DEPLOY_HELPER=true` + a timing-safe (`hash_equals`) `DEPLOY_TOKEN` match, failing as a plain `404` (not `403`) either way — the shipped `.env.example` placeholder token is refused explicitly even if it somehow matches. A fixed menu of safe commands only (`migrate --force`, `db:seed --force`, `config:clear`/`cache:clear`/`route:clear`/`view:clear`) — no generic command runner, no destructive commands.
- Seeding refuses to run if the `users` table already has a row, unless explicitly confirmed, since most seeders in this project `create()` unconditionally and would duplicate demo data on a second run.
- `routes/deploy.php`, loaded via `bootstrap/app.php`'s new `withRouting(..., then: ...)` callback: registers these routes with none of Laravel's normal `web` middleware group (no session, no CSRF, no cookie handling), because this project's own `SESSION_DRIVER=database`/`CACHE_STORE=database` defaults would otherwise make every request here fail on a genuinely fresh database before a single migration has run. Authorization lives entirely inside the controller for the same reason.
- A best-effort, atomic (read → regex-replace → write-to-temp → rename) self-disable action that flips `ENABLE_DEPLOY_HELPER` back to `false` in `.env`, reporting plainly if the host's file permissions didn't allow it rather than pretending it worked.
- Every action logs to Laravel's file log (not `audit_logs`, which may not exist yet the first time this runs).
- `verify_routes.py` (the Phase 3-era route cross-check script) was extended to also parse `routes/deploy.php` alongside `routes/web.php`, since these routes are deliberately registered outside that file — confirms **137 total named routes, zero duplicates, zero unresolved references** across the whole Phase 1–8 codebase.
- 1 new test file, 9 test methods (execution NOT VERIFIED here — see §7). **NOT VERIFIED against a real shared-hosting account** — see §10 step 5's caveat: this sandbox can review the logic and unit-test it against its own already-migrated test database, but cannot try it against a truly fresh production MySQL database on real shared hosting, which is the one thing it exists to do.

**Phase 7 — Security audit pass**
- Manual, static code review across the entire Phase 1–6 codebase against the 8 areas named in the spec. Full writeup in the new `SECURITY_AUDIT.md`. This is not a substitute for an automated SAST/DAST scan or a real penetration test — this sandbox can't install one (same Packagist restriction documented in §7) — every finding here was traced to file/line evidence by hand.
- **Clean, no changes needed**: mass assignment (all 48 models declare `$fillable`/`$guarded`), raw SQL injection (only 3 hardcoded `selectRaw` aggregates anywhere, zero interpolated input), XSS (exactly one `{!! !!}` in the whole view tree, a TOTP QR code rendered as SVG paths, not text), CSRF (all 89 forms parsed programmatically, every mutating one has `@csrf`).
- **Not applicable**: file upload handling — no such feature exists anywhere in Phases 1–6.
- **3 IDOR/authorization findings, fixed**: `LeaveService::cancel()` now requires the actor own the leave request or hold `approve-leave` (previously any of the 8 roles could cancel any other employee's pending leave request — a real IDOR, since `request-leave` is universally granted). `LeaveService::approve()` and `ExpenseService::approve()` (and, for consistency, `RefundService::approve()`) now reject a self-approval outright — previously a single Duty Manager account could request and approve its own leave or small expense with zero second-party review, which an existing Phase 4 test had literally encoded as correct behavior; that test was rewritten to use two different Duty Manager accounts. `AttendanceController::index()` now shows the full attendance roster only to `record-attendance` holders — previously every role (via the universally-granted `clock-own-attendance`) could see every other employee's clock-in/out times.
- **1 rate-limiting gap, fixed**: `/login` (username lookup) and `/login/totp/setup` (first-time MFA code confirmation) had no throttling at all, unlike the password/TOTP-challenge/PIN steps which were already correctly protected. Fixed by wrapping the whole unauthenticated login route group in `throttle:20,1`.
- **2 secret-handling/config gaps, fixed**: `SESSION_SECURE_COOKIE` had no explicit default (session cookies could travel over plain HTTP even on an HTTPS deployment) — now defaults to `true` in `.env.example`. Separately, `.env.example` described `ENABLE_DEPLOY_HELPER`/`DEPLOY_TOKEN` as an active gate on a `DeployHelperController` that doesn't exist anywhere in the codebase yet — corrected the comment so it reads as the reserved-for-later config it actually is, not a live control (exactly the kind of claim this project's own honesty rule exists to prevent).
- 9 new/updated test methods across `tests/Feature/Auth/LoginTest.php`, `tests/Feature/Finance/ExpenseTest.php`, `tests/Feature/Finance/RefundTest.php`, `tests/Feature/Hr/LeaveTest.php`, and `tests/Feature/Hr/AttendanceTest.php` (execution NOT VERIFIED here — see §7). `php -l` clean across the whole tree; `verify_routes.py` re-confirms 132 named routes, zero duplicates, zero unresolved references (the route count is unchanged from Phase 6 — this phase changed authorization logic and middleware, not route structure).

**Phase 6 — Reporting (financial, operational, inventory, HR reports + KPI dashboard)**
- `App\Services\Reporting\FinancialReportService`: `revenueByChargeType()` (accrual revenue from `folio_transactions`, excluding voided charges), `cashFlow()` (real cash in/out from the central `payments` ledger, split by `source_module`), `expenseSummary()` (requested vs. actually-paid by category), `receivablesAging()` (open AR bucketed 0–30/31–60/61–90/90+ days by age).
- `App\Services\Reporting\OperationsReportService`: `occupancy()` — a day-by-day loop over the date range computing total rooms, room-nights available/occupied, occupancy rate, ADR, and RevPAR from real `stays` and `folio_transactions` (a stay occupies a date only while it is genuinely active that day: `check_in_at` on or before that date and no `actual_check_out_at`, or one falling after it) — and `arrivalsAndDepartures()`.
- `App\Services\Reporting\InventoryReportService`: `stockValuation()` (real per-batch FIFO value, not a recomputed average cost), `wastageCost()` (reads `stock_movements` of type `wastage`, so only wastage that was actually **approved** counts — recording alone never creates a movement, unchanged from Phase 3), `lowStock()` (products below their configured reorder level).
- `App\Services\Reporting\HrReportService`: `payrollCost()` (only `Payslip::where('status', 'paid')` rows count as a real cost — draft and approved-but-unpaid payslips are a plan, not an expense yet), `attendanceSummary()` (present/absent/late/on-leave counts per employee), `leaveBalances()`.
- `App\Services\Reporting\KpiDashboardService`: `forRange()` delegates to the financial and operations services above rather than recomputing anything independently, so the dashboard can never disagree with its own detail reports.
- Deliberate accrual-vs-cash separation: accrual revenue (folio charges) and cash flow (the payment ledger) are kept as two distinct, clearly-labeled figures rather than blended into one "revenue" number — documented in the service docblock and footnoted on the KPI dashboard view.
- `App\Http\Controllers\Reporting\ReportController`: one controller for all seven report/dashboard actions, with a shared `range()` helper defaulting to month-to-date and accepting `?start=&end=` query params (swapped automatically if given in reverse order).
- 7 new Blade views: a permission-gated report hub (`reports/index`), a KPI dashboard, and one screen each for financial, occupancy, arrivals/departures, inventory, and HR reports, plus a shared date-range filter partial.
- 4 new **view-only** permissions (`view-financial-reports`, `view-operational-reports`, `view-inventory-reports`, `view-hr-reports`) granted per the updated matrix in §9 — the simplest permission tier in the system, since reporting is read-only and needs no approve/request pair, unlike every transactional module before it.
- A "Reports" section added to the main navigation, shown only to a user holding at least one of the four new permissions.
- 4 new test files, 22 test methods, covering every report's arithmetic against real ledger data (FIFO batches, folio charges, the payment ledger, payslips, attendance records) plus permission enforcement for all four report domains (execution NOT VERIFIED here — see §7). The route-verification script (unchanged since its Phase 4 fix) confirms **132 total named routes, zero duplicates, zero unresolved references** across the whole Phase 1–6 codebase.

**Phase 5 — HR & Payroll (employees, attendance, leave, payroll runs, configurable statutory deductions)**
- 10 new migrations: `employee_profiles`, `attendance_records`, `leave_types`, `leave_balances`, `leave_requests`, `payroll_deduction_types`, `payroll_deduction_brackets`, `payroll_runs`, `payslips`, `payslip_lines`.
- `App\Services\Hr\EmployeeService`: onboarding is an upsert (create-or-update the same profile for a user rather than ever duplicating one); `terminate()` deactivates the employee's login in the same DB transaction as the termination.
- `App\Services\Hr\AttendanceService`: the single writer of `attendance_records` — self clock-in/out, a manager's manual correction, and an approved leave request's automatic `on_leave` marking all funnel through here, enforcing one row per user per day.
- `App\Services\Hr\LeaveService`: `pending → approved` (deducts the balance and marks attendance atomically) or `rejected`/`cancelled` (touches neither) — the balance itself is only read, never written, until the moment of approval, so a request that gets rejected never leaves an HR record of its own behind.
- `App\Services\Hr\PayrollDeductionService`: the configurable statutory-deduction engine — `flat_percent` (a straight rate on the whole amount) or `progressive_brackets` (a real progressive scale taxing only the slice of pay inside each band), entirely data-driven from `payroll_deduction_types`/`payroll_deduction_brackets`, never a hardcoded formula.
- `App\Services\Hr\PayrollService`: the payroll run lifecycle, `draft → approved → paid`. Generation computes a real itemized payslip (basic, absence deduction from actual attendance, every active statutory deduction, net pay) per payroll-eligible employee; approval locks every payslip to `finalized`; payment posts each payslip's net pay through the same central `PaymentLedgerService` as every other module, making payroll payout subject to the accounting-day lock exactly like a folio or expense payment.
- 8 new permissions (`manage-employees`, `clock-own-attendance`, `record-attendance`, `request-leave`, `approve-leave`, `manage-payroll`, `approve-payroll-run`, `pay-payroll-run`), granted per the updated matrix in §9 — `clock-own-attendance`/`request-leave` to all 8 roles as self-service actions, and a three-way separation of duties (generate vs approve vs pay) for payroll runs stricter than Finance's two-way expense-approval split.
- Role-aware Blade UI: Employees, Attendance (self clock-in/out + manager manual entry), Leave (request/approve/reject/cancel + leave-type configuration), Deductions (types + brackets), Payroll Runs (list/detail with generate/approve/pay actions), and a permission-free self-service "My Payslips" screen scoped to the viewer's own records.
- `HrSeeder`: an employee profile for every Phase-1 demo user, three demo leave types, and two demo statutory deduction types (one flat, one progressive) so a payroll run can be generated against real demo data immediately.
- 5 new test files, 28 test methods, covering every workflow above including the bracket-engine math directly and the accounting-day-lock interaction with payroll payout (execution NOT VERIFIED here — see §7). The route-verification script (unchanged since its Phase 4 fix) confirms **125 total named routes, zero duplicates, zero unresolved references** across the whole Phase 1–5 codebase.

**Phase 4 — Finance completion (expense approval, refund approval, reconciliation, accounting-day lock)**
- `App\Services\Finance\AccountingDayService`: the accounting-day open/lock model. `today()` opens the current business date implicitly on first use; `assertOpen()` is the single guard, wired into `PaymentLedgerService::record()` (Phase 2) so it automatically covers every folio payment/refund and every POS payment without touching any of those call sites individually. Locking is one-way (no `reopen()`) and requires today's cash reconciliation to already exist.
- `App\Services\Finance\CashReconciliationService`: `expected_amount` is always computed live from the central `payments` ledger (cash in minus cash out for the business date), never hand-entered, so a reconciliation is a real check rather than a self-agreeing form.
- 4 new migrations: `accounting_days`, `cash_reconciliations`, `expenses`, `refund_requests`.
- `App\Services\Finance\ExpenseService`: `pending → approved → paid` (or `rejected`/`cancelled`). The `approve-large-expense` tier is required above the configurable `expense_approval_threshold` setting, read fresh at approval time rather than frozen at request time. `markPaid()` posts through the same central `PaymentLedgerService` as every other module.
- `App\Services\Finance\RefundService`: recording a refund request never moves money; only `approve()` calls the Phase-1 `FolioService::postRefund()` method (whose docblock had said "requires approval upstream" since it was written), posting both the folio adjustment and the central ledger entry in one DB transaction.
- `App\Services\PaymentLedgerService::record()` modified to call `AccountingDayService::assertOpen()` as its first line — the single enforcement point for the lock, covering every module retroactively.
- 8 new permissions (`manage-expenses`, `approve-expense`, `approve-large-expense`, `pay-expense`, `request-refund`, `approve-refund`, `record-cash-reconciliation`, `lock-accounting-day`), granted per the updated matrix in §9, including the threshold-tier separation for expense approval and keeping day-lock/refund-approval out of the hands of whoever requests the money or counts the drawer.
- Role-aware Blade UI: Expenses screen (with a threshold banner and above-threshold rows highlighted), Refunds screen, Day Close screen (reconciliation → lock), and a "Request refund" form added to the existing stay/folio screen.
- 3 new test files, 18 test methods, covering every workflow above including the threshold boundary, the ledger-posting side effects, and the accounting-day lock actually blocking a new folio payment (execution NOT VERIFIED here — see §7, which also describes a route-verification-script bug this phase's own routes exposed and how it was fixed, plus a real duplicate-route-name authoring mistake the fixed script caught before this delivery).

**Phase 3 — Inventory, FIFO costing, POS↔Inventory link**
- `App\Services\Inventory\StockService`: the single FIFO engine (`receiveStock()` / `issueStock()`) every inventory workflow in this phase is built on. `issueStock()` always consumes the oldest open batch first, computes real costs from real batches, and throws `HmsException` (never lets stock go negative) when there isn't enough on hand.
- 16 new migrations: `units`, `suppliers`, `products`, `purchase_orders`, `purchase_order_items`, `goods_receipts`, `goods_receipt_items`, `stock_batches`, `stock_movements`, `stock_requisitions`, `stock_requisition_items`, `stock_counts`, `stock_count_items`, `stock_wastages`, `recipe_items`.
- `App\Services\Inventory\PurchaseOrderService` + `GoodsReceiptService`: draft → submitted → approved → received lifecycle, PO-linked receiving with partial-delivery tracking (`purchase_order_items.quantity_received`), and fully ad-hoc receiving with no PO at all.
- `App\Services\Inventory\RequisitionService`: department stock requests, approved all-or-nothing across every line in one DB transaction.
- `App\Services\Inventory\StockCountService`: physical counts that snapshot system quantity on start and post real FIFO adjustments (in or out) for every line with a variance on completion.
- `App\Services\Inventory\WastageService`: approval-gated wastage (recording alone never touches stock, same shape as Phase 1's `MaintenanceTicket`).
- `App\Services\Pos\OrderService::close()` extended to deduct real FIFO stock for recipe-linked menu items, inside the same DB transaction as the payment/folio posting — a stock shortfall blocks the close cleanly rather than charging for an order that then can't be fulfilled. Menu items without a recipe are untouched, so Phase 2 stays fully backward compatible.
- 10 new permissions (`manage-products`, `manage-suppliers`, `manage-purchase-orders`, `approve-purchase-order`, `receive-goods`, `request-stock`, `approve-requisition`, `manage-stock-count`, `record-wastage`, `approve-wastage`), granted per the updated matrix in §9 — including the deliberate ordering-vs-approval separation of duties for purchase orders, and the requesting-vs-approving separation for requisitions/wastage.
- Role-aware Blade UI for products/units, suppliers, purchase orders (with a receiving screen for partial deliveries), ad-hoc goods receipts, requisitions, stock counts, wastage, and a per-menu-item recipe editor under POS → Menu.
- `InventorySeeder`: units, two suppliers, six demo products each given a real opening batch through `StockService` (never a raw quantity write), and two Phase 2 menu items linked to recipes.
- 6 new test files, 19 test methods, covering the FIFO engine directly plus every workflow above (execution NOT VERIFIED here — see §7, which also describes the automated route-name cross-check script used for this phase's static verification).

**Phase 2 — POS (Bar & Restaurant), central payment ledger**
- New `payments` table and `App\Services\PaymentLedgerService`: the single writer every module's payments post to (spec section 16). Introduced in this phase, ahead of the full Finance phase, specifically so POS could not create orphaned financial records outside a real ledger.
- `App\Services\FrontOffice\FolioService::postPayment()`/`postRefund()` retrofitted to also write to the new central ledger, so folio payments and POS payments are never in two different places.
- 5 new migrations: `payments`, `menu_categories`, `menu_items`, `pos_tables`, `orders`, `order_items`.
- `App\Services\Pos\OrderService`: full order lifecycle (`open`/`addItem`/`removeItem`/`confirm`/`cancel`/`close`), the add-only lock after confirmation, tax recalculation via the existing `SettingsService`, and folio deferral restricted to guests with an active `Stay`.
- 2 new permissions (`operate-bar-pos`, `operate-restaurant-pos`) plus `manage-menu`, granted per the updated matrix in §9; outlet-specific authorization checked dynamically per request (`OrderController::authorizeOutlet()`), since the permission name depends on the `{outlet}` route parameter.
- Role-aware Blade UI: order board, order detail/settle screen, menu management screen.
- `PosSeeder`: demo bar/restaurant categories, items, and tables.
- 1 new test file, 7 test methods, covering the full lifecycle, the add-only lock, folio deferral (including the "not currently checked in" rejection), outlet permission isolation, and audit log writes (execution NOT VERIFIED here — see §7).
- Deleted the unused Vite/npm scaffolding's remaining references in `composer.json` scripts (`npm install`/`npm run build`/`concurrently` dev script) and the framework's own community-management files (`CHANGELOG.md`, issue-triage/PR-label workflows) that shipped with the Laravel skeleton but don't belong in an application repo; rewrote `.github/workflows/tests.yml` into a real project CI pipeline (checkout → PHP → `composer validate` → install → `composer audit` → MySQL service container → migrate → seed → test → package artifact) matching spec section 35 — this pipeline has not been run yet (no GitHub Actions run has been triggered from this repo), so treat it the same as everything else in §7: written and reviewed, not executed.

**Phase 1 — Foundation, Authentication, Front Office**
- New Laravel 12 project scaffolded from the official skeleton (not hand-typed, to guarantee a correct baseline), with `composer.json` pinned to Laravel 12, PHP ^8.2, `spatie/laravel-permission` ^6.19 (the last major version supporting PHP 8.0+/Laravel 12 — v7+ requires PHP 8.3), `pragmarx/google2fa` ^9.0, and `bacon/bacon-qr-code` ^3.0.
- 18 migrations: users (extended for 3-mode auth), departments, settings, audit_logs, spatie's permission tables, guests, room_categories, rooms, reservations, stays, folios, folio_transactions, accounts_receivable, housekeeping_tasks, maintenance_tickets.
- 8 roles seeded exactly as specified, with a Phase-1 permission matrix (§9).
- 3-mode authentication implemented from scratch against `pragmarx/google2fa` directly (not Fortify — see §2 for why).
- Full Front Office service layer (`App\Services\FrontOffice\*`) with DB-transaction-safe reservation approval, check-in (walk-in + from-reservation), checkout with the Accounts Receivable rule, an append-only folio ledger with void-by-reversal, housekeeping, and maintenance-with-approval.
- Role-aware Blade UI for every workflow above.
- 7 test files, ~30 test methods, covering the above (execution NOT VERIFIED here — see §7).
- Removed the unused Vite/npm scaffolding that shipped with the Laravel skeleton, since the UI currently has nothing to compile (Tailwind via CDN) — avoids a false "you need Node to build this" impression.
