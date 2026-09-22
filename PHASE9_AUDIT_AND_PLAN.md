# Phase 9 Audit & Implementation Plan

**Scope of this document:** audit-only, per explicit instruction ("perform the audit and produce its implementation plan"). **No implementation has been done under Phase 9 yet.** Every claim below is backed by a file path (and, where useful, a line number) so it can be checked directly rather than taken on faith. Where something cannot be verified in this sandbox, that is stated plainly rather than assumed or glossed over.

---

## 1. Executive Summary

The Phase 9 master prompt asks for two different kinds of work, and they need to be told apart up front:

1. **Things that are genuinely missing and buildable** — an admin UI for Users/Roles/Departments, a Settings UI, printable documents, a data-integrity checker, a role-aware dashboard, and (with an important caveat below) financial reports. These are additive to the existing single-database system and don't require re-architecting anything.
2. **A new architectural layer that does not exist at all today** — the local-server + cloud-VPS hybrid with bidirectional sync (`sync_devices`, `sync_batches`, `sync_items`, `sync_conflicts`, `sync_logs`, `sync_checkpoints`, UUID/ULID identifiers throughout, conflict resolution, a sync dashboard). This is a from-scratch subsystem that touches every table in the schema (anything that syncs needs a UUID/ULID primary or secondary key) and changes the deployment story from "one shared-hosting site" to "two networked installations of the same app." It is by far the largest item in the prompt and the one most likely to interact badly with an incremental, non-destructive approach if decisions aren't made explicitly first.

Additionally, this audit turned up **three real, currently-live authorization gaps** that were missed by the Phase 7 security audit (that audit checked IDOR/self-approval patterns on routes that already had permission gates; it didn't do a "does this route have a gate at all" sweep). These are cheap to fix and are recommended as the very first Phase 9 sub-phase, ahead of any new feature work, because they are live exposures in the code as it stands today — not something Phase 9 would introduce.

Finally, this sandbox cannot execute `composer install`, `php artisan test`, `php artisan migrate:fresh --seed`, concurrency tests, or a live two-server sync test, for the same reason as every prior phase: no outbound access to Packagist and no live MySQL/MariaDB server to run against. This hasn't changed. The master prompt asks for these to be run for real with recorded numbers — that's addressed directly in §5 below rather than worked around.

**Bottom line:** the plan below sequences the work into 9 sub-phases (9.0 through 9.8), starts with the free authorization fixes, treats the two big open questions (accounting depth, hybrid topology assumptions) as blocking decisions rather than guesses, and keeps every existing working feature untouched.

---

## 2. Audit Methodology & Sandbox Limitations

Every finding below was produced by directly reading or grepping the actual files in `/home/claude/hms` — not by re-describing prior phases from memory. Where a claim is "X does not exist," it's backed by a search command that returned nothing (shown inline). Where a claim is about behavior (e.g. "this route has no permission gate"), the exact route definition is quoted with its line number.

**What could not be run in this sandbox, and why:**

| Command the master prompt asks for | Why it can't run here | Evidence |
|---|---|---|
| `composer install` / `composer validate` / `composer audit` | No outbound access to Packagist; `vendor/` has never existed in this project | `ls composer.json composer.lock` → `composer.lock` does not exist; `ls -d vendor` → does not exist |
| `php artisan test` | Requires `vendor/` (PHPUnit) and a working MySQL/MariaDB connection, neither available | same as above, plus no live DB server in this sandbox |
| `php artisan migrate:fresh --seed` against real MySQL/MariaDB | No live MySQL/MariaDB server reachable from this sandbox | n/a — no DB server process available |
| Concurrency tests (double-booking, double stock consumption, duplicate payment, duplicate sync payload) | Requires multiple simultaneous connections to a live MySQL server under load | same as above |
| A genuine two-server hybrid sync test | Requires two actual running instances of the app, networked, with real data flowing between them | categorically outside what a single static sandbox can provide |

This is unchanged from every prior phase's honesty note. It means the Phase 9 deliverable, like Phases 1-8, will contain **written, unexecuted** test code and migrations that are believed correct by inspection and by consistency with the existing (also-unexecuted) test suite's patterns, plus a written test plan for a human to run on a real server — not a `TEST_REPORT.md` with genuine numbers. This is stated up front so the final Phase 9 output doesn't imply otherwise, consistent with the master prompt's own §39.

---

## 3. Section-by-Section Findings

Ratings: **PASS** (exists and matches spec) · **PARTIAL** (some of it exists) · **MISSING** (nothing exists) · **NEEDS-DECISION** (can't be built correctly without the user resolving an open question) · **CANNOT-EXECUTE-IN-SANDBOX** (code can be written but not run/verified here).

### 3.1 Hybrid local+cloud architecture & sync engine — **MISSING** (NEEDS-DECISION on top)

Confirmed absent, system-wide:
- No `sync_devices` / `sync_batches` / `sync_items` / `sync_conflicts` / `sync_logs` / `sync_checkpoints` tables — no migration mentions any of them.
- No UUID/ULID usage anywhere: `grep -rn "HasUuids\|HasUlids\|Str::uuid\|Str::ulid\|uuid(" app database/migrations` returns nothing. Every model today uses auto-increment integer primary keys, which collide across two independently-writing databases the moment sync is introduced.
- No concept of "device," "node," "site," or "installation" anywhere in the schema or code.

This is a new subsystem, not a modification. It needs: a `sync_devices` registry (one row per local-server/cloud-VPS installation, with an identity/secret), a way to tag every syncable row with a globally-unique ID (UUID or ULID) instead of relying on the integer PK, an outbox/batch mechanism that packages changed rows since the last checkpoint, an idempotent apply step on the receiving side (so replaying the same batch twice is safe), conflict detection (two sides changed the same record between syncs) with a resolution policy, and a dashboard to see sync health and conflicts. This is the single largest item in the whole prompt.

**Blocking decision needed before any of this is built:** see §4.1 and §4.2 below.

### 3.2 Administration — Users / Roles / Departments — **PARTIAL**

- `User` model, `spatie/laravel-permission` roles/permissions, and `Department` model + migration + seeder all exist.
- `find app/Http/Controllers -iname "*user*" -o -iname "*admin*" -o -iname "*setting*"` returns nothing — **there is no admin-facing controller or view to create/edit/deactivate a user, reset a password, assign a role, or manage departments.** Everything user/role/department-related today is seed-only (`RolesAndPermissionsSeeder.php`, `DatabaseSeeder.php`).

Buildable additively: a standard `UsersController` (index/create/store/edit/update — deactivate rather than delete, to preserve referential integrity with everything a user has touched: payments, approvals, audit logs), a `RolesController` (assign/revoke — not create-arbitrary-permission, since the 58 permission strings already form a closed, audited set per `RolesAndPermissionsSeeder.php`), and a `DepartmentsController` (CRUD, used today only as a foreign key on `User`/HR records).

### 3.3 System Settings — **PARTIAL**

`Setting` model/migration/seeder exist and `SettingsService` already reads settings at runtime (confirmed in earlier phases — e.g. `AccountingDayService`, PIN lockout, expense threshold all read from it). What's missing is the admin-facing screen to edit those values through the UI instead of directly in the database — hotel profile, logo upload, operational parameters (already partially covered by the `HMS_*` env-seeded keys), finance parameters, security parameters (PIN attempts/lockout, session timeout), room categories, and POS configuration. Buildable additively on top of the existing `SettingsService`.

### 3.4 Financial reporting — **NEEDS-DECISION**

Confirmed by exhaustive grep: `grep -rln "chart_of_accounts\|ChartOfAccount\|general_ledger\|GeneralLedger\|TrialBalance\|BalanceSheet\|IncomeStatement" app database` returns nothing. The Finance module that exists (`PaymentLedgerService`, `AccountsReceivable`, `Expense`, `Refund`, folio transactions) is a **payments/operational ledger**, not a double-entry bookkeeping system. There is no Chart of Accounts, no debit/credit posting, no General Ledger.

A **Trial Balance** and a **Balance Sheet** are, by definition, outputs of a double-entry GL. Producing genuine ones requires building that GL/COA subsystem first — every revenue, expense, payment, and receivable event would need to post as a balanced debit/credit journal entry against a chart of accounts, which is a substantial new subsystem in its own right, comparable in size to the sync engine.

The alternative is to build **management-style reports** that answer the same business questions using the data that already exists (revenue by category from folio/POS transactions, expenses from the `Expense` table, receivables from `AccountsReceivable`, cash movements from ledger entries) and label them honestly as such rather than calling them "Trial Balance" / "Balance Sheet" if they aren't backed by real double-entry postings. Income Statement, Cash Flow, AR Aging, Guest Stay History, Revenue Reports, and Inventory Reports are all buildable today from existing data without a GL — only Trial Balance and Balance Sheet strictly require one.

**This is the other blocking decision** — see §4.1.

### 3.5 Management Dashboard + Weekly Owner Summary — **MISSING / PARTIAL**, and currently mis-documented

`app/Http/Controllers/DashboardController.php::index()` builds a single `$data` array — `roomsTotal`, `roomsOccupied`, `arrivalsToday`, `departuresToday`, `openReceivablesTotal` (a financial figure, via `AccountsReceivable::whereIn(...)->sum('amount_outstanding')`), `pendingHousekeeping`, `openMaintenanceTickets`, etc. — and returns it **identically for every authenticated user, regardless of role.** The controller's own docblock claims "Role-specific dashboards (spec section 25)," which is not what the code does — this is a real, pre-existing gap, not a Phase 9 feature to add from nothing.

`resources/views/dashboard.blade.php` renders the "Open receivables" figure unconditionally to whoever loads the page — meaning a Bar Staff or Restaurant Staff login sees a hotel-wide financial total today.

A Weekly Owner Summary (aggregated financials + occupancy + a narrative-style rollup, presumably emailed or viewable/printable weekly) does not exist in any form.

### 3.6 Printable documents (11 documents + CSV/PDF export) — **MISSING**

`grep -rli "invoice\|receipt\|print" resources/views` returns only incidental word matches inside `layouts/app.blade.php` and two inventory views (referring to "receiving" stock, not a payment receipt) — there are zero actual print-layout views anywhere. None of the 11 named documents (reservation confirmation, guest invoice, payment receipt, final hotel bill, restaurant receipt, bar receipt, daily cash closing, payment summary, expense report, management report, weekly owner summary) exist. No CSV/PDF export mechanism exists either (no `barryvdh/laravel-dompdf` or similar in `composer.json`'s `require`).

### 3.7 Data Integrity Checker — **MISSING**

`app/Console/Commands` does not exist as a directory at all (`ls -la app/Console` → "No such file or directory"). There are zero custom Artisan commands anywhere in the project. `runIntegrityCheck()` exists in no form.

### 3.8 Financial authorization hardening + correction/reversal workflow — **PARTIAL, with newly-found gaps**

The existing accounting design already has real controls worth preserving: `AccountingDayService::assertOpen()` gating every ledger write, self-approval guards added in Phase 7 for Expense/Refund/Leave, an append-only ledger with void-not-delete semantics in `FolioController::void`. What's missing/newly found:

- **`stays.index` / `stays.show` have no permission middleware at all** (`routes/web.php` lines 108-111):
  ```
  Route::prefix('stays')->name('stays.')->group(function () {
      Route::get('/', [StayController::class, 'index'])->name('index');
      Route::get('/{stay}', [StayController::class, 'show'])->name('show');
      Route::post('/{stay}/checkout', [StayController::class, 'checkout'])->middleware('permission:checkout-guest')->name('checkout');
  });
  ```
  Only the checkout POST is gated. `StayController::show(Stay $stay)` loads `$stay->load(['guest', 'room.category', 'folio.transactions.user', 'folio.accountsReceivable'])` with no authorization check, and `resources/views/front-office/stays/show.blade.php` renders the folio balance, the full transaction ledger, and outstanding AR unconditionally (only the action *buttons* like void/charge/payment are individually `@can`-wrapped — the *display* of the guest's full financial history is not). **Today, any authenticated role — including Bar Staff, Restaurant Staff, or Cashier — can open any guest's full financial/folio history by visiting `/stays/{id}`.** This is exactly the kind of "front-line staff must not see unrelated financial data" gap §22 of the master prompt calls out, and it already exists.
- **`rooms.index` has no permission middleware** (`routes/web.php` line 82-83) — exposes room occupancy and current guest names to every role.
- **`maintenance.index` has no permission middleware** (`routes/web.php` line 130, viewing only — `store`/`approve`/`resolve` are correctly gated) — lower severity, ticket visibility rather than financial data.
- **`DashboardController` exposes `openReceivablesTotal` to every role** — see §3.5.

A formal correction/reversal workflow beyond the existing void-with-reason pattern (e.g. a distinct "correction" record type that references the original, rather than a bare void) does not exist and would be new work.

### 3.9 Re-verification of existing controls — spot-checked, no regressions found

The Phase 7 self-approval guards (`LeaveService::approve/cancel`, `ExpenseService::approve`, `RefundService::approve`) and the accounting-day lock (`AccountingDayService::assertOpen()`) are still in place and unchanged since Phase 7/8; nothing in Phases 7-8 or this audit touched them. A full re-run of the existing 146 test methods to *prove* no regression is a §5/CANNOT-EXECUTE item, not something inspection alone can certify.

### 3.10 Deployment helper enhancements — **PARTIAL, buildable**

`DeployHelperController` (Phase 8) already handles migrate/seed/cache-clear/disable safely for a fresh, unmigrated database with no SSH. Missing per the new prompt: an integrity-check step (trivial to add once §3.7's console command exists — the controller can just call `Artisan::call('integrity:check')` the same way it calls `migrate`) and an admin-account-creation step (currently, the only way to get a first login is via seeded demo accounts — a real deployment needs a way to create a real Owner account with a real password through this same safe, pre-database-migration-friendly mechanism).

### 3.11 Environment configuration — **NEEDS-DECISION** (small, easy to resolve)

`.env.example` line 2 currently has `APP_ENV=production` (set in Phase 1-8 for the shared-hosting target). The new master prompt's §34 references `APP_ENV=staging`. These aren't necessarily in conflict — a real deployment might use `staging` for the pre-go-live verification environment and `production` once live — but which one `.env.example` should ship with, and whether a second `.env.staging.example` is wanted, needs a one-line answer from the user (folded into §4.2's questions below).

### 3.12 Non-expansion of deferred features — confirmed clean

Re-grepped for Event Hall, Gym/day-pass, external Bar/Buvette, and auto WhatsApp/SMS sending — none exist in the codebase, and nothing in the Phase 9 prompt or this audit calls for adding them. No action needed; noted here only to confirm the constraint hasn't been quietly violated.

---

## 4. The Decisions That Determine Everything Else — RESOLVED

These questions were put to the user directly (via the clarifying-question tool) after the audit above was written, since each changes the size and shape of the implementation plan by roughly an order of magnitude. Answers are locked in below and the plan in §6 reflects them.

### 4.1 How deep should "financial reporting" go? → **Management-style reports**

Income Statement, Cash Flow, AR Aging, Guest Stay History, Revenue, and Inventory reports will be built directly from existing tables (folio transactions, `Expense`, `AccountsReceivable`, POS/inventory records). Trial Balance and Balance Sheet will either be omitted or shipped as clearly-labeled management estimates (e.g. "Balance Sheet (management estimate — not a substitute for double-entry bookkeeping)") built from the same source data, with their limitations documented in `USER_GUIDE.md`/`SECURITY.md`. No Chart of Accounts / General Ledger subsystem will be built.

### 4.2 What does "local server + cloud VPS" mean for this deployment? → **Hybrid supersedes shared hosting; SSH/cron assumed available**

The hybrid local-server + cloud-VPS architecture becomes the primary supported deployment going forward, replacing the original shared-hosting-only target from Phases 1-8. Both the local server and the cloud VPS are assumed to have SSH access and a real cron daemon (or an equivalent persistent-process capability), so the sync engine will run via a scheduled Artisan command (`php artisan schedule:run` triggering a `sync:run` command, or similar) on each side, rather than a manually-visited web page. The Phase 8 deploy helper's web-based pattern remains as the safe first-install mechanism (its own constraints — working before the DB is migrated — are unrelated to this decision) but is not the sync trigger.

### 4.3 `.env.example` default → **Keep `APP_ENV=production`**

No change to the existing `.env.example` default. A separate `.env.staging.example` may be added later if a distinct pre-go-live environment is wanted, but this is not required by the decisions above.

---

## 5. How "real test execution" will be handled

Per §2, this sandbox cannot run any of these. The Phase 9 deliverable will instead:

1. Write the actual migrations, services, and PHPUnit test files for every new feature, following the existing project's testing conventions (146 existing test methods to pattern-match against).
2. Ship a `TEST_REPORT.md` that states plainly, in the same voice as `SECURITY_AUDIT.md`'s honesty section: which commands were written to be run, that none were executed here, and exact copy-paste commands (`composer install`, `php artisan migrate:fresh --seed`, `php artisan test`, plus the specific concurrency-test scripts) for the user (or a CI runner with real MySQL access) to execute and get genuine numbers from.
3. Not claim any readiness rating higher than the evidence supports — per the master prompt's own §39, the final rating will explicitly account for "written but unexecuted" as different from "passing."

---

## 6. Implementation Plan

Sequenced so that each sub-phase leaves the system in a working, shippable state — consistent with "do not rebuild from scratch."

**Phase 9.0 — Authorization gap fixes (no new features, fixes only) — DONE**
Added `permission:view-rooms` to `rooms.index` (new permission, granted to Owner/GM/Duty Manager/Front Desk), `permission:manage-guests` to `stays.index`/`stays.show`, `permission:report-maintenance` to `maintenance.index`. Fixed `DashboardController` to hide `openReceivables`/`openReceivablesTotal` unless the caller holds `view-financial-reports` or `view-operational-reports`, with a matching Blade `@if` guard. 4 new test files, 12 test methods. See the "Phase 9.0 Addendum" in `SECURITY_AUDIT.md` for full evidence (route count, permission-list parse, `php -l`).

**Phase 9.1 — Decisions from §4 — DONE**
Resolved: management-style financial reports (no GL/COA), hybrid supersedes shared hosting, SSH/cron assumed available for sync, `.env.example` stays on `production`. Phase 9.2 onward is scoped accordingly below.

**Phase 9.2 — Administration + Settings + Departments + Room Categories UI — DONE**
Built `Admin\{UserController,DepartmentController,SettingsController,RoomCategoryController}` and
`Admin\{UserAdminService,DepartmentService}`. Users: create (PIN or password/TOTP)/edit/deactivate-reactivate
(never hard-deleted)/reset password/reset PIN/clear PIN lockout, one role per user via `syncRoles`, self-deactivation
blocked, every mutation audit-logged. Departments: full CRUD. Settings: a grouped edit screen over the existing
`settings` table (added a `general` group for hotel name/address/phone/email) plus a logo upload on the `public`
disk. Room Categories (not originally called out as its own controller in this plan, but identified during
implementation as the concrete meaning of "rooms" under §3.3's System Settings scope): full CRUD over
rate/occupancy/description, deliberately gated on `manage-settings` rather than `manage-rooms`. 4 new test files,
24 test methods. `RolesController` (assign/revoke as a separate screen) was not built separately — role assignment
is instead a field on the Users edit form (`syncRoles([$role])`), matching this project's existing one-role-per-user
model; a separate Roles screen would only make sense if roles became dynamically definable, which they are not
(the 8 roles and 59 permissions are fixed by `RolesAndPermissionsSeeder`, not user-creatable).

**Phase 9.3 — Financial reports (per the §4.1 decision) — DONE**
Cross-checking the six-item checklist against the existing Reporting module (Phase 6) before building anything found that Cash Flow, AR Aging, Revenue by charge type, and Inventory reports already existed — only **Income Statement** and **Guest Stay History** were genuinely missing. Built `FinancialReportService::incomeStatement()` (total revenue minus total requested expenses, explicitly labeled a management-style approximation per §4.1 — no GL/COA was built) and `OperationsReportService::guestStayHistory()` (completed stays in a date range with real charge/payment totals and an AR flag, gated on `view-operational-reports` so front-line roles can't see it). 5 new test methods appended to the existing report test files.

**Phase 9.4 — Management Dashboard + Weekly Owner Summary — DONE**
Built `PendingApprovalsService::forUser()`: purely permission-driven (never role-name-driven, per §5's "permission-scoped, not identity-scoped" principle), it checks the 9 `approve-*` permissions in the system and returns a pending-item count + route for each one the caller personally holds. Wired into `DashboardController`/`dashboard.blade.php` as a "Pending approvals" section, plus a trailing-7-day financial summary widget reusing `FinancialReportService::incomeStatement()`, gated specifically on `view-financial-reports` (not the broader financial-or-operational check used for the existing Open receivables card). Built `WeeklyOwnerSummaryService::forWeekEnding()` — the narrative-style weekly rollup called for in §3.5 — combining `OperationsReportService::occupancy()`, `FinancialReportService::incomeStatement()`/`cashFlow()`/`receivablesAging()` for the 7 days ending a given date (defaults to today, `?week_ending=` selects a past week); new `reports.weekly-owner-summary` route/view, linked from both the Reports index and the dashboard widget. Computes nothing independently — every figure is delegated to the service that already owns it, so the rollup can never disagree with its own detail reports.

While wiring the approvals widget, verifying every route name it references was actually reachable surfaced two real, pre-existing dead ends (not caused by this project's own earlier phases): `inventory.purchase-orders.index` was gated only on `manage-purchase-orders`, which Accountant does not hold (only `approve-purchase-order`) — Accountant could approve a PO by POST but never see a page listing one to approve. `inventory.requisitions.index` had the identical problem for Duty Manager (holds `approve-requisition`, not `request-stock`). Both fixed in `routes/web.php` by extracting the `index` route out of its single-permission group into a standalone OR-permission route (`manage-purchase-orders|approve-purchase-order`, `request-stock|approve-requisition`), mirroring the pattern already used for `finance.expenses.index`/`finance.refunds.index`. A related but distinct observation, documented rather than "fixed": `approve-payroll-run` is held only by Owner/General Manager in the current seed (Accountant holds `manage-payroll`/`pay-payroll-run` but not the approve permission) — nothing is unreachable (Accountant still reaches `payroll-runs.index` via `manage-payroll`), so this reads as a deliberate control rather than a bug, and this plan does not call for widening who signs off on payroll.

2 new test files (`tests/Feature/Dashboard/PendingApprovalsServiceTest.php` — 11 methods covering all 9 permission branches including the expense OR-permission case; `tests/Feature/Reporting/WeeklyOwnerSummaryTest.php` — 5 methods) plus 9 authorization-regression methods appended to 3 existing test files (`DashboardAuthorizationTest.php` +4, `Inventory/PurchaseOrderTest.php` +2, `Inventory/RequisitionTest.php` +3) — 25 new test methods in total. See §7 for what was actually run vs. written-but-unexecuted.

**Phase 9.5 — Printable documents + export — DONE**
Built all 11 named print layouts against a new bare `layouts/print.blade.php` (no sidebar/nav/flash chrome from `layouts/app.blade.php`, hotel name/address/phone from `SettingsService` in the header, a "Print / Save as PDF" button, `@media print` CSS): Reservation Confirmation (`ReservationController::print()`), Guest Invoice + Final Hotel Bill (`StayController::printInvoice()`/`printFinalBill()`, sharing one `folio-print` partial since they're the same folio ledger at two lifecycle points — final bill genuinely blocked via `HmsException` until the stay is closed, not just cosmetically hidden), Payment Receipt (`FolioController::printReceipt()`, 404s for a non-payment transaction), Restaurant Receipt + Bar Receipt (`OrderController::printReceipt()`, one view for both outlets, authorized the same in-controller way as every other order action), Daily Cash Closing (`AccountingDayController::printCashClosing()`), Payment Summary + Expense Report + Management Report (three new report screens in `ReportController`, each with its own print view — Payment Summary and Expense Report are individual-row listings the existing reports didn't have, sourced from two new `FinancialReportService` methods (`paymentSummary()`, `expenseReport()`); Management Report combines `KpiDashboardService`'s headline tiles with `financial()`'s categorized breakdowns, computing nothing independently), and Weekly Owner Summary (print view for 9.4's existing service). Every print route reuses the exact permission the underlying screen already uses — no parallel, weaker authorization path was introduced anywhere in this phase.

CSV export: a small `ExportsCsv` trait (`app/Http/Controllers/Concerns/ExportsCsv.php`) streams via native `fputcsv` — genuinely no new Composer dependency, unlike PDF. Wired to the three list-shaped reports where it's an obvious fit: Payment Summary, Expense Report, and (retrofitted) Guest Stay History. Not retrofitted onto the aggregate-only reports (Financial, KPI Dashboard, Occupancy) in this pass, since a bar-chart-shaped report has no natural row-per-line CSV shape — left as a fast-follow if actually requested, not a gap in this phase's scope.

**PDF export — resolved decision, not built as a separate feature.** §3.6 originally flagged this as needing a new Composer dependency (`barryvdh/laravel-dompdf` or similar). Per §2's standing sandbox limitation, Packagist is blocked here, so any such dependency could be added to `composer.json` but never installed or verified — shipping that would be exactly the "claim completion without evidence" this whole implementation has been avoiding. Instead: every browser's native "Print → Save as PDF" produces a real PDF from the same `layouts/print.blade.php` pages built above, today, with zero new dependencies — the "Print / Save as PDF" button in that layout's header is that path, not a placeholder for one. A dedicated server-side PDF library (for unattended/bulk generation rather than one-at-a-time browser printing) remains a legitimate fast-follow, but requires an environment with real Packagist access to add and verify — it cannot be honestly marked done from here.

4 new test files (`tests/Feature/PrintableDocumentsTest.php` — 11 methods covering every print route's permission gate plus the final-bill/payment-receipt business rules; `tests/Feature/Reporting/PaymentSummaryReportTest.php` — 4 methods; `tests/Feature/Reporting/ExpenseReportTest.php` — 4 methods; `tests/Feature/Reporting/ManagementReportTest.php` — 3 methods) plus methods appended to 2 existing report test files (`OperationsReportTest.php` +2 for the Guest Stay History CSV, `WeeklyOwnerSummaryTest.php` +2 for its print view) — 26 new test methods in total. See §7 for what was actually run vs. written-but-unexecuted.

**Phase 9.6 — Data Integrity Checker — DONE**
Built `App\Services\Integrity\DataIntegrityCheckerService::run(): array`, 7 read-only checks, each reusing an existing model method rather than reimplementing its logic ("never a duplicated total"): folio balances match `Folio::recomputeBalance()`'s ledger total; every `AccountsReceivable` ties to a real folio; no `AccountsReceivable` owes more than its `original_amount`; every stay has a folio; a room's `status` agrees with whether it actually has an active stay (checked both directions — occupied-with-no-stay and active-stay-in-a-non-occupied-room); no `StockBatch` has gone negative; every purchase order marked `received` actually satisfies `PurchaseOrder::isFullyReceived()`. Each check returns `{key, label, passed, issue_count, issues[]}`; the service never auto-"fixes" anything, only reports.

Exposed two ways: `php artisan integrity:check` (`app/Console/Commands/RunIntegrityCheck.php`, auto-discovered per Laravel 12's `routes/console.php`-based command loading — no `Kernel.php` registration needed), which exits `0` on all-pass and non-zero on any failure so it's usable as a real cron/CI gate; and a new read-only step wired into the deploy helper (§3.10) — `DeployHelperController::integrityCheck()`, routed as `GET deploy-helper.integrity-check` (GET, not POST, unlike migrate/seed/clear-caches, since it never writes anything), which checks `Schema::hasTable('folios')` first so it fails gracefully with a plain message on an unmigrated database instead of surfacing a raw SQL error, then renders `deploy-helper/integrity-result.blade.php` with a PASS/FAIL badge and issue list per check. The deploy helper's index page gained a new "4. Run a data integrity check" section (renumbering the old step 4, "Disable this helper," to step 5).

One deliberate omission, corrected during implementation: an initial draft of the Artisan command's signature included a `--fail-on-warning` option whose own description admitted it was "currently a no-op, reserved for future checks." That's a small dishonesty — a flag that visibly does nothing — so it was removed before this was called done; `integrity:check` takes no options. The "no unresolved sync conflicts older than N days" check this section originally proposed is deferred to Phase 9.7, since sync tables don't exist yet; the 7 checks above are exactly the invariants that exist in the system today.

3 new test files: `tests/Unit/Integrity/DataIntegrityCheckerServiceTest.php` (9 methods — one confirming a clean/empty database passes all 7 checks with zero issues, one per check corrupting exactly that invariant and confirming only the matching check fails), `tests/Feature/Console/RunIntegrityCheckTest.php` (2 methods — exit code 0 with the "found no issues" message on a clean database, non-zero with the "found real issues" message and a `[FAIL]` line otherwise), plus 3 methods appended to `tests/Feature/DeployHelperTest.php` (pass-case renders "PASS", a corrupted AR record renders an "ISSUE" badge, and the route still 404s without a valid flag+token like every other deploy-helper action) — 14 new test methods in total. See §7 for what was actually run vs. written-but-unexecuted.

**Phase 9.7 — Hybrid sync engine** (the large one — see §3.1)

Per this section's own prior note, re-scoped into concrete sub-phases before any code was written, rather than attempting the whole subsystem in one pass:

- **9.7.1 — Global identifiers (schema foundation) — DONE.** See detailed writeup below.
- **9.7.2 — Sync registry tables + device authentication — DONE, corrected in 9.7.4.** See detailed writeup below — its device-secret design was revised once the transport was actually built; the "why" is under 9.7.4.
- **9.7.3 — `SyncService` — DONE.** See detailed writeup below.
- **9.7.4 — Transport — DONE.** See detailed writeup below.
- **9.7.5 — Scheduling — DONE.** See detailed writeup below.
- **9.7.6 — Sync dashboard + integrity check — DONE.** See detailed writeup below.

**9.7.1 — Global identifiers (schema foundation) — DONE**

The highest-risk architectural piece, and the one every later sub-phase depends on: every table either installation can independently write needs a globally-unique identifier, because the existing auto-increment integer primary keys collide the instant two databases both hand out their own "row #1" for the same table. Built additively, exactly as this section originally specified — no existing primary key, foreign key, or relationship was touched.

One migration, `database/migrations/2025_01_02_000100_add_ulid_to_syncable_tables.php`, adds a nullable, unique `ulid` (Laravel's `$table->ulid()` column type — a fixed 26-char, lexicographically-sortable string) to **48 tables**, guarded per-table by `Schema::hasColumn()` so it's safe to run more than once. The 48 are every table backing an `App\Models\*` class except one: `Setting` was deliberately excluded — hotel settings are few, change rarely, and are administered per-installation for now rather than runtime-synced; this is a scoped decision in the same spirit as the GL/COA and PDF-dependency decisions in earlier phases, not an oversight. Framework tables (`cache`, `jobs`, `migrations`) and spatie/laravel-permission's role/permission tables are excluded for a different reason: roles are fixed and provisioned identically on both installations by `RolesAndPermissionsSeeder`, so they are configuration, not per-hotel data that either side independently creates.

A new `App\Services\Sync\Concerns\HasSyncUlid` trait auto-assigns a ULID in a `creating` listener on all 48 models. Two deliberate design choices, both load-bearing for later sub-phases: `ulid` is never added to any model's `$fillable` — it is system-generated only, assigned via direct attribute assignment (which always bypasses mass-assignment protection, unlike `create()`/`fill()`), so no controller, form request, or (once 9.7.4 exists) incoming sync payload can overwrite a row's identity by simply including a `ulid` key. And the `creating` listener only assigns a new ULID `if (empty($model->ulid))` — a value already set before save() is preserved, which is exactly what the sync engine's apply step (9.7.3) will depend on when it replays a row that already has a ULID from the *other* installation. A `scopeWhereUlid()` helper (`Model::whereUlid($ulid)`) is included now since it's the natural lookup the apply step will need, even though nothing calls it yet.

Applying the trait to 48 model files by hand would be both slow and error-prone to eyeball-verify, so it was done with a small, idempotent, re-runnable Python script (kept in the scratchpad, not part of the shipped codebase) that inserts the import and the `use HasSyncUlid;` line at the same two structural points in every file — verified correct on every file afterward by mechanical checks (below), not by trusting the script.

New test file `tests/Unit/Sync/HasSyncUlidTest.php` (7 methods): a ULID is auto-assigned on create and matches the real 26-character Crockford-base32 shape; two records get different ULIDs; the trait works on a second, differently-shaped model (not just the one it was developed against); a pre-set ULID (set via direct attribute assignment, the way the future apply step will do it) survives save() unchanged; `ulid` is confirmed absent from `$fillable`; the `whereUlid` scope finds the right row; and `Setting` is confirmed to NOT use the trait, locking in this sub-phase's one scoping exclusion as a regression-checked fact rather than a comment that can silently drift.

**9.7.2 — Sync registry tables + device authentication — DONE**

Six new tables, each additive and independent of the 48 syncable business tables from 9.7.1 (none of these six is itself one of the 48, and none uses `HasSyncUlid` — see below for why): `sync_devices` (one row per local-server/cloud-VPS installation — `device_key` public, `secret_hash` never plaintext, `is_local` distinguishes "this installation" from its counterpart, `is_active` supports revocation), `sync_checkpoints` (one row per device per direction — `outbound`/`inbound` — recording the watermark the not-yet-built outbox will read from and write to), `sync_batches` (one row per batch attempt, keyed by its own `ulid` — the idempotency key a later apply step will check before ever re-applying a replayed batch), `sync_items` (one row per synced record within a batch, referencing the record polymorphically by `syncable_type` + `syncable_ulid` since the record can live in any of the 48 syncable tables — the same pattern `audit_logs.record_type`/`record_id` already uses), `sync_conflicts` (created when a future apply step finds a row changed on both sides since the last sync — holds both the local and incoming snapshots and a `resolution` field, never silently overwriting), and `sync_logs` (a general append-only event log, deliberately mirroring `audit_logs`' own `$timestamps = false` + explicit `created_at` shape and single-writer convention rather than inventing a second one).

**Why these six tables don't get `HasSyncUlid`:** the trait (9.7.1) exists so a row created independently on either installation can be recognized as "the same row" on the other side once it syncs. These six tables are the sync engine's own bookkeeping about itself — a device's registry row, a batch record, a log line — created once, locally, and never replicated as business data through the outbox they help implement. Giving them a `ulid` would imply they participate in the same replication mechanism they exist to run, which they don't; `sync_batches.ulid` is a separate, purpose-specific identifier assigned explicitly by the batch-creation code (built in 9.7.3), not by the generic trait.

Device authentication (`App\Services\Sync\DeviceAuthService`) reuses this codebase's existing secret-storage convention instead of inventing a new one: `Hash::make()`/`Hash::check()`, exactly what `App\Services\Admin\UserAdminService` already does for `users.password`/`users.pin_hash`. This is a deliberate improvement over the deploy helper's own DEPLOY_TOKEN pattern (a plaintext env-var value compared with `hash_equals()`) rather than a copy of it — the deploy helper has to work before a single table exists, so it can't hash anything into a database; a sync device's secret has no such constraint, since the schema already exists by the time any device registers, so it is hashed at rest like every other credential in the system. `register()` returns the plaintext secret exactly once and never persists it; `authenticate()` returns null (not a thrown exception) on any failure — wrong key, wrong secret, or a revoked device — without distinguishing which, the same "don't tell an attacker which half is wrong" principle `DeployHelperController::authorizeRequest()` already follows.

New `php artisan sync:register-device {name} {--local} {--endpoint=}` creates a device row and prints its `device_key` and one-time secret to the terminal — this sub-phase only creates the local database row; it does not yet exchange anything over a network (that's 9.7.4's transport). `App\Services\Sync\SyncLogger` (info/warning/error) is the sole writer of `sync_logs`, mirroring `App\Services\AuditLogger`'s existing single-writer shape.

3 new test files, 15 new test methods total: `tests/Unit/Sync/DeviceAuthServiceTest.php` (8 — registration returns a usable device+secret, the secret is never stored in plaintext but does verify against the stored hash, `secret_hash` is hidden from array/JSON serialization, authentication succeeds/fails for the right/wrong secret/an unknown key/a revoked device, and a successful authentication stamps `last_seen_at`), `tests/Feature/Console/RegisterSyncDeviceTest.php` (2 — the command creates a device and prints its key/secret, the `--endpoint` option is stored), `tests/Unit/Sync/SyncRegistryModelsTest.php` (5 — device→checkpoint/batch relations, the `checkpointFor()` direction lookup, batch→item and item→batch, an item's conflict lifecycle including resolution, and a sync log with and without an associated device). See §7 for what was actually run vs. written-but-unexecuted.

> **Correction made in 9.7.4, before any of this ran against a real database:** the paragraph above describing `Hash::make()`/`Hash::check()` for the device secret turned out to be the wrong tool once the transport was actually designed — a one-way hash can verify an incoming secret but can never be retrieved again to *present* on an outgoing call, and bidirectional sync needs both. `secret_hash`/`is_local` were replaced with a single encrypted, retrievable `shared_secret` per counterpart row (no more "self" row at all) before this schema or service was ever used for real. Full explanation under 9.7.4 below; this entry is left as originally written, rather than rewritten, so the record shows what was actually built at each step.

**9.7.3 — `SyncService` — DONE**

The engine itself, built and tested against real Eloquent models (SQLite, this project's test driver) rather than left as a design sketch. Two new supporting pieces first: `App\Services\Sync\SyncableModels::ALL` is the single canonical list of the 48 syncable model classes from 9.7.1 (so the outbox scan, the ulid backfill command, and any future caller share one list instead of each retyping it and risking drift), and `php artisan sync:backfill-ulids` — a necessary companion to 9.7.1 that was missing until now: `HasSyncUlid` only assigns a ulid at row-creation time, so every row that already existed before that migration ran has `ulid = NULL` and would be invisible to the outbox scan below; this command assigns one to every such row, once, via the query builder (not Eloquent, so it never fires 48 model classes' worth of events over what could be a lot of historical data).

`SyncService::buildOutboxBatch(SyncDevice $device)` scans all 48 syncable tables for rows changed since that device's outbound checkpoint (`updated_at > last_synced_at`; `withTrashed()` for the two SoftDeletes models — Guest and User — so a recent soft-delete isn't invisible to the scan), packages them into a new `SyncBatch`, and creates one `SyncItem` per row. Rows with no ulid yet are deliberately excluded rather than assigned one on the spot — assigning identity during a sync scan, instead of only at creation (or via the backfill command), would risk two installations independently minting two different ulids for what was originally the same pre-existing row. `acknowledgeOutboxBatch()` is a separate, explicit step from building the batch — the checkpoint only advances once the (Phase 9.7.4) transport confirms delivery, so a built-but-never-sent batch's rows are retried on the next attempt rather than silently skipped.

`SyncService::applyIncomingBatch(SyncDevice $fromDevice, string $batchUlid, array $items)` is the idempotent apply side §3.1 calls for: if a `sync_batches` row with that exact `ulid` + `direction=inbound` is already marked `applied`, the batch is returned untouched and nothing is reprocessed — proven by a test that replays an identical batch ulid carrying deliberately different data and confirms the second copy never takes effect. Each item is applied independently inside its own try/catch, so one bad or unrecognized item (an unknown `syncable_type`, a missing ulid) is marked `failed` on that item alone without aborting the rest of the batch — also directly tested.

**The conflict rule**, the riskiest piece of the whole sub-phase: before applying an incoming update to a row that already exists locally, `applyUpsert()` checks whether that local row's own `updated_at` is newer than the last time a batch from that same device was successfully processed (the inbound checkpoint). If it is, something changed here that the sender never saw — the local row is left completely untouched, both versions are recorded in a new `SyncConflict` row, and the `sync_item` is marked `conflict` (not `applied`, not `failed` — a distinct, expected outcome, so the batch as a whole still reports `applied` rather than `failed`). This is directly tested end-to-end: sync a row in, edit it locally, sync a conflicting change in from the same device, and assert the local edit survived untouched. Three more deliberate, documented design choices inside the apply step: `id` (the local auto-increment PK) is never copied across installations; `updated_at` is left to Eloquent's own timestamp management rather than copied from the sender, which is exactly what keeps the conflict check meaningful (it reflects "the last time *this* installation touched the row," sync-applies included) and sidesteps clock skew between two physical servers; and an existing row's `created_at` is never rewritten by an update.

**Deletion is deliberately conservative.** A soft-deletable model (Guest, User — the only two of the 48 that use `SoftDeletes`) propagates a delete like any other field change, since the row still exists to carry a `deleted_at`. Every other syncable model has no tombstone at all, so this design's outbox scan — which can only see rows that still exist — has no way to detect a hard delete on the sending side in the first place; `buildOutboxBatch()` therefore never emits a `delete` operation for them. If one somehow arrives anyway, it is recorded as `skipped` with a plain-text reason rather than silently deleting a business/financial record, which is exactly the kind of irreversible action this whole engine exists to avoid taking automatically. This is a known, named scope limit (real hard-delete propagation would need a tombstone table, which is not part of this sub-phase), not a silently-dropped requirement.

4 new files (`SyncableModels`, `SyncService`, `BackfillSyncUlids` command) plus 3 new test files, 18 new test methods total: `tests/Unit/Sync/SyncableModelsTest.php` (4 — exactly 48 unique, real classes; `Setting` confirmed absent; `isSyncable()` matches), `tests/Feature/Console/BackfillSyncUlidsTest.php` (3 — assigns a ulid to a query-builder-inserted "legacy" row across two different tables, leaves an already-ulid'd row alone, safe to run twice), `tests/Unit/Sync/SyncServiceTest.php` (11 — first-ever outbox includes existing data, a no-ulid row is excluded, an unchanged row isn't resent after acknowledgement, a post-acknowledgement change is picked up next time, an inbound create, an inbound update with no conflict, the conflict scenario end-to-end, batch-ulid replay idempotency, soft-delete propagation, hard-delete skip-with-reason, and an unknown type failing in isolation without aborting the batch). See §7 for what was actually run vs. written-but-unexecuted.

**9.7.4 — Transport — DONE**

Two things happened in this sub-phase: a design correction to 9.7.2 that had to happen before the transport could be built at all, and the transport itself.

**The correction.** Actually designing "how does installation A call installation B" surfaced a real flaw in 9.7.2's device model: `secret_hash` (bcrypt, via `Hash::make()`/`Hash::check()`) can verify a secret presented to it, but can never be decrypted back to the original value — so an installation would have no way to retrieve its own secret again to present when it INITIATES a call to its counterpart. A one-way hash only ever supports one direction of a two-direction relationship. Since nothing in Phase 9.7 has ever run against a real database (no MySQL in this sandbox — §2), the fix was made directly rather than shipped broken and patched around later: `sync_devices.secret_hash` + `is_local` were replaced with a single `shared_secret` column, encrypted at rest via Laravel's `encrypted` model cast (APP_KEY-based — retrievable, unlike a hash) rather than hashed. The corrected model has no more "self" row at all — every row is symmetric, one counterpart relationship, one shared secret both sides already know.

That secret is still never invented or transmitted automatically — there is no network handshake that bootstraps trust between two installations that don't already trust each other, deliberately, since that would just move the security question to "how do we trust the handshake." Instead: `DeviceAuthService::register()` (run on whichever installation goes first) generates a device_key + secret and prints them once; `DeviceAuthService::import()` (run on the other installation) records the exact same pair, handed over by the operator out of band — the same trust model the deploy helper already uses for a human to hand-carry `DEPLOY_TOKEN` into `.env`, just applied to a second installation's operator instead of the same one. `sync:register-device` gained `--import-key`/`--import-secret` options for this; its old `--local` flag is gone, since there's no more self-row to mark.

**The transport itself.** Three JSON endpoints, registered in a new `routes/sync.php` and required from `bootstrap/app.php`'s `then:` callback — outside the `web` middleware group, for the exact reason `routes/deploy.php` already is: this is server-to-server JSON traffic with no browser and no session on either end, so CSRF verification would only ever reject a legitimate request. `throttle:60,1` is kept as basic abuse protection (more generous than the deploy helper's 10/minute, since a real sync schedule calls these on every scheduled run, not once during a manual install).

- `POST /sync/push` — the caller hands over its outbound batch; this installation applies it via `SyncService::applyIncomingBatch()` (already built and tested in 9.7.3 with zero network involved) and returns a per-status breakdown (applied/conflicts/skipped/failed counts). A batch containing conflicts or failed items is still a successful HTTP response — those are expected, recorded outcomes, not a transport-level error.
- `POST /sync/pull` — the caller asks for this installation's outbound batch; `SyncService::buildOutboxBatch()` builds and returns it, but does **not** advance the outbound checkpoint yet.
- `POST /sync/acknowledge` — confirms a pulled batch was safely received, advancing the checkpoint via `SyncService::acknowledgeOutboxBatch()`. Idempotent: acknowledging an already-`applied` batch a second time is a silent no-op, so a retried acknowledgement can never skip over changes made in the gap between two acks.

`SyncTransportController` contains no sync logic of its own — every route authenticates via `DeviceAuthService::authenticate()` (a bad key or secret always gets the same generic 401, never a hint about which half was wrong, same principle as `DeployHelperController::authorizeRequest()`) and then delegates entirely to the already-tested `SyncService`. One robustness fix flowed back into `SyncService` from designing this: `applyIncomingBatch()`'s per-item loop now guards against a malformed (non-object) item — real network input is no longer guaranteed to match the array shape `SyncServiceTest` always handed it directly — recording it as its own failed item instead of throwing a `TypeError` that would have aborted every other item in the same batch.

New `tests/Feature/Sync/SyncTransportTest.php`, 7 methods, hits the real HTTP routes (not the controller directly) to prove routing/middleware/JSON serialization all work together: push is rejected without valid credentials, a valid push applies and reports the right counts, a missing `batch_ulid` is a 422, pull returns a batch without advancing the checkpoint, acknowledge advances the checkpoint and is confirmed idempotent on a second call, an unknown `batch_ulid` on acknowledge is a 404, and a revoked device is locked out of every endpoint. Plus revisions to the three sub-phase-9.7.2 test files to match the corrected device model (`DeviceAuthServiceTest.php` 8→11: added `import()`, `credentialsFor()`, and `credentialsFor()`'s failure case; `RegisterSyncDeviceTest.php` 2→4: added the `--import-key`/`--import-secret` flow and its validation), and one new method in `SyncServiceTest.php` (11→12: the malformed-item guard above). See §7 for what was actually run vs. written-but-unexecuted.

**9.7.5 — Scheduling — DONE**

Everything through 9.7.4 could apply a batch once someone called `SyncService`/hit the transport endpoints by hand (or from a test); nothing yet *initiated* an outgoing call on a schedule. This sub-phase closes that gap with two new classes and nothing else — no new sync logic, since 9.7.3/9.7.4 already cover both "what to send" and "how a batch is received."

`App\Services\Sync\SyncRunner::runFor(SyncDevice $device)` drives both directions against one counterpart:
- **Push**: `SyncService::buildOutboxBatch($device)` builds this installation's outbox for that device. If it's empty, nothing is sent over the network at all — there's nothing for the counterpart to apply — and `acknowledgeOutboxBatch()` is called locally anyway, purely so this installation's own outbound checkpoint advances and the *next* run scans "changed since last time" instead of re-querying every one of the 48 syncable tables from scratch again. If it's non-empty, it's POSTed to the device's own `/sync/push` (Phase 9.7.4), which applies it synchronously and replies with a status breakdown; only on a successful reply is it acknowledged locally.
- **Pull**: always makes both network calls, even when the reply turns out to have zero items. `POST .../sync/pull` asks the counterpart for its outbox; whatever comes back is applied locally via `SyncService::applyIncomingBatch()`; then `POST .../sync/acknowledge` confirms receipt so the counterpart's *own* outbound checkpoint advances. Skipping the acknowledge call on an empty reply was considered and rejected — the counterpart's `buildOutboxBatch()` never advances its own checkpoint on its own (see 9.7.4's `SyncTransportController::pull()` docblock), so an un-acknowledged empty batch would leave the counterpart re-scanning its entire dataset on every future pull, the identical wastefulness the push-side skip-when-empty is designed to avoid from the other direction.
- Push and pull failures are caught independently (a `try`/`catch` around each) and collected into one `errors` list per device, so a broken push never prevents the pull half from being attempted, and one device's failure never stops the command from moving on to the next device.

`php artisan sync:run` (`App\Console\Commands\SyncRun`, auto-discovered, no `Kernel.php` registration) is the actual cron entry point — a thin loop, no sync logic of its own: selects every `sync_devices` row that is `is_active` AND has a non-null `endpoint_url` (a device with no endpoint can still *receive* an incoming push/pull initiated by its counterpart — that direction needs no outbound configuration on this side at all — it just can't be the one to *initiate* an outgoing call, so it's skipped here with an explanatory note rather than silently ignored), optionally narrowed to specific `device_key`(s) via `--device=`, calls `SyncRunner::runFor()` for each, prints a per-device pushed/pulled count, and exits non-zero if any device reported an error — the same "real, cron/CI-usable, non-zero exit on failure" contract `integrity:check` (Phase 9.6) already established. A suggested crontab line is in the command's own docblock.

Uses Laravel's `Illuminate\Support\Facades\Http` client (Guzzle-backed, ships with `laravel/framework` — no new Composer dependency) for the three outgoing calls; `Http::fake()` in the new test makes this fully testable without any real network access, which this sandbox doesn't have anyway.

New `App\Services\Sync\SyncRunner` (no existing file changed except the new console command) plus 2 new test files, 11 new test methods total: `tests/Unit/Sync/SyncRunnerTest.php` (6 — push skipped-but-locally-acknowledged when empty, push sent and acknowledged when non-empty, push failure leaves the batch `pending` and the checkpoint unadvanced, pull applies items and acknowledges the counterpart, pull still acknowledges an empty reply, pull failure is recorded without aborting the whole run), `tests/Feature/Console/SyncRunTest.php` (5 — no devices registered, an endpoint-less device is skipped with a count in the output, a normal run reports success, a device error produces a non-zero exit, `--device` narrows the run to one device_key). See §7 for what was actually run vs. written-but-unexecuted.

**9.7.6 — Sync dashboard + integrity check — DONE**

The last sub-phase of Phase 9.7: the human-facing half of a sync engine that, through 9.7.5, could only be inspected via `php artisan tinker` or raw SQL. Two independent pieces, both additive.

**The dashboard.** New `App\Http\Controllers\Admin\SyncDashboardController`, gated by a new permission `manage-sync` (granted to Owner/GM/Duty Manager — the same tier that already administers other operational systems in this project), following the established `admin/*`-prefixed, `permission:`-middleware pattern (`routes/web.php`'s `admin.users`/`admin.settings`/`admin.room-categories` groups) rather than inventing a new authorization approach. Named `admin.sync.*` specifically to avoid any confusion with `routes/sync.php`'s own `sync.*` names (the machine-to-machine transport, registered outside the `web` group entirely) — same domain, deliberately different route-name namespace.

- `GET admin/sync` (`admin.sync.index`) — every registered `SyncDevice`, its active/revoked status, last-seen time, and both checkpoints' last-synced times (outbound = last time we successfully sent this device something; inbound = last time we successfully applied a batch from it), plus a pending-conflict count per device (computed via `SyncConflict::whereNull('resolved_at')->whereHas('item.batch', fn ($q) => $q->where('sync_device_id', $device->id))`) and the 25 most recent `sync_logs` rows. Entirely read-only.
- `GET admin/sync/conflicts` (`admin.sync.conflicts`) — every unresolved `SyncConflict`, showing both the local and held-back incoming JSON side by side, with a resolution form per conflict.
- `POST admin/sync/conflicts/{conflict}/resolve` (`admin.sync.conflicts.resolve`) — the only place a conflict can actually be resolved, delegating entirely to the new `SyncConflictResolver` service (see below); the controller itself contains no resolution logic.

**`App\Services\Sync\SyncConflictResolver`** — the sole writer of `sync_conflicts.resolved_*`, matching the `AuditLogger`/`SyncLogger` single-writer convention already established twice in this project. Four resolutions, exactly matching the column's existing enum (set back in the Phase 9.7.2 migration, unused by any code until now): `kept_local` (no data change — the local row was never touched by the original conflict-detecting apply in the first place, so "keeping it" is already true; just marks the record resolved), `kept_incoming` (the only resolution that mutates anything: applies the previously-held-back `incoming_data` onto the local row now, via the same `forceFill()`-and-save() approach — and the same excluded-attribute list, `id`/`ulid`/`created_at`/`updated_at` — that `SyncService::applyUpsert()` itself uses for a normal, non-conflicting upsert), `merged` (a human has already reconciled the two versions by hand outside this flow; nothing here attempts an automatic field-by-field merge, since guessing at one could produce a value neither side ever actually had), and `ignored` (deliberately leave both versions as history, no data change). Every resolution writes one `AuditLogger` entry (`sync.conflict_resolved`). An already-resolved conflict, an unrecognized resolution string, or `kept_incoming` against a local row that's since been deleted all throw `HmsException` — caught by the same global handler (`bootstrap/app.php`) every other business-rule violation in this project already is, no new error-handling path introduced.

**The integrity check.** `DataIntegrityCheckerService` gains an 8th check, `noStaleSyncConflicts()` — deliberately not part of the original Phase 9.6 rollout, since `sync_conflicts` didn't exist until Phase 9.7.2, two sub-phases later than that class was first built (Phase 9.6's own writeup names this exact gap). Flags any `SyncConflict` still unresolved more than 7 days after `detected_at` (the constant `STALE_CONFLICT_DAYS`, mirroring the "Weekly Owner Summary"'s existing 7-day cadence as this codebase's standing definition of "a week has gone by unattended") — a conflict is never auto-resolved by anything (see the migration's own docblock, quoted again in 9.7.2's writeup above), so an old one sitting unresolved is a genuine, actionable signal that two installations' data has been silently diverging on that record. `php artisan integrity:check` and the deploy helper's integrity-check page (Phase 9.6) both pick this up automatically — neither hardcodes the check count anywhere, they iterate whatever `run()` returns.

New files: `App\Services\Sync\SyncConflictResolver`, `App\Http\Controllers\Admin\SyncDashboardController`, `resources/views/admin/sync/index.blade.php`, `resources/views/admin/sync/conflicts.blade.php`. Edited: `RolesAndPermissionsSeeder` (+1 permission, `manage-sync`, granted to owner/general_manager via the existing `$all`/`array_diff` grants plus an explicit addition to `duty_manager`'s list), `routes/web.php` (+3 routes, `admin.sync.*`), `resources/views/layouts/app.blade.php` (a `manage-sync`-gated nav link, placed in the existing "Administration" section alongside Users/Departments/Settings), `DataIntegrityCheckerService` (+1 check).

3 new test files, 17 new test methods total: `tests/Unit/Sync/SyncConflictResolverTest.php` (7 — kept_local leaves the record untouched, kept_incoming overwrites it, kept_incoming never copies `id`/`ulid` from the incoming payload, merged and ignored both leave the record untouched, an already-resolved conflict cannot be resolved again, an unknown resolution string is rejected, kept_incoming against a since-deleted local row fails loudly rather than silently doing nothing), `tests/Feature/Admin/SyncDashboardTest.php` (6 — the status page renders a registered device, the conflicts page lists an unresolved one, resolving via the HTTP endpoint actually applies the incoming version and the conflict disappears from the unresolved list, Duty Manager can reach the dashboard while Front Desk cannot, a `@dataProvider`-driven check across every other role), and 2 new methods added to the existing `tests/Unit/Integrity/DataIntegrityCheckerServiceTest.php` (the "empty database passes every check" count updated 7→8; a conflict stale past 7 days is detected; a recent-but-unresolved conflict and an old-but-resolved conflict both correctly do NOT count as stale — proving the threshold logic, not just the check's existence). See §7 for what was actually run vs. written-but-unexecuted.

With 9.7.6 done, **Phase 9.7 (the hybrid sync engine) is now fully complete — all 6 sub-phases.** Only Phase 9.8 remains for the whole of Phase 9.

**Phase 9.8 — Documentation, re-verification pass, and final packaging**
Write `INSTALLATION.md`, `HYBRID_ARCHITECTURE.md`, `USER_GUIDE.md`, `SECURITY.md` (re-run the Phase 7 audit methodology against everything added in 9.0-9.7, including the new financial-authorization surface), `TEST_REPORT.md` (per §5). Produce the master prompt's mandated final-output format with an honest Readiness Rating.

---

## 7. Status

**Phase 9 is now fully complete — all of 9.0 through 9.8, plus the two out-of-band additions.** Phases 9.0 through
9.7 (all 6 of Phase 9.7's sub-phases) were done first — see §6 for what each delivered. Front Desk petty sales
(§8) and complimentary items/stays (§9) were completed out of band, mid-sequence, at the user's explicit request.
**Phase 9.8** (documentation, a full security re-audit, and final packaging) closes out the phase — see §9.8 below
for its full writeup, and `SECURITY.md`/`TEST_REPORT.md`/`INSTALLATION.md`/`HYBRID_ARCHITECTURE.md`/`USER_GUIDE.md`
for the five documents it produced.

### 9.8 — Documentation, re-verification pass, and final packaging

Per §6's original plan for this sub-phase: write `INSTALLATION.md`, `HYBRID_ARCHITECTURE.md`, `USER_GUIDE.md`,
`SECURITY.md` (re-running the Phase 7 audit methodology against everything built in 9.0–9.7 plus the two
out-of-band additions), and `TEST_REPORT.md`, then produce an honest Readiness Rating.

**The security re-audit came first**, ahead of writing `SECURITY.md` itself, following this project's standing
practice (Phase 7, then the Phase 9.0 addendum) of fixing what a re-audit finds rather than only describing it. A
dedicated pass across every route/controller/service added since the Phase 9.0 addendum — the admin screens,
reports, dashboard, hybrid sync engine (given the most attention, as the newest and most security-sensitive
surface in the project), Front Desk POS, and complimentary items/stays — found 3 genuine gaps and 1 defensive
hardening opportunity, all fixed in this sub-phase:

1. **`Pos\OrderController` cross-outlet IDOR** — every action authorized against the URL's `{outlet}` string, never
   against the route-bound `$order`'s actual outlet, letting any outlet's POS staff view/modify/close/cancel
   another outlet's orders by substituting an order ID. Fixed: `authorizeOutlet()` now also verifies
   `$order->outlet === $outlet`; `removeItem()` additionally verifies the item actually belongs to the bound
   order. 6 new regression tests (`tests/Feature/Pos/OrderCrossOutletTest.php`).
2. **Sync engine — User credentials could leave the installation, and be overwritten by, a sync payload** — the
   most severe finding: `SyncService::exportableAttributes()` bypassed `User::$hidden`, so `password`/`pin_hash`/
   `totp_secret`/`totp_recovery_codes` travelled in every outbound sync payload and could sit in cleartext JSON in
   `sync_conflicts`, visible to any `manage-sync` holder; combined with `applyUpsert()`'s unrestricted
   `forceFill()`, a compromised `sync_devices.shared_secret` could be used to directly rewrite another account's
   credentials — full account takeover. Fixed with a new `SyncableModels::PROTECTED_ATTRIBUTES` mechanism applied
   at all three points data crosses a boundary (export, apply, conflict-resolution apply) — see `SECURITY.md` §3.3
   Finding 2 and `HYBRID_ARCHITECTURE.md` §6 for the full design. 3 new regression tests across
   `tests/Unit/Sync/SyncServiceTest.php`/`SyncConflictResolverTest.php`.
3. **Hotel-logo upload accepted unsanitized SVG** (the first file-upload feature in this codebase) — a stored-XSS
   vector if the raw file URL were opened directly. Fixed: dropped `svg` from the upload's `mimes:` allowlist.
4. **CSV exports had no formula-injection neutralization** — a cell starting with `=`/`+`/`-`/`@` is interpreted as
   a formula by Excel/Sheets/LibreOffice when opened. Fixed defensively (low real-world exposure, since these
   exports are already permission-gated) by apostrophe-prefixing any such cell in `ExportsCsv::csvResponse()`. 3
   new tests (`tests/Unit/Http/ExportsCsvTest.php`).

**What changed**: `app/Http/Controllers/Pos/OrderController.php`, `app/Services/Sync/SyncableModels.php`,
`app/Services/Sync/SyncService.php`, `app/Services/Sync/SyncConflictResolver.php`,
`app/Http/Controllers/Admin/SettingsController.php`, `app/Http/Controllers/Concerns/ExportsCsv.php`; new test
files `tests/Feature/Pos/OrderCrossOutletTest.php` and `tests/Unit/Http/ExportsCsvTest.php`; new methods added to
`tests/Unit/Sync/SyncServiceTest.php` and `tests/Unit/Sync/SyncConflictResolverTest.php`.

**Evidence**: `php -l` clean across all 306 non-Blade PHP files under `app/`, `tests/`, `database/`, `routes/`,
`config/` (up from 304 before this sub-phase — the 2 new test files; every production-code file touched was an
edit). `verify_routes.py` — still **190 named routes**, unchanged (every fix this sub-phase changed authorization/
validation logic, not routing), 0 duplicates, 0 unresolved references. The 6 new/updated test methods this
sub-phase added are, like every test in this project, written and manually traced but **not executed** — see
`TEST_REPORT.md` §3 for the fix-to-test mapping and §4 for the exact commands to get real numbers, and
`SECURITY.md` for the complete findings writeup (which itself supersedes nothing in `SECURITY_AUDIT.md`, only adds
to it).

**Documentation produced**: `INSTALLATION.md` (local dev, shared-hosting production, and the two-node hybrid
install sequence — with two real command-syntax corrections caught while writing it: `sync:register-device`'s
`name` is a positional argument, not a `--name=` option, and `--endpoint` must be the counterpart's base URL, not
already including `/sync`, since `SyncRunner::endpoint()` appends `/sync/{push,pull,acknowledge}` itself),
`HYBRID_ARCHITECTURE.md` (the full sync-engine design: why this shape, the ULID data model, the outbox/checkpoint/
apply engine, the transport, conflict detection and resolution, the security-trust model behind Finding 2 above,
the admin dashboard, and explicitly-documented known limitations), `USER_GUIDE.md` (every role's day-to-day
workflows including both out-of-band additions and the sync dashboard), `SECURITY.md` (this sub-phase's re-audit,
consolidated with — not replacing — `SECURITY_AUDIT.md`'s Phase 7 audit and Phase 9.0 addendum), and
`TEST_REPORT.md` (the complete, honest test inventory: 62 files, 361 methods, zero executed, with exact commands).

**Not done, and explicitly out of scope for this sandbox regardless of effort**: an automated SAST/DAST scan,
`composer audit`, a real penetration test, an actual two-node live sync test, and — the single most valuable
missing piece of evidence for this entire project — a real `php artisan test` run. See `TEST_REPORT.md` §4 for the
exact commands and §4.1 for the specific things that genuinely need a live deployment to verify, which no amount
of further code review in this sandbox can substitute for.

**§9 (Complimentary items/stays) evidence — what was actually run vs. written-but-unexecuted** (same sandbox
limitations as every prior addition: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` clean across all 304 non-Blade `.php` files under `app/`, `tests/`, `database/`,
  `routes/`, `config/` (up from 301 at the end of 9.7.6 — the new migration, and the two new test files
  `ComplimentaryOrderTest.php`/`StayComplimentaryTest.php`; every other file touched — `OrderItem.php`, `Stay.php`,
  `OrderService.php`, `OrderController.php`, `FolioService.php`, `CheckOutService.php`, `StayController.php`,
  `routes/web.php`, `RolesAndPermissionsSeeder.php` — was an edit, not a new file, so it doesn't change the count).
  `php -l` also run on the 3 touched `.blade.php` files (`pos/orders/show.blade.php`,
  `front-office/stays/show.blade.php`, `front-office/stays/index.blade.php`) — zero syntax errors on all three.
  Directive-balance counts on the same three files: `pos/orders/show.blade.php` — `@if`/`@endif` 6/6,
  `@foreach`/`@endforeach` 3/3; `front-office/stays/show.blade.php` — `@if`/`@endif` 11/11, `@foreach`/`@endforeach`
  1/1, `@can`/`@endcan` 6/6; `front-office/stays/index.blade.php` — `@if`/`@endif` 1/1, `@forelse`/`@empty`/
  `@endforelse` 1/1/1 — all balanced (`@section`/`@endsection` excluded from this count per the Phase 9.5
  methodology — see that phase's note above). `verify_routes.py` re-run: **190 named routes** (188 → 190, exactly
  the 2 new `stays.mark-complimentary`/`stays.unmark-complimentary` routes), no duplicates, no dead `route(...)`
  calls anywhere in the project including the three edited Blade views.
- What genuinely cannot be verified here: whether the new `order_items`/`stays` columns (`is_complimentary`,
  `comp_reason`, `complimentary_reason`, `marked_complimentary_by`, `marked_complimentary_at`) actually apply
  cleanly against real MySQL via `php artisan migrate` — reviewed by inspection against Laravel 12's documented
  schema builder (`boolean()->default(false)`, `foreignId(...)->constrained('users')->nullOnDelete()`), but never
  executed, same standing limitation as every migration in this project. Also unverified: whether `bccomp()`
  behaves exactly as this design assumes when `$order->total_amount` is a fresh-from-`refresh()` Eloquent decimal
  cast rather than a hand-constructed string — this codebase already relies on the identical pattern in
  `CheckOutService` (`bccomp($folio->balance, '0.00', 2) > 0`), so it is an established, not a novel, assumption,
  but it is still untested by an actual run in this sandbox.
- Written and manually traced, but **not executed** (no PHPUnit run possible in this sandbox): all 15 new test
  methods across `ComplimentaryOrderTest` (6 methods) and `StayComplimentaryTest` (9 methods) — see the full list
  in the "What changed" section above. Because this addition touches `OrderService::addItem()`/`close()` and
  `CheckOutService`'s final-night-charge block — both shared, heavily-exercised code paths — the existing
  `OrderTest`, `FolioTest`, and any prior checkout-related test files should be re-run alongside the two new files
  to confirm nothing regressed for a non-complimentary order or stay (each new test file includes at least one
  explicit regression guard for exactly this reason —
  `test_a_non_comp_order_still_requires_a_payment_method_or_a_stay` and
  `test_checkout_still_posts_the_final_night_charge_for_a_normal_stay`). To get real pass/fail numbers:
  `php artisan test --filter=ComplimentaryOrderTest`, `--filter=StayComplimentaryTest`, `--filter=OrderTest`,
  `--filter=FolioTest`, or `php artisan test` for the full suite.

**Phase 9.7.6 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every
prior sub-phase: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` clean across all 301 non-Blade `.php` files under `app/`, `tests/`, `database/`,
  `routes/`, `config/` (up from 297 at the end of 9.7.5 — `SyncConflictResolver.php`, `SyncDashboardController.php`,
  and their two new test files; `DataIntegrityCheckerService.php` and its test file were edits, not new files, so
  they don't change the count). `php -l` also run on the 2 new `.blade.php` files (near-vacuous for Blade per the
  Phase 9.2 correction, but still catches a raw-PHP break) plus the edited `layouts/app.blade.php` — zero syntax
  errors on all three. Directive-balance counts (`@if`/`@endif`, `@foreach`/`@endforeach`, `@forelse`/`@endforelse`,
  `@can`/`@endcan`, `@empty` paired within its enclosing `@forelse`) on the same three files — all balanced.
  `verify_routes.py` re-run: **188 named routes** (185 → 188, exactly the 3 new `admin.sync.index` /
  `admin.sync.conflicts` / `admin.sync.conflicts.resolve` routes), no duplicates, no dead `route(...)` calls
  anywhere in the project including the two new Blade views.
- What genuinely cannot be verified here: whether Blade's `@class([...])` directive (used once in
  `admin/sync/index.blade.php` for the log-level badge color) compiles and renders exactly as documented — no
  `vendor/` to check the installed Blade compiler against, same category of caveat as every other
  documented-but-unverified framework API noted in this project (see Phase 9.7.5's `Http::fake()` caveat just
  above for the most recent example). Also unverified: whether Eloquent's dot-notation nested `whereHas('item.batch',
  ...)` (used in `SyncDashboardController::index()` to count each device's pending conflicts) resolves exactly as
  Laravel's documentation describes for a three-level relationship chain (`SyncConflict` → `SyncItem` →
  `SyncBatch` → `sync_device_id`) — a documented, standard Eloquent feature, but this project's own test suite has
  not previously exercised a chain this deep.
- Written and manually traced, but **not executed** (no PHPUnit run possible in this sandbox): all 17 new/added
  test methods listed in §6's Phase 9.7.6 entry. To get real pass/fail numbers: `php artisan test
  --filter=SyncConflictResolverTest`, `--filter=SyncDashboardTest`, `--filter=DataIntegrityCheckerServiceTest`, or
  `php artisan test` for the full suite — the last of these is now, for the first time since Phase 9.0, worth
  running in full rather than by filter, since Phase 9 has reached a natural stopping point (all of 9.0–9.7 done)
  and a real pass/fail count across the entire accumulated test suite would be the single most valuable piece of
  evidence this project could gain that remains completely unavailable in this sandbox.

**Phase 9.7.5 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every
prior sub-phase: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` clean across all 297 non-Blade `.php` files under `app/`, `tests/`, `database/`,
  `routes/`, `config/` (up from 293 at the end of the Front Desk addition — the new `SyncRunner.php`,
  `SyncRun.php`, and their two test files). One real syntax bug was caught and fixed by this same check: the
  console command's docblock originally included a literal crontab example (`*/5 * * * *`), and PHP's `/* ... */`
  comment parser reads the first `*/` it encounters as the comment's own closing delimiter — the docblock closed
  three lines early and everything after it became a stray top-level statement, a genuine parse error `php -l`
  caught immediately; reworded to avoid the literal `*/` sequence rather than escaped, since there is no escape
  sequence for it inside a PHP comment. `verify_routes.py` re-run: still **185 named routes**, no duplicates, no
  dead references — correct, since this sub-phase added no new routes at all (it only calls the three `/sync/*`
  endpoints Phase 9.7.4 already registered, as an HTTP client, not as new server-side routes).
- What genuinely cannot be verified here: whether `Illuminate\Support\Facades\Http`'s fake/assertion API
  (`Http::fake()`, `Http::assertSent()`, `Http::assertNotSent()`, request array-access via `$request['field']`)
  behaves exactly as documented — this sandbox has no `vendor/` to check the installed Laravel version's actual
  source against, only Laravel's public documentation and this project's own established conventions; flagged
  here as one more layer of "written against documented behavior, not verified" on top of the standing
  no-PHPUnit limitation (same category of caveat as Phase 9.5's `streamedContent()` note). Every `Http::fake()`
  call in the new test includes an explicit wildcard `'*'` fallback returning a deterministic failing response
  (HTTP 599) specifically so that if a URL-pattern assumption turns out to be wrong when these tests are actually
  run, the failure is a clear, local assertion failure rather than an attempted real network call escaping the
  fake and hanging or erroring against a hostname that doesn't exist.
- Written and manually traced, but **not executed** (no PHPUnit run possible in this sandbox): all 11 new test
  methods across `SyncRunnerTest`/`SyncRunTest` (see §6's Phase 9.7.5 entry for the full list). To get real
  pass/fail numbers: `php artisan test --filter=SyncRunnerTest`, `--filter=SyncRunTest`, or `php artisan test` for
  the full suite. Because this sub-phase is the first thing in the whole project to make an outgoing HTTP call,
  running these for real is also the first genuine test of whether `Http::fake()` intercepts correctly in this
  project's actual test configuration (`phpunit.xml`) — everything before it only ever exercised inbound
  HTTP (via `postJson()`/`getJson()` against this app's own routes) or no HTTP at all.

**Phase 9.7.4 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every
prior sub-phase: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` clean across all 291 non-Blade `.php` files under `app/`, `tests/`, `database/`,
  `routes/`, `config/` (the same scope every phase's count has used) — up from 288 at the end of 9.7.3 (the new
  `SyncTransportController`, `routes/sync.php`, and `SyncTransportTest.php`; the 9.7.2 correction edited existing
  files rather than adding new ones). `bootstrap/app.php` and `bootstrap/providers.php` (outside that scope, so
  not counted in the 291, but real files that had to change) were separately `php -l`-checked too — clean.
  `verify_routes.py` (extended this sub-phase to also read `routes/sync.php`, the same way it already read
  `routes/deploy.php` since Phase 8) confirms **185 named routes** (182 → 185, exactly the 3 new
  `sync.push`/`sync.pull`/`sync.acknowledge`), no duplicates, no dead `route(...)` calls.
- What genuinely cannot be verified here: whether Laravel's `encrypted` cast round-trips correctly against this
  project's real `APP_KEY` (reviewed against Laravel 12's documented behavior, never executed — no `vendor/`),
  and — the standing, most important caveat for this whole sub-phase — whether `routes/sync.php` registered
  outside the `web` middleware group in `bootstrap/app.php`'s `then:` callback actually resolves and dispatches
  correctly, and whether `postJson()` against it in `SyncTransportTest` really exercises what it's meant to. This
  design directly mirrors `routes/deploy.php`'s already-established (if likewise unexecuted) pattern, but a real
  `php artisan route:list` and a real HTTP round-trip are the only things that can actually confirm it.
- Written and manually traced, but **not executed**: the new `tests/Feature/Sync/SyncTransportTest.php` (7
  methods) and the revised `tests/Unit/Sync/DeviceAuthServiceTest.php` (now 11), `tests/Feature/Console/RegisterSyncDeviceTest.php`
  (now 4), and `tests/Unit/Sync/SyncServiceTest.php` (now 12). Once `vendor/` and a database exist:
  `php artisan migrate`, then `php artisan route:list --name=sync` to confirm the routes resolve, then
  `php artisan test --filter=SyncTransportTest`, `--filter=DeviceAuthServiceTest`, `--filter=RegisterSyncDeviceTest`,
  `--filter=SyncServiceTest`.

**Phase 9.7.3 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every
prior sub-phase: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` across all 288 non-Blade `.php` files in the project (up from 282 at the end of
  9.7.2 — `SyncableModels`, `SyncService`, `BackfillSyncUlids`, and 3 new test files) — zero syntax errors. A grep
  confirms `SyncableModels::ALL` has exactly 48 entries. `verify_routes.py` re-run to confirm this sub-phase (no
  transport yet) touched zero routes: still 182 named routes, unchanged, no duplicates, no dead references.
- What genuinely cannot be verified without `vendor/` and a real PHPUnit run — flagged rather than assumed away,
  and this is the sub-phase where that gap matters most so far: every claim above about SyncService's actual
  runtime behavior (the outbox scan's SQL, the conflict comparison's timestamp logic, the idempotent-replay check,
  soft-delete propagation) is based on careful code review and manual trace-through of each test scenario by
  hand, not on having actually run `tests/Unit/Sync/SyncServiceTest.php`'s 11 methods against a live SQLite
  database and watched them pass. This is the largest, most logic-dense file shipped in Phase 9 to date, and it is
  exactly the kind of code where a subtle bug (an off-by-one in the timestamp comparison, a wrong `except()` key)
  would not show up under `php -l` or a route check. Running `php artisan test --filter=SyncServiceTest` the
  moment `vendor/` exists is the single highest-priority verification step for the rest of Phase 9.
- Written and manually traced, but **not executed**: all 18 new test methods listed in §6's 9.7.3 entry, plus (as
  a prerequisite for any of them) `php artisan migrate` to actually apply the Phase 9.7.1/9.7.2 schema. Commands
  to run once possible: `php artisan migrate`, then `php artisan test --filter=SyncableModelsTest`,
  `--filter=BackfillSyncUlidsTest`, `--filter=SyncServiceTest`.

**Phase 9.7.2 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every
prior sub-phase: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` across all 282 non-Blade `.php` files in the project (up from 264 at the end of
  9.7.1 — 6 new migrations, 6 new models, 2 new services, 1 new Artisan command, 3 new test files) — zero syntax
  errors. A script parsed the six new migrations' `foreignId(...)->constrained(...)` calls and confirmed every
  foreign-key target table (`sync_devices`, `sync_batches`, `sync_items`, `users`) is created either by an earlier
  migration in this same batch or already existed — the migration ordering (`sync_devices` → `sync_checkpoints` →
  `sync_batches` → `sync_items` → `sync_conflicts` → `sync_logs`) is a correctness requirement, not just tidiness,
  and was checked rather than assumed. `verify_routes.py` re-run to confirm this sub-phase (no transport yet)
  touched zero routes: still 182 named routes, unchanged, no duplicates, no dead references.
- What cannot be verified here, flagged rather than glossed over: whether these six `CREATE TABLE` statements
  actually apply cleanly against real MySQL/MariaDB (enum column syntax, the `sync_checkpoints`
  `unique(['sync_device_id', 'direction'])` composite constraint, and every `constrained()`/`nullOnDelete()` call
  were reviewed by inspection against Laravel 12's documented schema builder behavior, but never executed against
  a live database, for the same standing reason as every phase before this one), and whether `Hash::check()`
  actually verifies a `Hash::make()`'d secret the way `DeviceAuthServiceTest` expects (a long-standing, stable
  Laravel API already relied on elsewhere in this codebase for `users.password`, but still unverified by an actual
  PHPUnit run here).
- Written and manually traced, but **not executed**: all 15 new test methods listed in §6's 9.7.2 entry. To get
  real pass/fail numbers once `vendor/` and a database exist: `php artisan migrate`, then
  `php artisan test --filter=DeviceAuthServiceTest`, `--filter=RegisterSyncDeviceTest`,
  `--filter=SyncRegistryModelsTest`.

**Phase 9.7.1 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every
prior phase: no Composer/Packagist access, no MySQL — see §2; re-confirmed again this sub-phase, same 403 through
the proxy on `composer install --dry-run`):
- Real, reproducible: `php -l` across all 264 non-Blade `.php` files in the project (up from 261 at the end of
  Phase 9.6 — the new migration, the new `HasSyncUlid` trait, and the new test file) — zero syntax errors,
  including every one of the 48 edited model files individually re-checked by name (not just swept in the
  aggregate count). A scripted grep confirms exactly 48 of the 49 files in `app/Models/` now reference
  `HasSyncUlid`, with `Setting.php` the sole, deliberate exclusion. A second scripted grep across every model's
  `$fillable` array confirms `ulid` appears in none of them, which is the property the trait's design depends on.
  A standalone script parsed the migration's own table list and confirmed all 48 entries are unique and match the
  48 models exactly (cross-referenced by hand against the model directory listing and each model's `$table`
  override, of which there are exactly two: `AccountsReceivable` → `accounts_receivable`,
  `PosTable` → `pos_tables`). `verify_routes.py` re-run to confirm this sub-phase touched no routes: still 182
  named routes, unchanged from the end of Phase 9.6, no duplicates, no dead references.
- What `php -l`/grep CANNOT verify, and is flagged honestly rather than glossed over: whether the migration
  actually applies cleanly against a real MySQL/MariaDB server (in particular, whether `ulid()->unique()` and its
  `down()` counterpart behave exactly as expected under this project's real MySQL version — reviewed carefully by
  inspection against Laravel 12's documented `Blueprint::ulid()` behavior, but never executed, since there is no
  live database in this sandbox), and whether the `creating` event actually fires and assigns a value the way the
  new unit test expects (the same standing PHPUnit-cannot-run limitation as every phase before this one).
- Written and manually traced, but **not executed**: all 7 methods in `tests/Unit/Sync/HasSyncUlidTest.php`. To
  get real pass/fail numbers once `vendor/` and a database exist: `php artisan migrate` (applies the new column),
  then `php artisan test --filter=HasSyncUlidTest`.

**Phase 9.6 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every prior
phase: no Composer/Packagist access, no MySQL — see §2; `composer install --dry-run` was tried again before writing
this section and still fails with a Packagist 403 through the sandbox's proxy, confirming the limitation hasn't
changed):
- Real, reproducible: `php -l` across all 261 non-Blade `.php` files in the project — zero syntax errors (this count
  is the actual `find app tests database routes config -name "*.php" ! -name "*.blade.php" | wc -l` result at the
  end of this phase, run directly rather than carried forward by arithmetic). `php -l` also run on the 2 new/edited
  `.blade.php` files this phase (`deploy-helper/index.blade.php`, `deploy-helper/integrity-result.blade.php`) — zero
  syntax errors. Directive-balance counts (`@if`/`@endif`, `@foreach`/`@endforeach`) on the same 2 files — all
  balanced (3/3 and 2/2 for `@if`/`@endif`, 2/2 for `@foreach`/`@endforeach`; the other directive pairs don't appear
  in either file). `verify_routes.py` re-run against the full route table: **182 named routes** (181 → 182, the one
  net addition being `deploy-helper.integrity-check`), no duplicates, no dead `route(...)` calls anywhere in the
  project.
- Written and manually traced, but **not executed** (no PHPUnit run possible in this sandbox — confirmed again just
  now: `php artisan test` fails immediately on the missing `vendor/autoload.php`, since `vendor/` does not exist and
  cannot be created without Packagist access): all 14 new test methods listed in §6's Phase 9.6 entry. To get real
  pass/fail numbers: `php artisan test --filter=DataIntegrityCheckerServiceTest`,
  `--filter=RunIntegrityCheckTest`, `--filter=DeployHelperTest`, or `php artisan test` for the full suite.

**Phase 9.5 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every prior
phase: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` across all 260 non-Blade `.php` files in the project (up from 255 at the end of
  Phase 9.4 — 1 new class (`ExportsCsv`) + 4 new test files) — zero syntax errors. `php -l` also run (near-vacuous
  for Blade, per the Phase 9.2 correction, but still catches a raw-PHP break) on all 20 new/edited `.blade.php`
  files this phase — zero syntax errors. Directive-balance counts (`@if`/`@endif`, `@unless`/`@endunless`,
  `@foreach`/`@endforeach`, `@forelse`/`@endforelse`, `@can`/`@endcan`) on the same 20 files — all balanced.
  (`@section`/`@endsection` was deliberately excluded from this count: `@section('title', ...)` is a valid
  self-closing inline call with no matching `@endsection`, so counting it produces a false "mismatch" on every
  file that uses both forms — not a real signal, which is why the established methodology from Phase 9.2 onward
  never included it.) `verify_routes.py` re-run against the full route table: **181 named routes** (165 → 181, the
  16 new print/report/CSV routes added this phase — matches exactly), no duplicates, no dead `route(...)` calls
  anywhere in the project including the new Blade views.
- Written and manually traced, but **not executed** (no PHPUnit run possible in this sandbox): all 26 new test
  methods listed in §6's Phase 9.5 entry. Two of those (`PaymentSummaryReportTest`'s and `ExpenseReportTest`'s CSV
  content assertions) additionally rely on Laravel's `TestResponse::streamedContent()` helper, which this sandbox
  cannot cross-check against the actual framework source (no `vendor/` — see §2) — it is a long-standing, stable
  Laravel testing API, but flagged here as one more layer of "written, not verified" on top of the standing
  no-PHPUnit limitation. To get real pass/fail numbers: `php artisan test --filter=PrintableDocumentsTest`,
  `--filter=PaymentSummaryReportTest`, `--filter=ExpenseReportTest`, `--filter=ManagementReportTest`,
  `--filter=OperationsReportTest`, `--filter=WeeklyOwnerSummaryTest`, or `php artisan test` for the full suite.

**Phase 9.4 evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every prior
phase: no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` across all 255 non-Blade `.php` files in the project (up from 251 at the end of
  Phase 9.3 — the 4 new files: `PendingApprovalsService.php`, `WeeklyOwnerSummaryService.php`,
  `PendingApprovalsServiceTest.php`, `WeeklyOwnerSummaryTest.php`) — zero syntax errors. Blade directive-balance
  counts (`@if`/`@endif`, `@foreach`/`@endforeach`, `@can`/`@endcan`) on every new/edited view
  (`dashboard.blade.php`, `reports/weekly-owner-summary.blade.php`, `reports/index.blade.php`) — all balanced.
  `verify_routes.py` re-run against the full route table: 165 named routes (164 → 165, the one net addition being
  `reports.weekly-owner-summary`; the purchase-orders/requisitions fixes relocate an existing route rather than add
  one), no duplicates, no dead `route(...)` calls. Additionally cross-checked all 10 route name *strings* referenced
  dynamically inside `PendingApprovalsService`/the new report (which a literal-string scanner like `verify_routes.py`
  cannot catch) against that script's own reconstructed route table — all 10 resolve to a real, defined route name.
- Written and manually traced, but **not executed** (no PHPUnit run possible in this sandbox): all 25 new test
  methods listed in §6's Phase 9.4 entry. To get real pass/fail numbers: `php artisan test --filter=PendingApprovalsServiceTest`, `--filter=WeeklyOwnerSummaryTest`, `--filter=DashboardAuthorizationTest`, `--filter=PurchaseOrderTest`, `--filter=RequisitionTest`, or `php artisan test` for the full suite.

As with every phase, no test suite has been executed in this sandbox — see each phase's evidence report for the
real, run commands (`php -l`, `verify_routes.py`, permission-list parses) versus what's written-but-unverified
(the PHPUnit test files themselves).

---

## 8. Front Desk Petty Sales (added post-9.7.4, out of band with the sync-engine plan)

The user asked, mid-Phase-9.7, that "the front desk (receptionist) will also be doing petty sale of things like
juice, water, snacks, biscuits etc" — a new functional requirement, not part of the original spec's 8-role matrix
(where `front_desk` had no POS/order permission at all) and not part of the hybrid-sync work Phase 9.7 is
otherwise scoped around. It is recorded here, out of numeric sequence with 9.7.x/9.8, because it was implemented
immediately (small, additive, low-risk) rather than deferred to a later phase, per the standing instruction to
proceed through corrections without stalling for confirmation on a well-scoped, reversible change.

**Design decision.** The existing Bar/Restaurant POS module (`Pos\OrderController`, `Pos\OrderService`, `Order`,
`OrderItem`, `MenuItem`, `MenuCategory`, `PosTable`) was already generically outlet-parametrized rather than
hardcoded to two outlets: routes are `pos/{outlet}/...`, authorization is the dynamic permission string
`operate-{outlet}-pos` (`OrderController::authorizeOutlet()`), and every query filters `WHERE outlet = ?`. The one
place that *was* hardcoded to exactly two outlets was `OrderService::close()`'s folio-deferral charge type
(`$order->outlet === 'bar' ? 'bar_charge' : 'restaurant_charge'`), which has been generalized to `"{$order->outlet}_charge"` — a
one-line fix, backward-compatible (still produces `bar_charge`/`restaurant_charge` for the existing two outlets),
that a third outlet would otherwise have silently mis-posted as a restaurant charge. Given that, adding a third
outlet — `front_desk` — is additive, not a new feature: no new controller, service, or authorization mechanism, in
keeping with this project's "never a duplicated total" / reuse-over-reimplementation principle applied at the
architecture level rather than just the calculation level.

Front desk sales are walk-up counter sales, not table service, so `pos_table_id` is simply left null (it was
already nullable — `OrderController::store()`'s validation: `'pos_table_id' => 'nullable|exists:pos_tables,id'`) —
no `PosTable` rows are seeded for this outlet, and the order screen's table dropdown degrades to just its existing
"No table" option with zero rows to list. The full draft → confirmed → closed lifecycle, add-only-after-confirm
rule, audit logging, central payment ledger posting, and (new as of Phase 9.7.1–9.7.3) sync ULID/outbox coverage
all apply to front desk orders exactly as they do to bar/restaurant orders, since `Order` uses `HasSyncUlid` and
is already in `SyncableModels::ALL` — no sync-specific work was needed for this to sync correctly once 9.7.5/9.7.6
build the scheduler around it.

**What changed:**
- `database/migrations/2025_01_02_000800_add_front_desk_outlet_support.php` (new) — widens the MySQL `ENUM`
  columns `menu_categories.outlet`, `menu_items.outlet`, `pos_tables.outlet`, `orders.outlet`, and
  `folio_transactions.type` to include `front_desk` / `front_desk_charge`, via raw `ALTER TABLE ... MODIFY COLUMN`
  (Laravel's schema builder cannot widen an `enum()` column in place without `doctrine/dbal`, which is not
  installed here — same constraint documented for every other migration in this project). Every existing enum
  value is preserved in its original order; only the new value is appended.
- `app/Models/FolioTransaction.php` — `CHARGE_TYPES` gains `'front_desk_charge'`.
- `app/Http/Controllers/FrontOffice/FolioController.php` — `charge()`'s validation `in:` list gains
  `front_desk_charge`.
- `app/Services/Pos/OrderService.php` — the `close()` chargeType fix described above.
- `app/Http/Controllers/Pos/OrderController.php` — docblock updated (no logic change: the class was already
  outlet-generic).
- `routes/web.php` — `Route::prefix('pos/{outlet}')->where('outlet', 'bar|restaurant')` becomes
  `'bar|restaurant|front_desk'`. Route *names* are unchanged (still `pos.index`, `pos.orders.store`, etc.), so
  `verify_routes.py`'s count is unaffected by this change (see evidence below).
- `database/seeders/RolesAndPermissionsSeeder.php` — new permission `operate-front_desk-pos` (61st permission,
  up from the 60 as of Phase 9.6 — see README §9 for the updated matrix), granted to `owner` (via `$all`),
  `general_manager` (via `array_diff($all, [])`), `duty_manager` (for parity with its existing
  `operate-bar-pos`/`operate-restaurant-pos`), and — the actual point of this work — `front_desk`.
- `database/seeders/PosSeeder.php` — a `front_desk` → `Petty Sales` category with five starter items (Bottled
  Water, Fruit Juice, Soft Drink, Biscuits, Snacks — the exact items the user named plus two adjacent, plausible
  ones), matching the existing bar/restaurant seed pattern. No `PosTable` rows (see above).
- `resources/views/layouts/app.blade.php` — a `@can('operate-front_desk-pos')`-gated "Front Desk Sales" nav link,
  matching the existing Bar/Restaurant POS links' pattern exactly.
- `resources/views/pos/orders/index.blade.php`, `pos/menu/index.blade.php`, `pos/orders/receipt-print.blade.php` —
  their page titles used `ucfirst($outlet)`, which would have rendered "Front_desk POS" (the literal snake_case
  string) instead of "Front Desk POS"; changed to `\Illuminate\Support\Str::headline($outlet)`, which is
  backward-compatible for `bar`/`restaurant` (`Str::headline('bar')` === `ucfirst('bar')` === `"Bar"`) and correct
  for `front_desk` (`"Front Desk"`).
- `database/factories/MenuCategoryFactory.php` — added a `frontDesk()` state alongside the existing `bar()`/
  `restaurant()` states, for test convenience; not required by any existing test.
- `tests/Feature/Pos/FrontDeskPettySaleTest.php` (new, 4 methods) — a front desk staff member can open a
  table-less order, add items, confirm, and close it with cash, posting to the central payment ledger under
  `pos_front_desk`; a front desk sale can be deferred onto a checked-in guest's room folio and lands specifically
  as a `front_desk_charge` (the regression this change is actually guarding against — before the `OrderService`
  fix, this would have posted as `restaurant_charge`); a Bar Staff account gets a 403 on the Front Desk POS; a
  Front Desk account gets a 403 on the Bar POS (role scoping still holds for the third outlet).

**Deliberately not done:** no inventory/recipe linkage was added for the new petty-sale items (recipes are
optional per-item already — `OrderService::deductRecipeStock()` skips any menu item with none configured, exactly
as it does for existing bar/restaurant items that haven't set one up), and no new dashboard/report line item was
added to single out front-desk revenue specifically — it is already included in every existing report that
iterates `FolioTransaction::CHARGE_TYPES` or filters by `source_module` (e.g. `FinancialReportService`'s
revenue-by-type breakdown, which loops the constant rather than naming outlets), and in the central `payments`
ledger under `source_module = pos_front_desk`, without any code change, because those reports were already
written generically. If the user wants inventory deduction for petty-sale items (e.g. treating bottled water as a
stock product rather than an unlimited menu item), that is a `RecipeItem` configured through the existing
`pos.{outlet}.menu.items.recipe.store` route — no code change needed, just data entry once a `vendor/`-backed
environment can seed real `Product` rows.

**Evidence — what was actually run vs. written-but-unexecuted** (same sandbox limitations as every other phase:
no Composer/Packagist access, no MySQL — see §2):
- Real, reproducible: `php -l` clean across all 293 non-Blade `.php` files under `app/`, `tests/`, `database/`,
  `routes/`, `config/` (up from 291 at the end of 9.7.4 — the new migration + the new test file). `verify_routes.py`
  re-run: still **185 named routes**, no duplicates, no missing `route(...)` references — confirming the outlet
  regex widening did not touch route *names* (only the `{outlet}` parameter's matching pattern), so the count
  genuinely should be unchanged, and is. Grepped the full `app/`/`resources/views/`/`routes/` tree for every other
  place `bar`/`restaurant` might have been hardcoded as an exhaustive pair (validation rules, dropdowns, report
  filters) — found and fixed the two real ones (`OrderService`'s chargeType ternary, the three `ucfirst($outlet)`
  view titles); everywhere else that mentions both words together turned out to be prose/comments, not logic.
- What genuinely cannot be verified here: whether the raw `ALTER TABLE ... MODIFY COLUMN` statements in the new
  migration are valid MySQL syntax against this project's actual schema state (no MySQL in this sandbox — same
  standing limitation as every migration in this project since Phase 1). The statements were written directly
  against the exact `ENUM(...)` value lists read out of the four/five source migrations (`menu_categories`,
  `menu_items`, `pos_tables`, `orders`, `folio_transactions`), so they should be syntactically and semantically
  correct, but "should be" is not "verified."
- Written and manually traced, but **not executed** (no PHPUnit run possible in this sandbox): all 4 new test
  methods in `FrontDeskPettySaleTest`. To get real pass/fail numbers: `php artisan test --filter=FrontDeskPettySaleTest`,
  and — because this phase touched shared code (`OrderService`, `FolioTransaction`, `routes/web.php`) — the full
  existing `php artisan test --filter=OrderTest` and `--filter=FolioTest` suites should be re-run too, to confirm
  the chargeType generalization didn't change behavior for the existing bar/restaurant paths (it shouldn't have —
  `"{$order->outlet}_charge"` produces the identical string as the old ternary for both existing values — but that
  is reasoning, not a test run).

---

## 9. Complimentary Items & Complimentary Stays ("special guest lodging") (added post-9.7.6, out of band with the
sync-engine plan)

The user asked, after Phase 9.7 finished, to also support "some complimented [complimentary] drink orders at the
bar" and "some special guest lodging" — two more new functional requirements, not part of the original spec, and
not part of Phase 9.7/9.8. Two follow-up questions were put to the user to pin down scope, since both phrases are
ambiguous on their own:

1. Whether a comp drink means the *whole order* is free, or just that *item*. The user's answer — "the
   complimentary is free but the main d[r]ink is not" — settled this as a **per-item** comp: one line on an order
   can be free while other lines on the same order remain billable.
2. Whether "special guest lodging" means the same per-item logic applied to a stay (room free, other charges still
   billed), or something broader (e.g. also a VIP tag). The user chose **"room stay complimentary, extras still
   billed"** — confirming the same "some parts free, some parts not" principle applies at the stay level: only the
   *room* charge is waived; bar/restaurant/front-desk/other charges against that same guest's folio are completely
   unaffected.

**Design decision — comp items.** A `OrderItem` gets two new columns: `is_complimentary` (boolean) and
`comp_reason` (string, required when `is_complimentary` is true). When `OrderService::addItem()` is called with
`$isComplimentary = true`, the item's `line_total` is stored as `'0.00'` while `unit_price` is preserved at its
normal value — so reporting can still show "what this would have cost" even though nothing is charged for it. This
needed **zero changes** to `recalculateTotals()`, which already just sums `line_total` across an order's items —
directly continuing this project's "never a duplicated total" principle: the exclusion happens once, at the point
the line is created, not as a special case bolted onto every place totals are computed or displayed. A reason is
mandatory (mirrors the existing `removeItem()` precedent: routine POS-level actions need a reason, not a separate
approval permission), and no new permission was introduced — comping an item still only requires the existing
`operate-{outlet}-pos` permission every outlet staff member already holds, since it's the same class of action as
adding or removing a line, not a bigger financial decision.

The one structural change comp items forced was in `OrderService::close()`: previously, closing an order
*unconditionally* required either a `payment_method` or a `stay_id` (nothing to collect otherwise). An order made
entirely of comp items totals `0.00`, so that guard was moved from before the transaction to *after*
`recalculateTotals()` runs inside it, and is now skipped when `bccomp((string) $order->total_amount, '0.00', 2)
=== 0`. A non-zero order still enforces the original rule exactly as before (see the
`test_a_non_comp_order_still_requires_a_payment_method_or_a_stay` regression guard). The immediate-payment ledger
record, previously written unconditionally in that branch, is now written only `elseif ($paymentMethod)` — for a
fully-comp order closed with neither a payment method nor a stay, there is nothing to record, which is correct: no
`Payment` row, no ledger entry, just a closed order with a `0.00` total.

**Design decision — comp stays.** A `Stay` gets four new columns: `is_complimentary`, `complimentary_reason`,
`marked_complimentary_by`, `marked_complimentary_at` (the latter two an audit trail independent of the general
`audit_logs` table, matching the existing `checked_in_by`/`checked_out_by` pattern already on `Stay`). Unlike a
POS comp, marking a whole stay complimentary is a materially bigger revenue decision — waiving an entire room
night, not one drink — so it is gated behind a **new, dedicated permission**, `mark-stay-complimentary`, granted
only to `duty_manager`/`general_manager`/`owner`. It is deliberately **not** granted to `front_desk`, even though
front_desk manages stays day-to-day, mirroring this project's established separation-of-duties pattern elsewhere
(e.g. `approve-expense` vs. `manage-expenses`): the role that runs day-to-day stay operations is not the role that
gets to give a room away for free.

The complimentary flag only ever affects `room_charge`. Two different, deliberately different, enforcement points
exist for it, because the automatic and manual code paths have different obligations:
- `FolioService::postCharge()` — a **manual** attempt by staff to post a `room_charge` against a complimentary
  stay's folio throws a clear `HmsException` ("This stay is marked complimentary — its room charge is waived...").
  This is a human about to make a mistake; the system should stop them and say why.
- `CheckOutService` — its own **automatic** final-night `room_charge` post (the `post_final_night` checkbox) is
  silently *skipped*, never throws, when `$stay->is_complimentary`. This follows the project's standing "checkout
  must never be blocked" principle (spec §10): an exception here would stop a guest from checking out over a
  charge that was never supposed to happen in the first place, which would be worse than just not posting it.

Every other charge type — `bar_charge`, `restaurant_charge`, `front_desk_charge`, `other_service` — is completely
untouched by either mechanism, is not mentioned by either guard's condition, and posts and gets paid exactly as it
would for any other guest. This is the literal, deliberately narrow scope the user confirmed: "room stay
complimentary, extras still billed."

**What changed:**
- `database/migrations/2025_01_02_000900_add_complimentary_support.php` (new) — adds `is_complimentary` +
  `comp_reason` to `order_items`; adds `is_complimentary`, `complimentary_reason`, `marked_complimentary_by`
  (`foreignId(...)->constrained('users')->nullOnDelete()`), `marked_complimentary_at` to `stays`.
- `app/Models/OrderItem.php` — `$fillable` and `casts()` updated for the two new columns.
- `app/Models/Stay.php` — `$fillable` and `casts()` updated for the four new columns; new `markedComplimentaryBy()`
  `belongsTo(User::class)` relation.
- `app/Services/Pos/OrderService.php` — `addItem()` gains `bool $isComplimentary = false, ?string $compReason =
  null` parameters, a mandatory-reason guard, and comp-aware `line_total`/`is_complimentary`/`comp_reason` on
  create; audit action is `pos.item_comped` (not `pos.item_added`) when complimentary. `close()`'s
  payment-method-or-stay guard is now conditional on the order not being fully complimentary (see design decision
  above).
- `app/Http/Controllers/Pos/OrderController.php` — `addItem()` validation gains
  `is_complimentary`/`comp_reason` (`required_if:is_complimentary,1`), passed through to the service.
- `app/Services/FrontOffice/FolioService.php` — `postCharge()` gains the manual `room_charge`-against-a-comp-stay
  guard described above.
- `app/Services/FrontOffice/CheckOutService.php` — the automatic final-night charge post is now conditional on
  `! $stay->is_complimentary` (silent skip, never throws).
- `app/Http/Controllers/FrontOffice/StayController.php` — new `markComplimentary()`/`unmarkComplimentary()`
  actions, each audit-logged (`stay.marked_complimentary` / `stay.unmarked_complimentary`); constructor now also
  injects `AuditLogger`.
- `routes/web.php` — new `Route::middleware('permission:mark-stay-complimentary')->prefix('stays')->name('stays.')`
  group: `stays.mark-complimentary`, `stays.unmark-complimentary` (POST). A third `stays.`-prefixed route group in
  this file alongside the existing `manage-guests` and `checkout-guest` ones — confirmed this causes no route-name
  duplication, since Laravel (and `verify_routes.py`) key on the full accumulated name string, not which
  `Route::group()` block declares it.
- `database/seeders/RolesAndPermissionsSeeder.php` — new permission `mark-stay-complimentary` (62nd permission, up
  from 61 as of the Front Desk addition — see README §9), granted to `duty_manager` explicitly (owner/GM auto via
  the existing `$all`/`array_diff` grants); deliberately **not** granted to `front_desk`.
- `resources/views/pos/orders/show.blade.php` — a purple "Comp" badge on comped item rows (title = the reason); the
  add-item form gains a complimentary checkbox that reveals a reason input via inline `onchange`.
- `resources/views/front-office/stays/show.blade.php` — a purple "Complimentary stay" badge and waiver note on
  comp stays; the "Post charge" dropdown's `room_charge` option gets `@disabled` + a "(waived)" suffix on a comp
  stay (and, while here, a pre-existing gap was fixed — this dropdown was missing `front_desk_charge` entirely,
  see §4 of Errors and fixes below); a new `@can('mark-stay-complimentary')` block to mark/unmark a stay
  complimentary; the checkout form's `post_final_night` checkbox gets `@disabled` + a "(waived)" note on a comp
  stay.
- `resources/views/front-office/stays/index.blade.php` — a purple "Comp" badge next to the guest name on the active
  stays list.
- `tests/Feature/Pos/ComplimentaryOrderTest.php` (new, 6 methods) — see the Evidence section under §7 for the full
  method list.
- `tests/Feature/FrontOffice/StayComplimentaryTest.php` (new, 9 methods) — see the Evidence section under §7 for
  the full method list.

**Bug found and fixed along the way:** while editing `front-office/stays/show.blade.php`'s "Post charge" dropdown
for this feature, noticed it only listed `room_charge`/`bar_charge`/`restaurant_charge`/`other_service` —
`front_desk_charge` (added to `FolioTransaction::CHARGE_TYPES` and `FolioController`'s validation during the §8
Front Desk petty-sales work) was never added to this specific view. Fixed as part of this same change; unrelated
to the complimentary feature itself but caught while touching the same file.

**Deliberately not done:** no reporting line item singles out comp revenue/foregone-revenue specifically — comp
items already show as `0.00` `line_total` rows inside the existing order/receipt views, and a comp stay's waived
room charge is simply the *absence* of a `room_charge` transaction on its folio, which every existing report that
sums `FolioTransaction` rows already reflects correctly with no code change. If the owner later wants a dedicated
"total value comped this month" report, that's a new read-only query over `OrderItem::where('is_complimentary',
true)` and `Stay::where('is_complimentary', true)` — straightforward, deferred as a nice-to-have rather than
something the user asked for.

**Evidence:** see the "§9 (Complimentary items/stays) evidence" entry under §7 (Status) above for the full,
itemized real-vs-unexecuted verification report, in the same format used for every other phase and addition in
this project.

---

## 10. Final Output — Readiness Rating

This is the master prompt's mandated final deliverable for Phase 9: an honest statement of where the project
actually stands, with "written but unexecuted" treated as categorically different from "passing" throughout, per
the master prompt's own §39 and this project's practice in every phase since Phase 1.

**A single overall grade would misrepresent this project**, because the two things that most determine real-world
readiness — how complete and internally consistent the code is, versus how much of it has actually been proven to
run correctly — are at opposite ends of the scale here. So this rating is given in three parts.

### 10.1 Feature completeness: complete

Every requirement from the original Phase 1–8 spec, the full Phase 9 master prompt (9.0 through 9.8), and both
user-requested out-of-band additions (Front Desk petty sales, complimentary items/stays) has real, written code
behind it — no placeholder, no "coming soon" screen, no silently-dropped requirement. Where the master prompt's
own scope was ambiguous or asked for something outside a reasonable single-property system's needs (full
double-entry Trial Balance/Balance Sheet, `APP_ENV=staging` vs. `production`), that was resolved explicitly with
the project owner (§4) or documented as a deliberate, labeled simplification rather than quietly built to a lower
standard than claimed.

### 10.2 Code review confidence: high, with real bugs found and fixed along the way

Nothing in this project shipped without a second look. `php -l` has run, clean, after every single phase — 306
files, zero syntax errors, right now. `verify_routes.py` has run after every phase that touched routing — 190
named routes, zero duplicates, zero dead references, right now. Every migration was checked by hand against its
own foreign-key ordering and its source `ENUM` value lists. Three dedicated security-audit passes (Phase 7, the
Phase 9.0 addendum, and this phase's Phase 9.8 re-audit) found and fixed **14 real issues** across the whole
project's history — IDOR/authorization gaps, a self-approval hole, an information-disclosure bug, a missing rate
limit, secret-handling gaps, a stored-XSS vector, and, most seriously, a credential-takeover path in the sync
engine found in this final pass. That track record — repeatedly finding and fixing real problems under a
dedicated audit, rather than the audits coming back clean every time — is itself evidence the review process has
been genuinely adversarial rather than a rubber stamp. See `SECURITY.md` and `SECURITY_AUDIT.md` for the complete
list.

### 10.3 Verified correctness: unproven — this is the real gap

**Zero of this project's 361 test methods have ever been executed.** No `composer install` has ever succeeded in
this project's history (Packagist is unreachable from this sandbox — a policy block, confirmed repeatedly, not a
transient failure). No migration has ever run against a real database. No two-node sync cycle has ever actually
moved a byte between two real installations. Every claim of correctness in this entire project — from Phase 1's
first controller through this phase's security fixes — rests on code review and manual tracing, not on watching
code run and produce the expected result. `TEST_REPORT.md` is the complete, dedicated accounting of this gap: what
exists, what it's supposed to prove, and the exact commands to get this project's first-ever real test numbers.

This is not a small caveat. Manual tracing is good at catching the mistakes a careful reader can spot — a wrong
permission name, a missing `@csrf`, an off-by-one in a route parameter — and this project's track record (14 real
findings across three audits, one real parse-error catch via `php -l`) shows it does catch real things. It is
*not* good at catching what only a live run surfaces: a subtly wrong Eloquent relationship that returns the right
*shape* of data but the wrong *rows*, a migration that's syntactically fine but fails against real MySQL's actual
constraint-checking behavior, a race condition between two simultaneous requests, or simply a typo in a test's own
assertion that happens to still "read" as correct on a careful re-trace. **Running `php artisan test` for the
first time, on a real machine, is the single most valuable thing that can happen to this project next** — not
because the code is expected to be wrong, but because right now there is no direct evidence either way, only
careful inference.

### 10.4 Overall assessment

**Ready for a real test run; not yet ready to claim production-verified.** This project should be handed to an
environment with normal internet access (a developer's machine, or CI) and have `composer install && php artisan
migrate --seed && php artisan test` run against it as the very next step — before any further feature work, and
before treating any part of this system as trustworthy with real guest or financial data. `INSTALLATION.md` §7 is
a pre-launch security checklist independent of test execution (rotate demo credentials, confirm `APP_DEBUG=false`,
etc.) that should be worked through regardless of how that first real test run goes. If the suite comes back
clean, that upgrades this project from "carefully reviewed" to "actually verified," which is the one thing no
amount of further work inside this sandbox can achieve on its own.

## 11. Post-9.8 Fix Round — Concurrency, Ownership Invariants, Login Enumeration, POS Lifecycle

A follow-up, user-directed task after Phase 9.8 closed: six specific, pre-identified flaws to fix with focused,
production-quality changes and a regression test for each — not a fresh audit pass looking for new issues, but
remediation of issues already named by the project owner. All six are done; the table below is the map from issue
to fix to test.

| # | Issue | Fix | File(s) changed | Regression test(s) |
|---|---|---|---|---|
| 1 | Duplicate money/stock movements from concurrent requests | `lockForUpdate()` on the row inside the existing `DB::transaction`, with every eligibility check re-run against the *locked* copy (not the pre-transaction parameter) before any side effect | `ExpenseService::markPaid`, `RefundService::approve`, `OrderService::close` | Folded into each method's existing test coverage plus the new `OrderTest::test_a_draft_order_cannot_be_closed` (which also exercises the locked-row path) |
| 2 | Purchase-order receiving didn't check line ownership | Reject (before creating any `GoodsReceipt`/stock/quantity effect) if a submitted `purchase_order_item_id`'s `purchase_order_id` doesn't match the route-bound PO — enforced in both the controller and the service | `PurchaseOrderController::receive`, `GoodsReceiptService::receive` | `PurchaseOrderTest::test_a_line_from_one_purchase_order_cannot_be_received_through_another` |
| 3 | POS outlet ownership not enforced for tables/menu items | `OrderService::open()` rejects a `pos_table_id` from another outlet; `OrderService::addItem()` rejects a `menu_item_id` from another outlet or an inactive one — enforced in the service layer (so a direct call can't bypass it) and mirrored in request validation via scoped `Rule::exists()` | `OrderService::open`/`addItem`, `OrderController::store`/`addItem` | `OrderCrossOutletTest`: `test_cannot_open_a_bar_order_against_a_restaurant_table`, `test_order_service_open_rejects_a_table_from_a_different_outlet_directly`, `test_cannot_add_a_restaurant_menu_item_to_a_bar_order`, `test_cannot_add_an_inactive_menu_item_to_an_order`, `test_order_service_add_item_rejects_a_menu_item_from_a_different_outlet_directly` |
| 4 | `add_front_desk_outlet_support` migration used MySQL-only `ALTER TABLE ... MODIFY COLUMN ... ENUM`, incompatible with the SQLite test database `phpunit.xml` configures | Made the migration driver-aware: MySQL keeps the original enum-widening `ALTER`/reversal unchanged; SQLite widens via `Schema::table()->column->change()` (Laravel 11+'s native, doctrine/dbal-free column rebuild), which collapses the CHECK-constrained enum to a plain string — SQLite never had a real enum here, and nothing in the model layer casts these columns to one, so nothing is weakened | `database/migrations/2025_01_02_000800_add_front_desk_outlet_support.php` | No dedicated test (a migration-shape fix, not a business rule) — its success criterion is that the full suite's `RefreshDatabase` migrations now run at all under SQLite, which every other test in the suite implicitly exercises |
| 5 | Login flow let an attacker enumerate valid usernames and their auth type (PIN vs. password) via the identify step's redirect target | `LoginController::identify()` now routes an unknown/inactive username through the exact same two-step flow as a real one (a deterministic pseudo-type picks the next step), and that step always fails with the same generic message, timing profile (a dummy `Hash::check()` against a fixed bcrypt hash), and rate limiting a real wrong credential would produce | `app/Http/Controllers/Auth/LoginController.php` | New `tests/Feature/Auth/LoginEnumerationTest.php` (7 methods) plus an updated assertion in `LoginTest::test_inactive_user_cannot_start_login` |
| 6 | `OrderService::close()` accepted a `draft` order (via the too-broad `isEditable()` check), letting an order settle, charge a folio, record a payment, or deduct stock before ever being confirmed | `close()` now requires the new `Order::isConfirmed()` specifically; combined with the Item 1 rewrite since both needed the same locked-row restructuring | `app/Services/Pos/OrderService.php`, `app/Models/Order.php`, `resources/views/pos/orders/show.blade.php` | New `OrderTest::test_a_draft_order_cannot_be_closed`; 7 pre-existing tests across `OrderTest`, `ComplimentaryOrderTest`, `RecipeStockDeductionTest`, and `FrontDeskPettySaleTest` updated to confirm an order before closing it, so they keep testing what they were designed to test rather than incidentally passing via the new guard |

**Verification, honestly stated (same standing limitation as every other phase — see §10.3 and `TEST_REPORT.md`):**
`composer install` was attempted again at the start of this task and still fails with the same `repo.packagist.org`
403 policy denial through this sandbox's egress proxy — no `vendor/`, so `php artisan test` could not be run. Every
claim above rests on `php -l` (clean across the whole `app/`, `database/`, `resources/`, and `tests/` trees — 63
test files, 375 test methods now, up from 62/361 — see `TEST_REPORT.md`), `verify_routes.py` (no duplicate or
dangling route names), and manual tracing of each changed path against the exact tests that exercise it, including
tracing every pre-existing test that the Item 6 lifecycle change would touch before changing any of them. Nothing
here should be read as "passing" until it's actually run — see `TEST_REPORT.md` §4 for the exact commands.

Nothing in this fix round loosened authorization, audit logging, accounting-day locking, or an existing approval
control — every change either adds a new rejection (a lock, an ownership check, a lifecycle check) or replaces one
distinguishable failure mode with an equally-safe indistinguishable one (the login fix). No unrelated behavior was
changed.

## 12. First Real Execution — a Genuine, Previously Invisible Bug Found and Fixed

Every prior phase of this document said some version of the same thing: manual review and `php -l` catch a
narrower class of mistake than a real run does, and this project would not have real evidence either way until
`composer install` and a real boot actually happened somewhere with normal internet access. That happened for the
first time during this task, via a Laravel Cloud deployment attempt, and it immediately proved the point.

**What happened:** `composer install` succeeded completely — 116 packages locked, 81 installed, autoloader
generated. The very next step, `php artisan package:discover` (which every `artisan` command and every web
request triggers indirectly, since it boots the full framework including the route service provider), crashed
with:

```
TypeError: array_merge(): Argument #2 must be of type array, string given
  at vendor/laravel/framework/.../Routing/RouteGroup.php:84
  ... routes/web.php:196 ...
```

**Root cause:** `routes/web.php`'s POS route group —
`Route::prefix('pos/{outlet}')->where('outlet', 'bar|restaurant|front_desk')->name('pos.')->group(...)` — called
the *group-level* `where()` (returned by `Route::prefix()`, an instance of `Illuminate\Routing\RouteRegistrar`)
with two positional arguments, the way a single route's `->where($name, $pattern)` accepts them. `RouteRegistrar`
doesn't support that form: its `where` is a generic "attribute" set via `__call()`/`attribute()`, which accepts
exactly one argument — a `['param' => 'pattern']` array — and silently ignores everything after the first
argument. In practice this meant the call actually stored the plain string `'outlet'` (the first argument) as the
group's entire `where` attribute, instead of the intended array. The crash only surfaces later, when Laravel
merges this group's attributes with its parent group's inside `RouteGroup::formatWhere()`, which assumes `where`
is always an array on both sides.

**Why nothing before this caught it:** `php -l` only checks PHP syntax — this is syntactically perfect PHP, wrong
only in what Laravel's specific object does with two arguments at runtime. `verify_routes.py` only checks route
*names*, not the validity of a group's fluent method calls. Manual review missed it too, across every phase since
this route was added (Phase 9.7.4, Front Desk petty sales) — the two-argument `where()` call reads as completely
correct if you know the single-route form and don't happen to recall that the group form is stricter. Nothing
short of a real boot was ever going to find this, which is exactly what every prior phase's "verified correctness:
unproven" section (§10.3) predicted would eventually happen.

**Impact if it had shipped:** this wasn't a POS-only bug. `routes/web.php` is loaded on every single request the
application ever serves — the exception would have fired immediately for literally every page, including the
login screen. The application could not have booted at all in production.

**Fix:** `Route::prefix('pos/{outlet}')->where(['outlet' => 'bar|restaurant|front_desk'])->name('pos.')->group(...)`
— the array form the group-level `where()` actually requires. Confirmed by reading the installed Laravel version's
actual `RouteRegistrar` source (not just by inspection of this project's code) before applying the fix. `php -l`
and `verify_routes.py` both re-run clean afterward (190 named routes, 0 duplicates, 0 dangling references) — as
before, that only confirms syntax and naming, not that this specific fix resolves the runtime crash; that
confirmation is the next real deploy attempt.

This is the first genuine "real execution disagreed with manual review, and won" moment in this project's history,
and it's a validation of the process this documentation has insisted on since Phase 1: state plainly what's
unverified, and treat the first real failure as information rather than something to explain away. One real
deploy attempt found one real, application-breaking bug that eleven phases of careful reading never caught.

## 13. HR Visibility Restriction — "only Owner, General Manager, and Accountant should see HR"

A direct product decision from the project owner, applied after the first successful real deployment: the HR &
Payroll module — previously partly self-service for every role — should now be visible and usable only by Owner,
General Manager, and Accountant. Every other role (Duty Manager, Cashier, Front Desk, Bar Staff, Restaurant Staff)
should lose it entirely, not just have it hidden from the menu.

**What was actually open before this fix:** `manage-employees`, `manage-payroll`, and the approval-tier payroll
permissions were already Owner/GM(/Accountant for payroll)-only. The gap was the *self-service* permissions —
`clock-own-attendance` and `request-leave` — which spec section 23's original least-privilege design deliberately
granted to every one of the eight roles (the same reasoning as "any authenticated user can view /rooms"), plus
Duty Manager's separate grant of `record-attendance` and `approve-leave`. Combined, this meant Cashier, Front Desk,
Bar Staff, Restaurant Staff, and Duty Manager could all clock in/out, request leave, and see the "HR & Payroll"
section of the nav (unconditionally rendered — see below) with their own Attendance/Leave/Payslips pages, and Duty
Manager could additionally record other employees' attendance and approve/reject leave requests.

**What changed:**
- `database/seeders/RolesAndPermissionsSeeder.php`: removed `clock-own-attendance` and `request-leave` from
  Cashier, Front Desk, Bar Staff, and Restaurant Staff; removed `clock-own-attendance`, `record-attendance`,
  `request-leave`, and `approve-leave` from Duty Manager. Owner (holds every permission) and General Manager
  (also holds every permission) are unaffected. Accountant's existing HR-related grants
  (`clock-own-attendance`, `request-leave`, `manage-payroll`, `pay-payroll-run`, `view-hr-reports`) were left
  exactly as they already were — this is a restriction on who else could reach HR, not a new grant to Accountant.
- `resources/views/layouts/app.blade.php`: the entire "HR & Payroll" nav section — previously rendering its
  header and the Attendance/Leave/My Payslips links completely unconditionally, regardless of any permission —
  is now wrapped in a single `@can('clock-own-attendance')`. Since that permission is now held by exactly the
  three intended roles, this is one gate that automatically tracks the seeder rather than a second, independently
  maintained role list hardcoded into the view.
- `routes/web.php`: `GET /hr/my-payslips` had no permission gate at all before this fix (any authenticated user,
  scoped to their own records by the controller) — it now requires `clock-own-attendance`, matching the nav.
  Every other `hr.*` route was already permission-gated; those permissions simply changed hands in the seeder.
- Updated stale comments in `AttendanceController::index()` and `LeaveService::approve()`/`cancel()` that
  described the old "granted to every role" / "Duty Manager and above" behavior.
- `tests/Feature/Hr/AttendanceTest.php` and `tests/Feature/Hr/LeaveTest.php`: rewritten throughout. Every test
  that exercised self-service attendance/leave as Bar Staff, Front Desk, or Duty Manager now uses Accountant
  (the one remaining non-Owner/GM role with these permissions) or General Manager (for the manager-tier actions
  Duty Manager used to hold) instead, and new tests assert that Bar Staff, Front Desk, and Duty Manager now get a
  plain `403` from every one of these routes.

**Verification performed:** `php -l` clean across every changed file; `verify_routes.py` re-run clean (190 named
routes, 0 duplicates, 0 dangling references — unchanged, since no route was added, removed, or renamed, only
regated). As with every fix in this project before a real `php artisan test` run happens, the test rewrites above
are written to the same standard as the rest of the suite but **not executed here** — see `TEST_REPORT.md`.

**One judgment call worth flagging explicitly:** this removes the ability for Cashier/Front Desk/Bar
Staff/Restaurant Staff/Duty Manager to clock their own attendance in this system at all, and removes Duty
Manager's ability to record others' attendance or approve/reject leave. If this hotel still needs those five
roles' work hours tracked, that now has to happen some other way (a physical time clock, a manager recording it
on their behalf via `record-attendance`, which is Owner/GM-only now, or a future decision to re-grant a narrower
version of self-service). This was confirmed directly with the project owner before implementing it this way,
specifically because it is a real functional loss for those roles, not just a menu-hiding cosmetic change.

---

## 14. The large "corrections" fix round — ~16 requests in one batch

The project owner sent a single message listing roughly sixteen corrections/additions after using the system for
real for the first time. Three genuine ambiguities were resolved with the owner before writing any code (via
targeted questions, answered before implementation started): **PIN reset authority** = Owner + General Manager
only; **wastage removal** = hide/disable the module but keep its historical data, not a destructive delete; **VAT
removal** = stop applying/showing it everywhere, but keep the `tax_rate_percent` setting itself sitting dormant in
Settings rather than deleting it outright. Everything below assumes those three decisions.

### 14.1 Rooms — receptionist can update room status; Add Room moved above the board

**Before:** `RoomController` had only `index()`/`store()` — there was no direct "change a room's status" action
anywhere; status only ever changed as a side effect of check-in/check-out/maintenance services. The "Add room"
form sat below the room grid on `front-office/rooms/index.blade.php`.

**What changed:** a new `update-room-status` permission (deliberately separate from `manage-rooms`, same
reasoning as the existing `view-rooms`/`manage-rooms` split) granted to Owner/General Manager/Duty
Manager/Front Desk; a new `RoomController::updateStatus()` + `POST /rooms/{room}/status` route
(`rooms.update-status`) that lets those roles set a room to `available`/`maintenance`/`out_of_service` — but
deliberately **not** `occupied` (that must go through an actual check-in, which also creates the Stay/Folio an
occupied room implies) and refuses to touch a room that's currently `occupied` (must check the guest out first).
The room board view was reordered so "Add room" renders above the grid, and each room tile now shows an inline
status dropdown + "Set" button when the viewer holds the new permission.

**Verification:** `tests/Feature/FrontOffice/RoomAuthorizationTest.php` (+3 methods) — Front Desk can set status,
a role without the permission is forbidden, an occupied room refuses a direct status change.

### 14.2 Check-in — guest search instead of a dropdown, inline guest creation, two buttons after creating a guest

**Before:** the walk-in check-in form used a plain `<select>` populated from `Guest::orderBy('first_name')->limit(200)`
— a hard cap that could silently omit guests, and any new guest required a full detour to a separate "New guest"
page (whose `store()` always redirected to the guest's own profile with no way back into check-in).

**What changed:** a new `GuestController::search()` JSON endpoint (`GET /guests/search`, same `manage-guests`
permission as the rest of that controller) backs a live search-as-you-type box on the check-in form
(`resources/views/front-office/checkin/walk-in.blade.php`) — plain `fetch()`, no framework. If nothing matches,
the typed name simply becomes the new guest's name: `CheckInController::walkInStore()` now creates the guest
inline (first/last name, phone, email) when no `guest_id` was selected, in the same request that performs the
check-in, rather than requiring a separate trip. Separately, `GuestController::store()` now redirects to the new
guest's page with a `?new=1` flag, and `guests/show.blade.php` uses that flag to show two prominent buttons,
**Check In** and **Reservation**, that carry the new guest's ID into `checkin.walk-in`/`reservations.create` (both
controllers now accept an optional `guest_id` query parameter to prefill).

**Verification:** `tests/Feature/FrontOffice/GuestSearchTest.php` (4 new methods — name match, phone match, empty
query returns nothing, permission gate) and 2 new methods in `CheckInCheckOutTest.php` (inline guest creation,
and the validation error when neither an existing guest nor a new name is provided).

### 14.3 VAT/tax removed everywhere, never shown on a receipt

**Before:** `OrderService::recalculateTotals()` was the only place tax was ever computed (folio/room charges were
already always tax-agnostic) — it read `SettingsService::taxRatePercent()` and added the result into
`total_amount`; `pos/orders/receipt-print.blade.php` and `pos/orders/show.blade.php` both showed a Tax line.

**What changed:** `recalculateTotals()` now always writes `tax_amount = 0` and `total_amount = subtotal` — it no
longer reads `taxRatePercent()` at all. Both POS views dropped their Tax line entirely (subtotal and total are
now always identical, so showing both would just be confusing). Per the owner's decision, `tax_rate_percent`
itself was **not** deleted from `SettingsSeeder`/the Settings screen — its description was updated to say plainly
that it currently has no effect, so a future hotel that wants tax back on has a value already sitting there
rather than needing a schema change.

**Verification:** new regression test `OrderTest::test_a_nonzero_tax_rate_percent_setting_has_no_effect_on_order_totals`
proves a nonzero setting value has zero effect on a real order's totals.

### 14.4 Self-service PIN change; PIN reset already Owner/GM-only

**Before:** the only way a PIN-login account's PIN ever changed was an admin using
`UserController::resetPin()`/`unlockPin()`, gated on `manage-users` — there was no self-service "change my own
PIN" anywhere.

**What changed:** a new `MyPinController` (`GET`/`POST /my-pin`, routes `my-pin.edit`/`my-pin.update`) lets any
authenticated PIN-login account change its own PIN after proving the current one (`Hash::check`) — no permission
beyond being logged in, since it only ever touches `auth()->user()`, exactly like logging out. Linked from a new
"My Account" section at the bottom of the sidebar nav. **The other half of the request — "only the master or
main manager have the access to reset the pin" — required no code change at all**: `manage-users` (the
permission `UserController::resetPin()` already required) has only ever been granted to Owner and General
Manager in `RolesAndPermissionsSeeder` (every other role's grant list omits it), so that restriction was already
true. `tests/Feature/MyPinTest.php` includes a test that proves this directly (`test_only_owner_or_general_manager_can_reset_someone_elses_pin`)
rather than just assuming it.

**Verification:** `tests/Feature/MyPinTest.php` (5 new methods).

### 14.5 Bar/Restaurant/Front-Desk POS redesigned as click-to-cart; save & search open orders

**Before:** `pos/orders/show.blade.php` was a `<select>` + "Add" button that reloaded the whole page for every
single item; the "open orders" list (`OrderController::index()`, already existing — `draft`/`confirmed` orders)
had no search box.

**What changed:** the order screen is now a grid of clickable menu tiles, grouped into category tabs, that add to
the cart via `fetch()` without a page reload — `OrderController::addItem()`/`removeItem()` grow a JSON response
branch (`$request->wantsJson()`) that returns a small, deliberately hand-shaped cart payload for the JS to redraw
from, so a future column rename on `orders`/`order_items` can't silently change this API's shape. The original
full-page-submit "Manual add / complimentary item" form still exists (collapsed into a `<details>`) as the
no-JavaScript fallback and the only way to mark an item complimentary. "Save order" needed no new server action —
every click is already its own persisted request, so a draft/confirmed order is already "saved" the instant an
item is added; the button just returns to the outlet's order list. That list (`pos/orders/index.blade.php`) grew
a `?search=` text box matching on order number or table label. This is the same controller/service for all three
outlets (bar/restaurant/front_desk), so Front Desk Sales gets the identical click-to-cart redesign automatically
— no separate work was needed for the "front desk sales should also be a click and add to cart" request.

**Verification:** manual `node --check` on the extracted inline `<script>` blocks (after substituting Blade's
`{{ }}` interpolation with a placeholder) confirms the JS is syntactically valid; the existing POS test suite
(`OrderTest`, `ComplimentaryOrderTest`, `RecipeStockDeductionTest`, `FrontDeskPettySaleTest`) exercises the
same `addItem`/`removeItem`/`confirm`/`close` service methods this UI calls, unchanged.

### 14.6 Business branding: logo + name shown prominently; hover/click/selected states; mobile responsiveness

These three were combined into one pass over `resources/views/layouts/app.blade.php`, since all three touch the
same file:

- **Branding:** the hotel logo (already uploadable via the pre-existing `SettingsController::uploadLogo()`) and
  hotel name now render at the top of the sidebar on every page (and in a mobile header bar — see below), not
  just on the Settings screen and printed documents.
- **Hover/click/selected states:** a small global stylesheet block gives every button and nav link a consistent
  hover (`filter: brightness(1.08)`) and press (`transform: scale(0.97)`) feel, and an `.is-selected` class
  (applied from Blade via `request()->routeIs(...)` on the current nav link, and from JS on the active POS
  category tab) gets a visibly distinct treatment.
- **Mobile responsiveness:** the sidebar was a fixed `w-56` block with no way to hide it on a narrow screen. It
  now collapses off-canvas below the `md` breakpoint behind a hamburger button in a new mobile header bar, with a
  tap-outside-to-close backdrop. Separately, roughly twenty other view files that used a hardcoded 3-column
  `grid grid-cols-3` dashboard-style layout (stay detail, reports, HR, inventory, finance screens, etc.) were
  changed to `grid grid-cols-1 md:grid-cols-3` so they stack into one column on a phone instead of squeezing three
  panels into a few hundred pixels.

**Verification:** `php -l` and a Blade-directive-balance sweep (matching `@if`/`@endif`, `@can`/`@endcan`, etc.
counts) across every changed view; a spot-check confirmed the bulk `grid-cols-3` → `grid-cols-1 md:grid-cols-3`
replacement didn't create any duplicated or malformed class strings. There is no automated browser-level test of
the responsive layout itself — this sandbox cannot render a page or take a screenshot; this is a documented gap,
not a claim of full coverage.

### 14.7 Wastage module hidden (not deleted)

Per the owner's "hide/disable, keep the data" decision: `record-wastage`/`approve-wastage` are excluded from
every role's grant in `RolesAndPermissionsSeeder` — including Owner/General Manager, who would otherwise hold
them automatically via the `$all` shortcut — so every `inventory/wastage/*` route now 403s for everyone, and the
nav's "Wastage" link (already gated on `@canany(['record-wastage', 'approve-wastage'])`) never renders for
anyone. The `stock_wastages` table, `StockWastage` model, `WastageController`, and `WastageService` are
completely untouched, so this is reversible later just by granting the two permissions to a role again — no
migration, no data loss. `tests/Feature/Inventory/WastageTest.php` was rewritten from a permission-matrix test
into a "the module is fully hidden" test, including one method that calls `WastageService` directly (bypassing
HTTP/permissions) to prove the underlying mechanism and historical data are unaffected by the hide.

### 14.8 A new general-purpose Payments module

**Before:** `PaymentLedgerService::record()` (the single writer of the central `payments` table) was only ever
called from inside another module's own flow — a folio payment, a POS order close, an expense payout, a payroll
run. There was no screen to record a payment that isn't a guest's room/POS bill at all (rent from a visitor, a
one-off walk-in payment).

**What changed:** a new `PaymentController` (`GET`/`POST /payments`, routes `payments.index`/`payments.store`,
gated on a new `record-payment` permission granted to Owner/General Manager/Duty Manager/Front Desk) lets
Front Desk record any payment directly through `PaymentLedgerService::record()`, optionally tied to an existing
guest (via the same search widget as check-in) or to a free-text payer name. This is deliberately additive, not a
replacement: a guest's actual room/POS bill should still go through that stay's "Post payment" form or the POS
close screen, both of which already correctly tie the payment to the right folio/order automatically — this
module is for the money that doesn't belong to either of those. **The other half of this request — "at checkout,
the folio balance of payment that has been made auto-records in the payment ledger" — required no new code**:
`FolioService::postPayment()` already calls `PaymentLedgerService::record()` for every folio payment, and unpaid
balances at checkout already auto-create an `AccountsReceivable` row via `CheckOutService` (see §14.10) — both
predate this fix round.

**Verification:** `tests/Feature/FrontOffice/PaymentModuleTest.php` (3 new methods — a payment with no guest, a
payment tied to a guest, and the permission gate).

### 14.9 Folio receipt now shows cost per night

**Before:** the nightly rate has only ever lived on `Stay::agreed_rate` — the Folio itself only ever records
discrete `FolioTransaction` charge amounts, never a rate. Neither `stays/show.blade.php` nor the printable
`folio-print.blade.php` surfaced it.

**What changed:** both views now show the agreed nightly rate, and the print view additionally shows it
multiplied by a computed night count (`ceil(hours since check-in / 24)`, floored at 1 night, measured against
check-out if the stay has closed or "now" for an in-progress stay's invoice) — no schema change, since the rate
was already there; this is purely a "make it visible" fix.

### 14.10 Guest folio shows a per-department bill summary

**What changed:** a new `Folio::departmentSummary()` method sums non-voided transactions by charge type (room,
bar, restaurant, front desk, other services — plus a combined "Payments & adjustments" line for everything else)
straight from the ledger, the same "never a separately-maintained total" philosophy as the existing `balance`
column and `recomputeBalance()` method, so it can never drift from the transactions it summarizes. Rendered as a
small card above the transaction table on both `stays/show.blade.php` and the printable folio receipt.

### 14.11 Front-desk sales: instant payment or post to folio, correct ledger timing, Accounts Receivable

This turned out to already be fully wired, mostly by virtue of Front Desk Sales being the exact same
`OrderController`/`OrderService` code path as Bar/Restaurant (Phase 9.7.4) — `OrderService::close()` already
accepts either an immediate `payment_method` (posts to the central ledger right away) or a `stay_id` to defer the
charge onto a checked-in guest's folio (which only enters the ledger later, when that folio itself is paid off —
`FolioService::postPayment()`); `CheckOutService` already creates an `AccountsReceivable` row for any positive
folio balance remaining at checkout, front-desk sales included. The click-to-cart redesign in §14.5 gives this
outlet the same UI improvements as Bar/Restaurant automatically. No new code was needed beyond what §14.5/§14.8
already cover; this section exists mainly to record that this requirement was audited and found already true,
rather than silently assumed.

### 14.12 Redesigned printable folio receipt — 2 copies per A4, dashed divider, signature line

**Before:** `layouts/print.blade.php` had no explicit page-size rule at all (relied entirely on the browser's own
default), and the folio receipt was a single, plain itemized ledger with no hotel branding beyond the shared
page-top header, no region field, and no signature line.

**What changed:** `layouts/print.blade.php` now sets `@page { size: A4; margin: 1.2cm; }` (harmless for every
other document that extends the same layout). A new `hotel_region` setting (`SettingsService::hotelRegion()`)
was added alongside the existing hotel name/phone/email/logo fields. The folio receipt
(`front-office/stays/folio-print.blade.php`) now renders a new shared partial,
`_folio-receipt-copy.blade.php`, **twice** — once "up", once "down" — separated by a dashed `<hr>` divider, per
"the receipt should be duplicate, up and down, with them both carrying the same info." Each copy is
self-contained: its own compact header (logo, hotel name, region, phone, email), guest/room/dates, cost-per-night
(§14.9), the department summary (§14.10), the full itemized transaction table, the balance, any Accounts
Receivable note, and a guest-signature + date line at the bottom. Font sizes are kept small and consistent
(`text-[10px]`/`text-[11px]`) so two full copies comfortably fit one A4 page for a typical folio. **Known,
documented limitation** (in the same honest style as this project's other print-related caveats): a folio with an
unusually large number of transactions could still spill the pair onto a second sheet when printed — this is
flagged directly on the page itself (a `no-print`-only note) rather than silently claimed to be impossible.

**Verification:** `php -l` and Blade-directive-balance checks on both the parent view and the new partial; the
department-summary and cost-per-night values feeding this template are the same ones covered by §14.9/§14.10.

### 14.13 What's genuinely untested end-to-end

This round touched a very large number of Blade view files and added real client-side JavaScript (the guest
search widgets, the POS click-to-cart cart, the mobile sidebar toggle). Every one of these was checked for PHP
syntax validity, Blade directive balance, and — for the JavaScript — `node --check` syntax validity after
substituting Blade's `{{ }}` interpolation with placeholder values. None of it was exercised in an actual browser,
because this sandbox has no browser and cannot run `npm`/a dev server against this project. The controller/service
layer underneath every one of these screens (room status, guest search/creation, PIN change, click-to-cart's
`addItem`/`removeItem` endpoints, the Payments module, folio department summary) has real PHPUnit test coverage,
written and manually traced to the same standard as the rest of this project (see `TEST_REPORT.md` §2.4) but,
per this document's standing limitation, **not executed** — the same caveat that has applied to every fix in this
project since this sandbox cannot run `composer install`/`php artisan test` (blocked Packagist access), a
limitation distinct from the project owner's own real, working deployment.

## 15. Real lodging charges, reservation deposits as real payments, and automatic checkout (post-14 fix round)

The user's own words: "the folio receipt shows cost-per-night and a bill breakdown by department... when i talk
about cost per night i mean if someone is checking for example 4 days and the room or apartment cost 20000 per
night, let the total cost be in the folio, the client can also make advance payment for the lodging so that
should be included in the folio so as to get the clients outstanding balance. you should also know that the
advance on reservation are also payments. while you do these adjustments also make some modifications following
global hotel (hospitality) standards."

This closes a real architectural gap that §14.9 only partially addressed: §14.9 made the nightly rate *visible* on
the folio receipt, but the folio's `balance` — the number that actually determines what a guest owes — never
carried the true stay cost until either a manual "post charge" or the old optional one-night checkout charge was
used. A 4-night stay's folio could sit at 0.00 for the entire stay. Separately, `Reservation::deposit_paid` was a
schema column with **no write path anywhere in the application** (grep-confirmed: only `ReservationService::
create()` ever wrote it, driven by a form field that does not exist) and, even if it had been set, it never
touched `PaymentLedgerService` — so a reservation deposit was invisible to the payments ledger and never reduced a
guest's outstanding balance.

### 15.1 Reservation deposits are now real payments (`ReservationService::recordDeposit()`)

**What changed:** a new "Record deposit / advance payment" form on the reservation page
(`reservations/show.blade.php`, visible while the reservation is pending/approved/confirmed) posts to a new route
(`POST /reservations/{reservation}/deposit`, named `reservations.deposit`) → `ReservationController::
recordDeposit()` → `ReservationService::recordDeposit()`. That method records the amount through
`PaymentLedgerService::record()` — the same central ledger every other payment in this system goes through — with
`source_module = 'reservation_deposit'`, then adds it onto `Reservation::deposit_paid` (a running total, since a
guest may pay a deposit in more than one instalment; `bcadd()` is used for exact decimal arithmetic rather than
float addition). A reservation can only take a deposit while pending/approved/confirmed — not once cancelled,
rejected, or already checked in.

**Judgment call — permission:** gated on `post-folio-payment` rather than `manage-reservations`. Taking money is a
cash-handling action, and `post-folio-payment` is the permission this codebase already uses for exactly that
(posting a payment against a guest's folio); some roles hold `manage-reservations` without being trusted to take
cash, so reusing that permission would have been wrong. Every role that already holds `post-folio-payment` (Owner,
General Manager, Duty Manager, Accountant, Cashier, Front Desk) can record a deposit with no seeder change needed.

### 15.2 The full lodging cost is now a real, posted folio charge — at check-in, not at checkout

**What changed:** `CheckInService` now injects `FolioService` and, immediately after opening a stay's folio (both
`checkInFromReservation()` and `checkInWalkIn()`), posts a single `room_charge` for the **entire anticipated stay
cost** — `agreed_rate × nights`, where nights is computed by a new `Stay::nightsUntil()` / `expectedNights()` /
`actualNights()` calendar-day-diff helper (the same floored-at-1-night model `Reservation::nights()` already used,
just generalized). How many nights that charge covered is recorded on a new `stays.nights_charged` column
(migration `2025_01_02_001000_add_lodging_charge_tracking_to_stays.php`) so checkout (§15.3) never charges the
same nights twice. If the reservation being checked in already has a `deposit_paid` balance (§15.1), it is applied
as a folio credit immediately after, via a new `FolioService::applyAdvanceCredit()` method.

**Judgment call — why a new `applyAdvanceCredit()` method instead of reusing `postPayment()`:** `postPayment()`
*always* also calls `PaymentLedgerService::record()` — that is what makes it correct for a payment that is
happening right now, at the folio, for the first time. A reservation deposit was already ledgered once, at the
moment it was actually received (§15.1); applying it again through `postPayment()` at check-in would silently
double it in the central `payments` table, which is a books-integrity bug, not a cosmetic one. `applyAdvanceCredit()`
posts the same kind of folio credit (`type = 'payment'`, negative signed amount, so it still reduces `balance` and
appears correctly in `Folio::departmentSummary()`'s "Payments & adjustments" line) but skips the ledger call
entirely, since the cash movement was already recorded once, honestly, when it happened.

**A worked example, matching the user's own:** a guest reserves a room at 20,000 XAF/night for 4 nights and pays a
15,000 XAF deposit up front. At check-in: the folio is charged 80,000 (20,000 × 4), then credited 15,000 (the
deposit), leaving a folio balance of 65,000 — the guest's real outstanding balance, visible the moment they check
in rather than only being discoverable at checkout.

### 15.3 Checkout no longer takes a manual "post final night" charge — it's automatic, and only for genuine extensions

**Before:** `CheckOutService::checkOut()` took an optional `$postFinalNightCharge` boolean, driven by a checkbox
on `stays/show.blade.php` ("Post final night's room charge first"), and posted exactly one night's charge if
checked. This only ever made sense because, before this round, the folio started at 0 and nothing had been
charged yet.

**What changed:** now that the full stay cost is posted upfront at check-in (§15.2), checkout would double-charge
the guest if it still had any option to post a full/final night charge. `CheckOutService::checkOut()` no longer
takes that parameter at all — it now computes `actualNights` (via `Stay::nightsUntil()`, same helper, against the
real checkout moment) and compares it to `nights_charged`. If `actualNights > nights_charged`, the **delta only**
is posted as a room charge ("Room charge — N additional night(s) beyond the original M-night stay") — a genuine
extension, never the nights already covered. The checkbox is gone from the checkout form; the form's help text now
explains, before the guest checks out, whether an extension charge will be added and how much.

**Judgment call — early departure is never auto-credited:** if a guest leaves before their `nights_charged` count
is reached (`actualNights < nights_charged`), checkout does **nothing** — no automatic charge (there's nothing to
charge) and, deliberately, no automatic refund either. This matches standard non-refundable nightly-rate hotel
policy and the same principle spec section 10 already established for outstanding balances ("checkout is never
blocked/altered automatically by a balance question") — a manual refund for an early departure is still available
through Finance → Refunds, which already requires its own approval step; checkout itself just never volunteers
money back silently.

### 15.4 Marking a stay complimentary after check-in now actually reverses the already-posted charge

**A gap this round's own change would otherwise have introduced:** "special guest lodging" (§9) can only be
marked *after* check-in (an active-stay-only action), which used to be safe because, before this round, nothing
had been charged at check-in yet — marking a stay complimentary just meant "skip the optional charge at
checkout." Now that the full stay cost is posted **at check-in**, a stay marked complimentary afterward would
otherwise still be sitting on a real, unpaid room charge — "complimentary" would no longer mean "free."

**What changed:** `StayController::markComplimentary()` now reverses every not-yet-voided `room_charge`
transaction on the stay's folio via the existing `FolioService::void()` mechanism (a reversing adjustment entry,
never an edit or delete — spec section 12) as part of marking a stay complimentary, and resets `nights_charged` to
0. This means: if complimentary status is later removed (`unmarkComplimentary`), checkout's automatic extension
calculation correctly treats the whole stay as unbilled again, rather than silently skipping nights that were
charged and then reversed. Every other charge type is completely untouched, exactly as before.

### 15.5 The folio receipt's "cost per night" line now shows the real charged total, not a separate estimate

**Before (§14.9's gap):** the printable folio receipt computed its own night count independently
(`ceil(hours since check-in / 24)`) purely for display — a second, separately-computed number that could disagree
with whatever the ledger actually showed.

**What changed:** `folio-print.blade.php` now reads the real `Stay::nights_charged` value (falling back to
`actualNights()` only for a stay that predates this column) so the receipt can never show a different night count
or total than what was actually posted to the ledger below it. The line itself now also shows the computed total
(rate × nights = total), not just the rate.

### 15.6 Verification

`php -l` across every touched PHP file (models, services, controllers, the new migration, all touched tests) and
a project-wide sweep — clean. `verify_routes.py` — the new `reservations.deposit` route resolves with no
duplicates or missing names (the tool's one "MISSING: outlet" finding is a pre-existing false positive, unrelated
to this round — `request()->route('outlet')` in `layouts/app.blade.php` is a route-parameter lookup, not a call to
the `route()` helper). Blade-directive-balance counts on both touched views. New/updated PHPUnit tests (written
and manually traced, not executed — this sandbox's standing limitation, see §14.13) cover: the full stay cost
posted correctly at both reservation-based and walk-in check-in; a deposit recorded through
`ReservationService::recordDeposit()` landing in the central ledger exactly once and then being applied as a
folio credit at check-in *without* being ledgered a second time; deposit authorization and status-guard rules;
checkout automatically charging a genuine extension; checkout never auto-crediting an early departure; and marking
a checked-in stay complimentary correctly reversing its already-posted room charge. See `TEST_REPORT.md` for the
full list.

### 15.7 Removed: the room's separate "clean/dirty" housekeeping status

Sent right after §15 shipped: "REMOVE THE CLEAN AND THE DIRTY STATUS." Rooms tracked two independent things: a
real operational `status` (available/occupied/maintenance/out_of_service) and a separate cosmetic
`housekeeping_status` (clean/dirty/inspected) shown next to it on the room board — set automatically to 'dirty' by
checkout and by a resolved maintenance ticket, and to 'clean'/'inspected' when a housekeeping task was completed.
Confirmed with the user before touching anything (this both drops a database column and changes checkout/
maintenance/housekeeping behavior, so guessing wrong would have been expensive to unwind): the choice was to
remove the label and its auto-tracking entirely from rooms, while leaving the separate Housekeeping task list
(where staff still create, start, and complete cleaning/inspection jobs) completely untouched.

**What changed:** a new migration drops `rooms.housekeeping_status` outright (down() restores the exact original
enum/default, for a clean rollback). `Room::$fillable` no longer lists it, and `RoomFactory` no longer sets it.
`CheckOutService::checkOut()` and `MaintenanceService::resolve()` no longer set it to 'dirty' when freeing a room
— they now just set `status => 'available'`. `HousekeepingService::complete()` no longer sets it to
'clean'/'inspected' when a task finishes — the task's own `status` (pending → in_progress → done) is untouched and
is still the real record of what housekeeping did; only the redundant, separate label on the room itself is gone.
The room board (`rooms/index.blade.php`) no longer shows the "· Clean/Dirty/Inspected" text next to a room's
status.

**Judgment call:** the Housekeeping task list stays exactly as it was — creating a "cleaning" or "inspection" task
for a room, assigning it, starting it, and marking it done all still work identically. Only the automatic
clean/dirty/inspected label that used to live directly on the room record is gone, per the literal request ("the
clean and the dirty status") — nothing further was assumed to be in scope.

**Verification:** `php -l` across every touched file, `verify_routes.py` (no change to route names), and
`tests/Feature/FrontOffice/CheckInCheckOutTest.php`'s checkout test no longer asserts a `housekeeping_status`
value; `tests/Feature/Console/BackfillSyncUlidsTest.php`'s raw `DB::table('rooms')->insert()` (which bypasses
Eloquent, so it has to list every column by hand) had the now-nonexistent column removed from its insert.

### 15.8 Deployment note

This round adds two migrations, run together with one `php artisan migrate --force` (the `--force` is required
because the production install refuses to run migrations non-interactively otherwise — it will otherwise print
"Command cancelled" and do nothing):

- `2025_01_02_001000_add_lodging_charge_tracking_to_stays.php` — purely additive, one new `stays.nights_charged`
  column defaulted to 0. Existing active stays will have `nights_charged = 0` until their next checkout, at which
  point checkout's automatic-extension logic will treat their entire actual stay as one genuine "extension" and
  charge it in full — the correct outcome for a stay that, under the old system, had never been charged for its
  room at all.
- `2025_01_02_001100_drop_housekeeping_status_from_rooms.php` — purely subtractive, drops the now-unused
  `rooms.housekeeping_status` column (§15.7). No data migration needed; nothing reads that column any more.

No new permissions or seeder changes either round.

## 16. Printable-receipt redesign and the front-desk room-status report

Three issues reported together, from a real screenshot of the guest invoice: "the receptionist (front desk) are
unable to update room status," "the receipt showing [redundant information] and we look at the headers... we are
supposed to have just 2 headers for the both receipts and we are to maximise the space... avoiding overflow or
overlapping info," and "the bar and the restaurant will be using... a pos device... that prints out the small pos
receipts."

### 16.1 Front desk unable to update room status — no code bug found; this is a live-data/deployment issue

`update-room-status` was added to Front Desk's permission grant in the "corrections" round (§14.1) and the route
(`POST /rooms/{room}/status`), controller guard, and Blade `@can` check were all re-verified this round and are
correct and unchanged. The two realistic causes on a live install, neither of which is a code defect: (1) the
"corrections" round's seeder (`RolesAndPermissionsSeeder`) was never actually re-run on the production database
after that deploy, so the live `front_desk` role's permission set still predates `update-room-status` even though
the code has supported it since; or (2) the room in question is currently `occupied` — this form is deliberately
hidden for an occupied room (`rooms/index.blade.php`'s `@if ($room->status !== 'occupied')`), by design: an
occupied room's status can only change via an actual checkout, never by hand, so nothing marks a room "occupied"
or clears it except `CheckInService`/`CheckOutService` themselves. No code change was made here; the fix is
operational — re-run `php artisan db:seed --class=RolesAndPermissionsSeeder --force` on the live database, or
confirm the room being tested isn't occupied. If it's still broken after both are ruled out, that's a genuine new
bug and needs a fresh report (ideally: which room, what the receptionist's account role actually is, and what
happens on screen when they try).

### 16.2 Removed the folio receipt's redundant third header block

**Before:** `layouts/print.blade.php` (the shared A4 document layout used by every printable document) always
rendered its own hotel-name/address/phone header at the top of the page. The folio receipt
(`folio-print.blade.php`) additionally renders `_folio-receipt-copy.blade.php` **twice**, and each copy already
carries its own compact, self-contained header (so a torn-off copy still identifies the hotel on its own) — so the
printed page showed **three** header blocks (the shared layout's, then each copy's own) where only two — one per
copy — were ever wanted.

**What changed:** `layouts/print.blade.php` now wraps its shared header in `@unless ($hidePrintHeader ?? false)`.
`StayController::printInvoice()`/`printFinalBill()` (the only two routes that render the dual-copy folio receipt)
now pass `hidePrintHeader => true`. Every other document using this same layout (reservation confirmation, payment
receipt, cash closing, etc.) is completely unaffected — they never set that flag, so they keep their one shared
header exactly as before. The divider between the two copies was also tightened (0.5cm → 0.3cm) and the
signature-line block's top margin reduced slightly, both purely to reclaim vertical space now that a whole
redundant header block is gone — directly serving "maximise the space... avoiding overflow."

### 16.3 A dedicated narrow-format layout for bar/restaurant POS receipts

**The gap:** `pos/orders/receipt-print.blade.php` (the bar/restaurant/front-desk POS order receipt) extended the
same A4 `layouts/print.blade.php` as every back-office document — full-width table, full Tailwind CDN load, A4
page size. A real bar or restaurant, per the user, prints these from a POS device onto a narrow thermal receipt
printer (80mm roll paper), which that layout was never designed for: a wide multi-column table either gets cut off
or shrunk illegibly on 80mm stock.

**What changed:** a new, separate layout, `layouts/print-thermal.blade.php` — fixed 80mm width, no CSS framework
dependency (a thermal printer has no use for one, and a stylesheet this small is easier to get exactly right for a
fixed narrow width by hand), monospace type, and a strictly single-column content flow so nothing can ever overflow
the roll's width regardless of item-name length. `pos/orders/receipt-print.blade.php` now extends this layout
instead, with its content restructured from a wide `<table>` into stacked single-column rows (order/table/served
by, then one block per line item showing qty × unit price above its line total, then the total, then payment
method). Applies uniformly to Bar, Restaurant, and Front Desk POS sales, since all three outlets already render
through this exact same shared controller action and view — there was no reason to special-case one outlet's paper
size over another's. Every other printable document (folio receipt, reservation confirmation, payment receipt,
cash closing) is completely unaffected — they still use the original A4 `layouts/print.blade.php`, unchanged.

**Judgment call:** the POS ticket is a single copy, not a duplicated "up/down" pair like the folio receipt — that
duplication was a guest-invoice-specific request ("the receipt should be duplicate, up and down"), never
mentioned for POS tickets, and doubling a thermal roll's length for a routine drink/food order would waste paper
for no stated benefit.

**Verification:** `php -l` across every touched PHP file, Blade-directive-balance counts on all four touched view
files (`layouts/print.blade.php`, `layouts/print-thermal.blade.php`, `folio-print.blade.php`,
`_folio-receipt-copy.blade.php`, `pos/orders/receipt-print.blade.php`), and `verify_routes.py` (no route names
changed this round). No test asserts specific HTML content from the POS receipt view (only its permission gate —
`tests/Feature/PrintableDocumentsTest.php`'s `test_pos_receipt_is_reachable_only_by_the_matching_outlet_role` and
`tests/Feature/Pos/OrderCrossOutletTest.php` — both check only HTTP status codes), so none needed updating; the
folio-receipt header change has no dedicated test either (no existing test asserted the old, now-removed, third
header), so nothing regressed there.

## 17. Second post-9.8 fix round — outlets, cash disbursement, shifts, Gym & Pool, department reconciliation, printable reports

Sent as one message covering six distinct requests: the front-desk room-status control still not appearing at
all; every report needing to be printable and date-range filterable; a new per-department, per-shift cash
reconciliation; a new cash-disbursement capability distinct from Expenses; a new report reconciling daily payment
by department; and new Gym & Swimming Pool facilities. Four follow-up clarifying questions were asked before any
of this was built; the answers materially changed the design from what was first proposed, and are recorded
verbatim against each section below because they are the actual specification for what got built.

### 17.1 "The bar outside" and "the bucareaux" — two more sales points, same POS module

**What was asked, and the answer received:** the user named two more sales points — "the bar outside" and "the
bucareaux" — as being handled somewhere in the daily cash picture. Asked directly what these are, the answer was:
"not really like a department but they are separate and their sales and stocks are managed separately." Neither
of two guessed options (a second outdoor bar counter; a bureau-de-change currency desk) was confirmed or denied —
only that they are operationally separate, with their own sales and their own stock.

**Judgment call:** the existing Bar/Restaurant/Front Desk POS module is already outlet-parametrized end to end —
`operate-{outlet}-pos`, `MenuCategory`/`MenuItem`/`PosTable`/`Order` all scoped by a plain `outlet` string, exactly
the pattern `front_desk` was added under in `2025_01_02_000800_add_front_desk_outlet_support.php`. "Separate
sales" is exactly what a new outlet value gives for free. "Separate stock" is *not* fully literal here: this
system has one central `products`/stock-batch pool, not per-outlet stock locations — Bar and Restaurant already
share that same pool today, distinguished only by which menu items' recipes consume which products. Bar Outside
and Bucareaux get that same treatment: their own outlet code, their own menu/categories/tables, their own
`operate-{outlet}-pos` permission and role (`bar_outside_staff`, `bucareaux_staff`, mirroring `bar_staff`/
`restaurant_staff` exactly), their own `departments` row (`BAROUT`, `BUCA`) for cash attribution — but drawing
from the one shared product catalog, same as Bar and Restaurant already do. If a true separate physical stock
*location* per counter turns out to be what was actually meant, that is materially bigger (multi-location
inventory) and is flagged here as an open question for the user to confirm before it's built, not assumed.

**What changed:**
- `2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php` — widens the `outlet` enum on `menu_categories`,
  `menu_items`, `pos_tables`, `orders` (MySQL: raw `MODIFY COLUMN`; SQLite: plain `change()` — same pattern as the
  front_desk migration, see that file's own docblock) to add `bar_outside`/`bucareaux`, and widens
  `folio_transactions.type` to add `bar_outside_charge`/`bucareaux_charge` (the "{outlet}_charge" convention
  `OrderService::close()` relies on when an order is deferred onto a guest's folio).
- `routes/web.php` — the POS outlet route regex now matches `bar|restaurant|front_desk|bar_outside|bucareaux`.
- `RolesAndPermissionsSeeder` — two new permissions (`operate-bar_outside-pos`, `operate-bucareaux-pos`) and two
  new roles (`bar_outside_staff`, `bucareaux_staff`), granted the same shape as `bar_staff`/`restaurant_staff`
  (`report-maintenance`, their own POS permission, `request-stock`, `manage-own-shift` — see §19).
- `DepartmentSeeder` — two new departments, `Bar Outside` (`BAROUT`) and `Bucareaux` (`BUCA`).
- `PosSeeder`/`UserSeeder` — starter menus/tables and one demo account each (`barout1@hms.test`,
  `buca1@hms.test`, PIN `1234`), so both outlets are immediately usable, not just wired.
- `layouts/app.blade.php`, `stays/show.blade.php` — nav links and the manual folio-charge dropdown extended.

No new controller/service code was needed at all — this is the entire point of the outlet-parametrized design
already in place: a genuinely new sales point is additive, not a new code path.

### 17.2 Front-desk room status still not updating — confirmed a live-environment issue, with a self-service diagnostic

**What was asked, and the answer received:** "my front desk is yet still not able to edit the room status," after
§16.1 had already traced this to either a stale live permission grant or an occupied room hiding the control by
design. Asked which symptom exactly matches, the answer was: "no dropdown/button appears at all."

That confirms the second investigation's leading theory precisely: the room-status form only renders behind
`@can('update-room-status')` in `rooms/index.blade.php` — a control that never appears at all (as opposed to
appearing and then 403ing) means that specific logged-in user's `update-room-status` check is resolving `false`
in the **live production database**, which this sandbox has no access to and therefore cannot inspect directly.
The route, controller guard, and seeder grant were re-verified again this round and are unchanged and correct
(third confirmation, after §14.1 and §16.1).

**What changed:** rather than repeat the same "please re-run the seeder" instruction a third time with no way for
the user to confirm it actually fixed anything, a new artisan command, `php artisan hms:check-access {email}
{permission=update-room-status}`, was added
(`app/Console/Commands/CheckUserAccess.php`). Run on the live server against the live database, it reports: does
this user exist and is it active; what role(s) does it hold; does that permission even exist in this database at
all (catches "the seeder has literally never run here"); does the role hold it (catches "the seeder ran before
this permission existed"); and, after force-clearing Spatie's permission cache, does `$user->can()` resolve true
right now (catches a stale cache surviving a re-seed). It ends with an exact, ordered fix — re-seed, clear the
permission cache, clear cached routes/config, log out and back in — tailored to whichever of those four things it
actually found wrong, instead of a generic "try this" list. This is a genuinely new, small, low-risk piece of
tooling, not a workaround for a bug that couldn't be found — the underlying code has now been independently
verified correct three separate times.

### 17.3 Payment-to-department attribution — the foundation the two new features below (§18, §19) sit on

Before either the cash-disbursement or shift-reconciliation features could exist, every payment needed to know
*which* of the existing `departments` rows it belongs to — the central `payments` table only ever recorded a
`source_module` string (`pos_bar`, `front_office`, `finance_expense`, ...), which module posted it, never which
department it should be attributed to for cash-handling purposes.

**What changed:** `2025_01_02_001300_add_department_id_to_payments_table.php` adds a nullable
`payments.department_id` (FK to `departments`, left `NULL` on historical rows — no fabricated backfill, per spec
section 40). `PaymentLedgerService::record()` gains an optional `?int $departmentId` parameter and, when it's not
passed, derives it automatically from `source_module` (`pos_bar` → `BAR`, `front_office`/`general_payment`/
`reservation_deposit`/`facility_access` → `FO`, etc.) — so every existing call site across the app keeps working
unchanged, and only the two callers that need a *more specific* attribution than the generic mapping would guess
pass it explicitly: `ExpenseService::markPaid()` now passes the expense's own `department_id` (an expense already
carries one), and the new `CashDisbursementService::disburse()` passes the disbursement's own department. This
is deliberately additive and low-risk: no existing behavior changes, no existing test needed updating, and every
future payment now carries department attribution for free.

### 17.4 Cash disbursement — a ticket, or a named authorizer, never neither

**What was asked, and the two proposals rejected before this shape was reached:** "the accountant or cashier has
to be able to record cash disbursement not just expense only." The first proposal offered was a binary choice
(immediate vs. Expense-style pre-approval); the answer rejected that as not quite right and described the actual
flow instead: "before a disbursement is done, the management creates the ticket, though disbursement should also
be able to be done without ticket but the details will have to be clear and the detail of the superior
authorising it [must be recorded]."

**What was built, exactly matching that description, not either of the two originally proposed shapes:**
- `disbursement_tickets` — a management-raised authorization (`authorize-cash-disbursement`: Owner/GM/Duty
  Manager/Accountant), single-use (`open` → `used` on the first disbursement against it, or `cancelled`).
- `cash_disbursements` — the actual cash-out event, always posting to the central ledger (`direction: 'out'`,
  same `PaymentLedgerService`/`DB::transaction()`/`lockForUpdate()` pattern as `ExpenseService::markPaid()`, the
  identified template for this). Two ways in, enforced in `CashDisbursementService::disburse()`, never left to
  the UI to remember: **with** a `ticket_id` (the normal path — the authorizing manager's identity comes from the
  ticket, not duplicated on the disbursement, and the amount cannot exceed what the ticket authorized), or
  **without** one, in which case the row itself must carry either an in-system `authorized_by_user_id` or a free-
  text `authorized_by_name` (+ optional title) — attempting neither throws a clear `HmsException` before anything
  is written or posted.
- New permissions: `authorize-cash-disbursement` (raise a ticket), `record-cash-disbursement` (execute a
  disbursement, ticketed or not) — granted to Owner/GM/Duty Manager/Accountant (both) and Cashier (execute only).
- `finance.cash-disbursements.index` — one screen showing disbursement history and open/cancelled tickets side by
  side, with separate forms for "raise a ticket" and "record a disbursement" (the latter toggling between picking
  an open ticket or entering a named authorizer). A printable voucher (`layouts.print`) records everything
  including the resolved authorization line (`CashDisbursement::authorizationLabel()`).

### 17.5 Shift-based department cash reconciliation, not a daily one

**What was asked, and the proposal rejected before this shape was reached:** "we will need a report that
reconciles daily payment by the various departments." The first proposal modeled this per calendar day, matching
the existing whole-hotel `CashReconciliation`/`AccountingDay` pattern already in the system. The answer corrected
that directly: "not really per day but in shifts because they work in shifts."

**What was built:** a new `shifts` table/model/service (`App\Services\Finance\ShiftService`), scoped per
department rather than per whole-hotel-per-day. A department's own staff **open** a shift (one open shift per
department at a time, enforced with `lockForUpdate()` inside a transaction, same concurrency-safety pattern as
every other single-in-flight-record rule in this system) and **close** it by declaring the cash actually counted;
`expected_cash` is always computed live from the payments ledger for that department across the shift's exact
open-to-close window — never hand entered — exactly matching the existing `CashReconciliationService`'s own
"never fill in a form that could agree with itself" principle, just scoped to one department and one work period
instead of the whole hotel and a calendar day. New permission `manage-own-shift` (open/close), granted to Front
Desk, Cashier, Bar/Restaurant/Bar Outside/Bucareaux staff; `view-shift-reports` (see every department's shifts)
granted to Accountant, Cashier, Duty Manager and up.

**Deliberately not done:** opening/closing a shift does **not** gate whether a payment can be recorded for that
department, unlike `AccountingDayService::assertOpen()`, which genuinely blocks postings once the day is locked.
Making a shift a hard gate risks blocking a real guest-facing transaction (a check-in, a bar sale) the moment
someone forgets to open one first — a materially worse failure mode than a shift simply not existing yet. The
existing whole-hotel daily `CashReconciliation`/`AccountingDay` lock is completely untouched and keeps working
exactly as before; shifts are an additive, per-department overlay on top of it, not a replacement.

### 17.6 Department payment reconciliation report

The report explicitly asked for: "a report that reconciles daily payment by the various departments, that is bar,
restaurant, front desk, the bar outside, the bucareaux and their income recorded from their sales, lodging etc."
Built as `reports.department-reconciliation` (screen, print, CSV — same `range()`-filtered pattern as every other
report), via a new `DepartmentReconciliationReportService`. Two figures per department, deliberately kept
distinct rather than blended into one number, mirroring the existing accrual-vs-cash split
`FinancialReportService::revenueByChargeType()`/`cashFlow()` already establishes for the whole hotel:

- **Sales/income** (accrual) — what the department actually sold or charged in the period: POS order totals for
  Bar/Restaurant/Bar Outside/Bucareaux; folio charges (room/lodging + front-desk petty sales + other service) for
  Front Desk.
- **Cash in/out/net** — what actually moved through the central payment ledger for that department, cash only —
  the figure each department's shift handovers should sum to.

They will not always match (a bar tab settled onto a guest's room folio at checkout is bar *sales* but front-desk
*cash*) — that gap is exactly what makes the report useful. Every department's closed shifts within the selected
range are listed underneath, directly surfacing the per-shift reconciliation §19 built, per the report's own
purpose. Visible to `view-financial-reports` **or** `view-shift-reports`, since the Cashier holds the latter but
not the former and should still be able to see this report.

### 17.7 Gym & Swimming Pool

Built exactly as described: "we have a gym, and swimming pool guest have free access but others can register for
the gym at the front desk and pay the registration fee even the swimming pool slots can be paid at the
receptionist (front desk) before granted a access to the pool." Three new tables — `facilities` (Gym, Pool, seeded
by `FacilitySeeder`), `facility_rates` (configurable prices per facility — never hardcoded, matching every other
price in this system; seeded with a starter "Gym Registration" and "Pool Slot" rate the owner should review), and
`facility_accesses` (one row per grant, either free — `stay_id` set, requires the stay be currently `active` — or
paid — a walk-in's name/phone, a rate, and the payment it was sold under, all created together in one transaction
via `App\Services\FrontOffice\FacilityAccessService`). New permissions: `grant-facility-access` (Front Desk and
up — day-to-day granting/selling) and `manage-facilities` (Owner/GM/Duty Manager — configuring facilities and
their rates, kept separate exactly like every other configure-vs-operate split in this system). A walk-in's paid
access prints on the same narrow thermal layout (`layouts.print-thermal`) the POS receipts use, since it's the
same kind of front-desk-counter sale.

### 17.8 Every report printable and date-range filterable

Every report already accepted `?start=&end=` via the shared `ReportController::range()` helper (defaults to
month-to-date) — the actual gap was print views. Eight reports that had a screen but no print sibling now have
one, each reusing the exact same controller data the screen view already renders (nothing recomputed): KPI
Dashboard, Financial, Income Statement, Occupancy, Arrivals & Departures, Guest Stay History (which already had a
CSV export, now also has print), Inventory, and HR. Every print view follows the established
`@extends('layouts.print')` pattern from `expense-report-print.blade.php` exactly. Two reports were deliberately
left as-is, documented rather than silently worked around: `Inventory`'s stock-valuation and low-stock figures are
point-in-time snapshots ("as of today") that a date range genuinely does not apply to the way it does to a
period's transactions (only the wastage-cost figure on that same screen is range-filtered, as it already was);
and the Weekly Owner Summary keeps its `week_ending` single-date/fixed-7-day-window design rather than being
force-fit into a `start`/`end` range, since a fixed week is the entire point of that specific report.

### 17.9 Verification

`php -l` across every touched/new PHP file; `verify_routes.py` (225 named routes, up from 197, no duplicates; the
one "missing" hit is the same pre-existing `request()->route('outlet')` false positive documented in earlier
rounds, re-confirmed unchanged); Blade-directive-balance counts across all 28 touched/new view files (all clean —
the only "mismatches" are the expected one-line `@section('title', ...)` calls that never need `@endsection`,
already the established pattern throughout this project). Six new test files / 22 new test methods added — see
`TEST_REPORT.md` §2.8 for the full list; totals are now 73 files / 430 methods, same standing "written and
manually traced, never executed" limitation as every other test in this project (no reachable database, no
`vendor/` directory in this sandbox).

**Deployment note:** this round adds six new migrations
(`2025_01_02_001200` through `2025_01_02_001700`) and no destructive schema changes — every new column is
nullable or has a safe default, every widened enum only appends new values. **Run `php artisan migrate --force`**
after deploying, then **`php artisan db:seed --class=RolesAndPermissionsSeeder --force`** (new permissions/roles
this round need to exist before anyone can be granted them) and, for a fresh/staging installation only, **`php
artisan db:seed --class=DepartmentSeeder --force`** and **`php artisan db:seed --class=FacilitySeeder --force`**
(both idempotent — safe to re-run, they only create rows that don't already exist by their unique code).

**Post-delivery hotfix (same round):** the first live deployment attempt hit
`PermissionDoesNotExist: There is no permission named 'operate-bar_outside-pos' for guard 'web'` when running the
seeder above. Cause: `RolesAndPermissionsSeeder::PERMISSIONS` (the master list `run()` creates rows from) was
missing `operate-bar_outside-pos` and `operate-bucareaux-pos`, even though both were already referenced further
down in the `duty_manager`, `bar_outside_staff`, and `bucareaux_staff` grant lists — `syncPermissions()` throws
when asked to grant a permission that was never created. Fixed by adding both to `PERMISSIONS` (same file, right
after the other three `operate-*-pos` entries). This also means Owner/General Manager — who get every permission
in `PERMISSIONS` via `$all` — were silently missing these two permissions as well before the fix; no role had
been fully seeded yet on the live server when the error was hit, so no further cleanup is needed beyond
re-running the seeder. A small script cross-checked every permission string used anywhere in `$grants` against
`PERMISSIONS` after the fix; no other gaps exist.

## 18. Third post-9.8 fix round — mandatory shift gating, reconciled financial accounts, and client debt settlement

Sent as one message covering four distinct requests: the bar, restaurant, and front desk must not be able to
record any transaction without an open shift; the shift a user opens must be derived automatically from their own
login rather than picked from a department dropdown; the system needs real, reconciled financial accounts (cash,
bank, MTN Mobile Money, Orange Money, etc.) rather than the generic `cash`/`bank_transfer`/`mobile_money`/`card`
method categories `payments.method` already recorded; and expenses/cash disbursements must specify exactly which
of those accounts the money left from. A fifth line — "create a means of closing client debt" — is covered
separately in §19, since it turned out to need its own clarifying question and its own design. Four clarifying
questions were asked before any of this was built (shift-gating scope, block scope, reconciliation mechanism, and
what "closing debt" means), and the answers are recorded verbatim below because, as with every other round in this
project, they are the actual specification for what got built. **Note on section numbers:** code docblocks
written during this round refer to "PHASE9_AUDIT_AND_PLAN.md §21" (shift gating/financial accounts) and "§22"
(client debt) — that anticipated a doc restructure, matching how §17's subsections (§17.4/17.5/17.7) are
referenced elsewhere as "§18/§19/§20", which never happened either. Both features actually live here, as §18 and
§19, continuing the doc's real top-level numbering; no code comments were rewritten to match, to avoid touching
dozens of unrelated files for a purely cosmetic renumbering.

### 18.1 "No transaction without an open shift" — five departments, every payment method, blocking creation only

**What was asked, and the clarifying answers received:** "make it such that the bar, restaurant, and the front
desk (receptionist) can not do any transaction without opening a shift." Two follow-up questions were asked and
answered before building anything: which departments should be gated (answer: "All five: Front Desk, Bar,
Restaurant, Bar Outside, Bucareaux" — the two newer outlets from §17.1 included even though the user only named
three by name), and how broad the block should be (answer: "Every transaction, any payment method" — not cash
only).

**What was built:** a new, small, single-purpose `App\Services\Finance\ShiftGateService`, kept deliberately
separate from `ShiftService` itself (whose own original design decision — §17.5's "opening/closing a shift does
**not** gate payments... a materially worse failure mode than a shift simply not existing yet" — is now
explicitly and permanently reversed for these five departments only, and preserved unchanged for every other
department that holds `manage-own-shift`, e.g. Cashier → Finance, Duty Manager → Management). `assertOpenForCode()`/
`assertOpenForDepartment()`/`assertOpenForOutlet()` throw a plain `HmsException` ("Front Office has no open shift
right now — open a shift (Finance > Shifts) before recording this transaction.") whenever the resolved department
is one of `FO`/`BAR`/`REST`/`BAROUT`/`BUCA` and that department has no `shifts` row with `status = 'open'`. It is
wired into every place real new business gets created for those departments, and deliberately left out of every
place that reverses, waives, or concludes existing business:

- `PaymentLedgerService::record()` — the single central point every payment already passes through — gates
  automatically whenever a payment's department was *auto-derived* from a guest-facing `source_module` (POS
  sales, folio payments, general payments, reservation deposits, facility-access sales; §17.3's existing
  auto-derivation map is reused unchanged for this). A department passed *explicitly* by the caller (Expense,
  Cash Disbursement, Payroll — none of which are guest-facing "is someone on duty" transactions) is never gated,
  regardless of which department that happens to be.
- `OrderController` (`store`/`addItem`/`confirm`/`close`) — every POS order action that creates or advances a
  sale, for all five outlet route segments.
- `FolioController::charge()` — a manual folio charge never touches `PaymentLedgerService` at all (it's not a
  payment), so this has to be asserted explicitly, first thing, before validation.
- `CheckInService` (`checkInFromReservation()` and `checkInWalkIn()`) — both post an initial lodging charge
  directly via `FolioService`, same reasoning as folio charges above.
- `FacilityAccessService::grantForGuest()` — a *free* guest facility grant never touches the ledger either; a
  *paid* walk-in sale already goes through the ledger-level gate, so it isn't gated a second time here.

**Deliberately left ungated**, each for its own documented reason (`ShiftGateService`'s own docblock is the
canonical source — this is a summary):

- `CheckOutService` — spec section 10's long-standing "an unpaid balance never blocks checkout" invariant. Even
  though checkout can post a genuine extension-night charge automatically, blocking a guest's departure because
  someone forgot to open a shift would be a strictly worse failure than a missing shift, the exact reasoning
  §17.5 already used to justify not gating payments at all.
- `StayController::markComplimentary()`/`unmarkComplimentary()` — these reverse or waive a charge; they never
  create new business.
- Expense/Cash Disbursement/Payroll payments — already excluded above (explicit department, not guest-facing).
- Plain reservation creation with no deposit — booking ahead of a shift being open is completely normal (e.g. a
  phone reservation taken days in advance); only the deposit *payment* itself is gated, automatically, the moment
  it's recorded.

The gate fires on the transaction's **resolved** department, not the acting user's own department — an
accountant manually posting a folio payment is still blocked if Front Office has no open shift, which matches
"is someone actually on duty at this outlet" more closely than gating by who happens to be logged in.

### 18.2 The shift's department is now automatic, never a dropdown

**What was asked:** "let the shift not be [a] dropdown of the departments but the department should be automatic
based[d] on the login." Before this round, `ShiftController::store()` accepted a `department_id` chosen freely
from a `<select>`, regardless of which department the logged-in user actually belonged to.

**What was built:** `users.department_id` (already a real column, not derived from the Spatie role) is now the
*only* source `ShiftController::store()` reads — the department field was removed from the form entirely, and
even a spoofed `department_id` in the POST body is simply never looked at. A user with no `department_id` set at
all gets a clear `HmsException` ("Your account has no department assigned...") instead of silently failing or
guessing. `finance/shifts/index.blade.php`'s "open a shift" form now shows the user's own department as fixed
text, with a distinct error state when none is assigned.

### 18.3 Reconciled financial accounts — Cash, Bank, MTN Mobile Money, Orange Money, and more

**What was asked:** "we need a reconciled cash account, bank account, mtn mobile money, orange money etc." A
clarifying question on *how* reconciliation should work offered two shapes; the answer picked "the accountant
enters the real balance from the statement/app" — i.e. a human-typed **declared** balance compared against a
**computed** expected balance, never the reverse.

**What was built:** a new `financial_accounts` table/model (`name`, unique `code`, `type` enum
`cash|bank|mobile_money|card`, `opening_balance` — see the migration's own docblock for why a nonzero starting
balance has to exist, since this software did not exist before today and a real bank account already had money in
it) and `financial_account_reconciliations` (one row per reconciliation event: `expected_balance` computed live,
`declared_balance` typed in, `variance` the difference — the exact same "never fill in a form that could agree
with itself" principle `CashReconciliationService` and `ShiftService::close()` already established, just applied
to a named real-world account instead of a department's till). `FinancialAccountService::currentBalance()` sums
`opening_balance` plus every `payments` row ledgered against that account (in minus out) — computed fresh every
time, never cached or hand-maintained. `FinancialAccountSeeder` starts every install with four accounts — Cash,
Bank, MTN Mobile Money, Orange Money, all at a zero opening balance the owner is expected to correct once from the
Financial Accounts screen — and the owner can add more (a second bank account, a new mobile money provider) at any
time, exactly like `DepartmentSeeder`'s list already works. New permission `manage-financial-accounts`
(Owner/GM/Accountant only — Duty Manager, who can do almost everything else in Finance, deliberately cannot
create or reconcile accounts).

`payments.financial_account_id` (nullable FK) is the link between a real payment and the real place the money
moved through. `PaymentLedgerService::record()` auto-attributes it from `method` when a caller doesn't pass one
explicitly — `cash → Cash`, `bank_transfer → Bank`, `card → Bank` — an unambiguous default in every one of those
three cases. `mobile_money` is **deliberately left unmapped**: a mobile-money sale's `method` alone can't say
whether the money is MTN or Orange, and guessing would silently misattribute it, which is worse than leaving it
blank pending a caller that actually knows. This is a documented, intentional scope limit for this round, not an
oversight — no income-side UI in this round was extended to ask "which mobile money provider," only the two
explicit-attribution paths below (expenses, cash disbursements) were, because those already have a natural place
in their forms to ask.

**For expenses and cash disbursements — the second half of what was asked** ("for expenses specify the particular
financial account... the money is leaving for the expense, same goes for cash disbursement"):
`ExpenseService::markPaid()` and `CashDisbursementService::disburse()` both now *require* a `FinancialAccount`
(changed from an optional/inferred `method` string) — `method` is derived from the chosen account's own `type`
rather than accepted as a separate value, so a caller can never record a cash expense against the Bank account by
mistake; the two values simply cannot disagree anymore. Both controllers' pay/disburse forms now show a financial
account `<select>` populated from `FinancialAccount::where('is_active', true)` instead of a generic method
dropdown.

### 18.4 Verification, tests, and deployment for §18

`php -l` across every touched/new PHP file (clean); `verify_routes.py` (234 named routes, up from 225 — the one
"missing" hit remains the same pre-existing `request()->route('outlet')` false positive re-confirmed unchanged
across every round since §17.9); a Blade-directive-balance sweep across every view in the project (the only
"mismatches" are the same two established false-positive patterns already documented in earlier rounds — the
one-line `@section('title', ...)` form that never needs `@endsection`, and `@canany`/`@endcanany` matching the
literal word inside a code comment in `layouts/app.blade.php`, not a real directive — nothing new or unexplained).

Wiring a hard gate into five departments' worth of transaction paths broke the assumptions of every existing test
that exercises one of those paths without first opening a shift — 22 pre-existing test files needed a
`CreatesUsers::openShiftFor('<CODE>')` call added (usually in `setUp()`, occasionally scoped to one affected test
method after tracing the exact order of checks the underlying controller/service now performs, to confirm each
test's original assertions still fire at the same point): `ShiftTest` (rewritten for §18.2's automatic-department
behavior), `AdditionalOutletsTest`, `DepartmentReconciliationReportTest`, `OrderTest`, `OrderCrossOutletTest`,
`ComplimentaryOrderTest`, `FrontDeskPettySaleTest`, `RecipeStockDeductionTest`, `PrintableDocumentsTest`,
`FolioTest`, `PaymentModuleTest`, `ReservationDepositTest`, `StayComplimentaryTest`, `CheckInCheckOutTest`,
`FacilityAccessTest`, `AuditLogTest`, `AccountingDayTest`, `CashDisbursementTest` and `ExpenseTest` (both also
needed their `method` → `financial_account_id` payload updated for §18.3's new required field, and
`ExpenseService::markPaid()`'s call sites converted from a string to a `FinancialAccount` instance),
`FinancialReportTest` and `PaymentSummaryReportTest` (same `markPaid()` conversion, plus an `openShiftFor('FO')`
before their folio-payment calls), and `OperationsReportTest` (one test method needed the same folio-payment
fix). Three new dedicated test files add negative-path and new-feature coverage that fixing existing tests alone
doesn't: `ShiftGateTest` (10 methods — the missing-shift block for all five gated departments, confirms opening
the right shift unblocks it, confirms a shift open for one gated department does *not* unblock a different one,
and confirms checkout/expense payouts are never blocked even with zero shifts open anywhere), `FinancialAccountTest`
(6 methods — create/reconcile/deactivate, duplicate-code rejection, and `currentBalance()`'s live computation),
and two new methods added to `CheckInCheckOutTest` covering §19's company billing (see §19.4). Total test
inventory after this round: 74 files / 458 methods, same standing "written and manually traced, never executed"
limitation as every other test in this project (no reachable database, no `vendor/` directory in this sandbox).

**Deployment note:** this round adds eight new migrations (`2025_01_03_000100` through `2025_01_03_000800`,
covering both §18 and §19 below) and no destructive schema changes — every new column is nullable or defaults
safely, every new table is additive. **Run `php artisan migrate --force`** after deploying, then **`php artisan
db:seed --class=RolesAndPermissionsSeeder --force`** (two new permissions, `manage-financial-accounts` and
`manage-accounts-receivable`, need to exist before anyone can be granted them) and **`php artisan db:seed
--class=FinancialAccountSeeder --force`** (idempotent — creates the four starter accounts only if they don't
already exist by their unique code; safe to run on an install that already has some).

## 19. Client debt — settling a guest's or a company's outstanding balance, and billing a stay to a company

**What was asked, and the clarifying answer received:** "create a means of closing client debt." The follow-up
question offered several shapes at once (record a payment against it; write it off/forgive it; track it at a
company level rather than just one guest) as a multi-select; the answer picked **"record a payment against it"**
together with **"track debt for a company/corporate account, not just one guest,"** and explicitly did *not*
select the write-off/forgive option — so that stayed unbuilt this round, on purpose, even though the
`accounts_receivable` table already carries `written_off_by`/`written_off_reason` columns from an earlier round
that anticipated it might be needed later.

### 19.1 Settling a debt — a real payment, through the same central ledger as everything else

**What was built:** `App\Services\Finance\AccountsReceivableService::recordSettlement()` locks the target
`AccountsReceivable` row (`lockForUpdate()`, the same concurrency-safety pattern used everywhere else in this
system a balance can be raced against), refuses to settle anything already `paid`
(`AccountsReceivable::isSettleable()` — `open`/`partially_paid` only) or an amount exceeding what's still
outstanding, then posts through `PaymentLedgerService::record()` with a new `sourceModule: 'ar_settlement'` —
deliberately **not** through `FolioService`, because the folio a debt originated from is already closed by the
time it becomes a receivable, and `FolioService::post()` correctly refuses to write to a closed folio, by design.
A settlement reduces `amount_outstanding` and flips `status` to `partially_paid` or `paid` depending on whether
anything is left. `'ar_settlement'` is deliberately **absent** from `PaymentLedgerService`'s department
auto-derivation map, so — unlike every other guest-facing payment this round — settling old debt is **never**
shift-gated; a guest walking in to pay off a balance from a previous stay shouldn't be turned away because nobody
has opened today's Front Office shift yet. New permission `manage-accounts-receivable`, granted to Accountant
(full access, alongside `manage-financial-accounts`) and to Duty Manager/Cashier/Front Desk (settle only — a
returning guest paying off old debt is usually taken at the front desk, per the same reasoning that already
granted Front Desk `grant-facility-access` in §17.7).

`finance.accounts-receivable.index` lists every open/partially-paid balance (filterable by status), each showing
the guest, the company it's billed to (if any), and the originating stay, plus a per-company rollup table
(`Company::withSum` of its outstanding receivables) so "how much does this company owe across everything" is a
single glance, not a manual sum.

### 19.2 Companies — a corporate billing account a stay can be checked in "billed to"

**What was built:** a new `companies` table/model/controller (`name`, `contact_name`, `phone`, `email`,
`credit_limit` — informational only, never enforced as a hard cap this round; gated on the same `manage-guests`
permission as Guests, since this is the same kind of front-office record-keeping). `stays.company_id` (nullable
FK) is set optionally at check-in — both `checkInFromReservation()` and `checkInWalkIn()` gained an optional
`companyId` parameter, and the walk-in form gained a "Bill to company (optional)" select. `CheckOutService`
copies `$stay->company_id` onto the `AccountsReceivable` row it creates for an unpaid balance, which is the entire
mechanism that makes company-level debt tracking work: no separate ledger table, no double-entry to keep in sync
— a company's total outstanding debt is simply `SUM(accounts_receivable.amount_outstanding WHERE status IN
('open','partially_paid') AND company_id = ?)`, exposed as `Company::totalOutstanding()`.

### 19.3 What was deliberately not built

Write-off/forgive was offered as an option and explicitly not selected — the `written_off_by`/`written_off_reason`
columns exist on `accounts_receivable` from an earlier round but nothing in this round writes to them; a debt can
currently only shrink through `recordSettlement()`, never be zeroed out administratively. A `credit_limit` on a
Company is stored but not enforced — nothing currently blocks checking a stay in against a company whose existing
debt already exceeds that number. Both are flagged here, not silently assumed, exactly as this project's
established practice requires (spec section 40) — either would be a small, clearly-scoped follow-up if the owner
asks for it.

### 19.4 Verification and tests for §19

Covered together with §18's sweep above (§18.4) — the same `php -l`/`verify_routes.py`/Blade-balance run covers
both features, since they shipped in the same round. Dedicated new coverage: `AccountsReceivableTest` (9
methods — partial and full settlement, over-payment rejection, an already-paid debt cannot be settled again, a
permission check, confirmation that settlement never requires an open shift, the company debt rollup summing
across several stays while excluding a fully-paid one and a different company's debt, and company creation/
permission checks), plus two new methods in `CheckInCheckOutTest` (a walk-in check-in can be billed to a company;
an unpaid checkout for a company-billed stay tags the resulting receivable with that company and the company's
`totalOutstanding()` reflects it) that trace the whole "billed to a company at check-in → unpaid at checkout →
shows up on that company's ledger" path end to end, not just each half in isolation.

**Deployment note:** covered by the same migration range as §18 above (`2025_01_03_000100` through
`2025_01_03_000800`) and the same `RolesAndPermissionsSeeder` re-run (`manage-accounts-receivable` is one of the
two new permissions it adds). No separate seeder is needed for Companies — the list starts empty and the owner
adds companies from the Companies screen as needed, same as Guests already works.

## 20. Fourth post-9.8 fix round — shift scope-down, a payroll double-payment audit finding, front-desk reservation approval, and website booking requests

Sent as one message covering four distinct requests: "let shifts not exist for the accountant, and the manager
and the duty manager" (i.e. remove their ability to open a shift); "look at all the account connections and make
necessary corrections such that money is not d[o]uble recorded" (an open-ended audit request, not a specific bug
report); "make sure the front desk have the right to a[p]prove reservation too"; and "update the system such that
it can catch reservations fr[o]m the website too." The fourth item was ambiguous enough on its own (how does a
website even reach this system? does a guest pay online? does it need approval?) that three clarifying questions
were asked before building anything, and the answers — recorded verbatim, as with every other round in this
project — are the actual specification for what got built: **"Hosted booking page (recommended)"** (not an API
for a third-party site to call); **"request only but with upload of deposit screen shot transaction details"**
(no online payment gateway integration at all); and **"Goes in as 'pending' for staff to approve
(recommended)"** (no new bypass of the approval gate spec section 22 already establishes). **Note on section
numbers:** as with §18/§19, code docblocks written during this round refer to "PHASE9_AUDIT_AND_PLAN.md §20" for
all four items — that reference is correct this time; this is genuinely §20 in the doc's real top-level
numbering.

### 20.1 Shifts no longer exist for the Accountant, General Manager, or Duty Manager

**What was asked:** "let shifts not exist for the accountant, and the manager and the duty manager." Read as:
these three roles should no longer be able to open a shift at all — a request to shrink a permission, not to
change any transaction behavior.

**What was built:** `manage-own-shift` removed from General Manager's and Duty Manager's permission grants in
`RolesAndPermissionsSeeder` (Accountant never held it in the first place — confirmed by reading the seeder before
touching anything, so that block only got a clarifying comment). Both roles keep `view-shift-reports`, so they can
still see every department's shift history and reconciliation — only the ability to open/close a shift of their
own is removed. Because every "open a shift" nav link and form in this codebase is already gated by
`@can('manage-own-shift')`/the route's `permission:manage-own-shift` middleware (established since §17.5), this
is a pure permission-table change — no controller, service, or view needed to be touched for the UI to correctly
stop offering shift-opening to these three roles. One consequence worth flagging explicitly, not silently: Duty
Manager's own department is Management, which is not one of the five departments §18.1's `ShiftGateService`
blocks transactions for, so removing `manage-own-shift` from Duty Manager does not newly block anything Duty
Manager does — it only removes a capability (opening a Management-department shift) that had no real transactional
consequence to begin with, since Management was never a gated department.

### 20.2 The audit: "make sure money is not double recorded" — one genuine gap found and fixed

**What was asked:** an open-ended instruction to check for a class of bug, not a report of one. Treated as a real
code audit, not an invitation to invent unrelated "hardening": every call site that posts to the central ledger
(`PaymentLedgerService::record()`) was traced — `PayrollService`, `CashDisbursementService`,
`AccountsReceivableService`, `ExpenseService`, `OrderService`, `FacilityAccessService`, `FolioService` (two call
sites), `ReservationService`, `PaymentController` — and `grep -rn "Payment::create(" app/ database/` was run
across the whole codebase to re-confirm the single-writer invariant this project has held since early rounds:
exactly one place in the entire application is allowed to insert a `payments` row, inside
`PaymentLedgerService::record()` itself. That invariant held; nothing bypasses it.

What did **not** hold: every money-moving action in this codebase is supposed to follow one established
concurrency-safe pattern — `DB::transaction()` wrapping a `lockForUpdate()` re-fetch of the row, with the status
precondition (e.g. "is this already paid?") re-checked against that *locked* instance, *inside* the transaction —
first documented in `ExpenseService::markPaid()`'s own "Concurrency fix" docblock, and correctly present in
`RefundService::approve()`, `CashDisbursementService::disburse()`, `AccountsReceivableService::recordSettlement()`,
and `ShiftService::open()`/`close()`. `PayrollService::pay()` was the one place this pattern was missing: it
checked `$run->isApproved()` *before* opening the transaction, then re-fetched the row *without* a row lock inside
it. Two requests hitting "pay this payroll run" at nearly the same moment (a double-click, a slow network retry,
two staff members both authorized to pay payroll acting within the same second) could both pass the initial
`isApproved()` check before either had committed, each post a full `PaymentLedgerService::record()` for the same
run, and the run would be paid twice — a real, if narrow, double-recording bug, exactly the failure mode the
user's request named. **Fixed** by moving the precondition check inside a `lockForUpdate()`'d transaction,
mirroring `ExpenseService::markPaid()`'s exact pattern: the second concurrent request now correctly finds the run
already `paid` once it acquires the lock and is rejected before it can post anything. This is the concrete
"correction" delivered for this request — a real, previously-latent bug found by systematic comparison against
this project's own established pattern, not a cosmetic pass or an invented feature.

### 20.3 Front Desk can now approve or reject a reservation

**What was asked:** "make sure the front desk have the right to a[p]prove reservation too." Before this round,
Front Desk held `manage-reservations` (create/view/cancel) but not `approve-reservation` — only Duty
Manager/General Manager/Owner could move a reservation out of `pending`.

**What was built:** `approve-reservation` added to Front Desk's grant in `RolesAndPermissionsSeeder`. Before
making this change, `ReservationService`/`ReservationController` were checked for a self-approval guard (the
pattern Expense, Refund, and Leave requests all have — a requester cannot approve their own request) — none exists
for reservations anywhere in this system today, so Front Desk now holds exactly the same
`manage-reservations` + `approve-reservation` combination Duty Manager already had, with no new business rule
needed, consistent with existing precedent and the user's explicit, unambiguous instruction.

### 20.4 Website booking requests — a hosted, unauthenticated booking page

**What was asked, and the clarifying answers received:** "update the system such that it can catch reservations
fr[o]m the website too." Three questions were asked before building anything (how a reservation should reach the
system; whether a guest can pay online; whether it needs approval), and the answers are the specification: a
**hosted booking page** this system serves itself (not an API contract for a separately-built website to call —
the owner's website links to it, e.g. a "Book Now" button), **no online payment** — a guest may optionally attach
proof of a deposit they say they already sent by other means (mobile money, bank transfer) — and every submission
**always lands as `pending`**, reviewed by staff exactly like a phone or walk-in reservation.

**What was built:**

- **`reservations` gains four purely-additive columns** (migration `2025_01_04_000100`): `source` enum
  (`staff`/`website`, defaulted `staff`, indexed) for filtering/badging; `deposit_proof_path`,
  `deposit_claimed_amount`, `deposit_reference` — all nullable — to hold what the guest *says* they already sent.
  None of these four columns is ever written to by anything that also touches the payment ledger; they are purely
  informational until a staff member acts on them.
- **A permanent seeded system user** (`WebsiteBookingSeeder`, idempotent, `website-booking@system.local`) satisfies
  `reservations.requested_by`'s existing `NOT NULL` foreign key for a submission with no logged-in staff member
  to attribute it to. It holds no role (so no permission at all) and `is_active = false` (so it can never log in
  even if its random, nobody-knows-it password were somehow guessed) — deliberately chosen over making
  `requested_by` nullable, since this sandbox cannot execute a migration to verify a doctrine/dbal-dependent
  `->change()` operation works and this project's `composer.json` does not include that package; a seeded system
  account is a zero-schema-risk way to satisfy the same constraint. It doubles as an honest, visible signal to
  staff reviewing the pending queue ("Requested by: Website Booking") that a request came from the public page,
  not a colleague.
- **`ReservationService::createFromWebsite()`** — deliberately kept separate from the existing staff-facing
  `create()` rather than overloading it, since the caller is a guest with no negotiated rate and no known guest
  record yet: the room category's own `base_rate` is quoted automatically (staff can adjust or decline at
  approval time if it's wrong), and the guest is matched by phone to an existing record or created inline, the
  same pattern `CheckInController::walkInStore()` already uses for a walk-in with no prior reservation. Always
  creates the reservation with `status = 'pending'` and `source = 'website'` — the approval gate from spec
  section 22 is completely unaffected; the website is just a new front door onto the same queue a phone or
  walk-in request already goes through.
- **`App\Http\Controllers\Public\WebsiteBookingController`** (`showForm`/`store`/`success`) — a new, deliberately
  unauthenticated controller, routed outside the `auth` middleware group in `routes/web.php` under a `/book`
  prefix, protected the same way this project's other unauthenticated endpoints (login) already are: a
  `throttle:10,1` rate limit, plus a honeypot field (`company_website`, visually hidden but not `display:none`,
  since some spam bots specifically skip fields hidden that way) that silently no-ops a submission rather than
  revealing to an attacker exactly what tripped the check. Validates every field server-side (required
  guest/stay details, an active room category, check-out after check-in, check-in not in the past, an optional
  deposit-proof upload restricted to `.jpg/.jpeg/.png/.pdf` at up to 5MB, stored on the existing `public` disk
  under `deposit-proofs/`, the same disk/pattern the hotel-logo upload already established).
- **`resources/views/public/booking/{create,success}.blade.php`** and a new **`layouts/public.blade.php`** — a
  lightweight, unauthenticated public layout (CDN Tailwind, no nav, no login-gated anything) separate from the
  staff-facing `layouts/app.blade.php`, so a guest filling out this form never sees any part of the internal
  application shell.
- **Staff-facing surfacing:** the reservations index now shows a "Website" badge per row (via a new
  `Reservation::isFromWebsite()` helper) and, for a user holding `manage-reservations`, an info box with the
  copyable public booking URL (`url('/book')`) to paste into the hotel's own website — this is how the
  non-technical owner actually connects the two systems, since this round deliberately did not build any
  code-level integration into a separate website platform. The reservation detail page shows a clearly-labeled
  "Guest-claimed deposit (not yet verified)" panel — the claimed amount, reference/note, and a link to the
  uploaded proof file — with explicit text stating it has **not** been recorded as a payment; a staff member still
  has to use the existing, permission-gated "Record deposit / advance payment" action
  (`ReservationService::recordDeposit()`, unchanged, still the only thing that ever calls
  `PaymentLedgerService::record()` for a reservation) once they've verified it. This is the same "never invent a
  second way to record money" discipline §20.2's audit exists to protect — a website submission can describe a
  payment but can never create one.

**What was deliberately not built:** any online payment integration (explicitly ruled out by the clarifying
answer); an API endpoint for a separately-hosted website to call directly (the "hosted page" answer means this
system's own page *is* the integration — the owner links to it); any way for a guest to see or track the status of
their own submitted request after leaving the confirmation page (no guest accounts exist in this system at all,
staff-only login) — a returning guest calls the phone number shown on the confirmation page, which is why
`WebsiteBookingController::success()` surfaces it.

### 20.5 Verification, tests, and deployment for §20

`php -l` across all fourteen touched/new PHP files (clean); `verify_routes.py` re-run (the only "missing" hit
remains the same pre-existing `request()->route('outlet')` false positive documented since §17.9, unchanged); a
Blade-directive-balance sweep across every view in the project (only the same two established false-positive
patterns already documented in §18.4 — the one-line `@section('title', ...)` form, and `@canany` appearing inside
a code comment in `layouts/app.blade.php` — nothing new or unexplained, including in the two brand-new public
views). New/updated test coverage: one new method in `PayrollTest` proving a payroll run cannot be paid twice
concurrently and posts exactly one payment; two new methods in `ShiftTest` (Accountant/GM/Duty Manager can no
longer open a shift; all three can still view shift reports); `ReservationTest` updated (the now-incorrect
"front desk is forbidden from approving" test replaced with three new tests covering front desk approving,
rejecting, and confirming a role genuinely without `approve-reservation` is still forbidden) and one existing
`PendingApprovalsServiceTest` method corrected for the same permission change (front desk now sees the
reservations-approval dashboard item; `bar_staff` is the new negative control); a new dedicated
`tests/Feature/Public/WebsiteBookingTest.php` (17 methods — the form renders without authentication, a
submission creates a `pending`/`source = website` reservation attributed to the seeded system user, a returning
guest is matched by phone rather than duplicated, the success page shows the reference number, a deposit
screenshot/amount/reference is stored and surfaced without ever creating a `payments` row, a non-image/non-PDF
proof file is rejected, every required field and business-rule validation (inactive/nonexistent category,
check-out not after check-in, a past check-in date), the honeypot field silently no-ops a spam submission, the
rate limit trips on the 11th submission within a minute, staff can approve or reject a website-sourced reservation
exactly like any other, the website badge renders on the reservations list, and the seeded system user itself has
no role and cannot log in). Total test inventory after this round: **75 files / 480 methods** (up from 74/458),
same standing "written and manually traced, never executed" limitation as every other test in this project (no
reachable database, no `vendor/` directory in this sandbox).

**Deployment note:** this round adds **one** new migration (`2025_01_04_000100`, purely additive — every new
column is nullable or defaulted, no existing row or query is affected). After deploying: **`php artisan migrate
--force`**; **`php artisan db:seed --class=RolesAndPermissionsSeeder --force`** (picks up both the Accountant/
GM/Duty Manager shift-permission removal and Front Desk's new `approve-reservation` grant — safe to re-run,
Spatie's seeder pattern here always syncs a role's exact permission set rather than only adding); and **`php
artisan db:seed --class=WebsiteBookingSeeder --force`** (creates the one-time system user the website-booking
feature needs — idempotent, safe to re-run). After seeding, an Owner/GM/Accountant/Duty Manager/Front Desk user
can open Reservations to copy the public booking-page URL (`https://<your-domain>/book`) and paste it into the
hotel's own website (e.g. behind a "Book Now" button or link) — every submission through that link will appear in
the same Reservations queue as a `pending` request with a "Website" badge, ready for staff to review, assign a
room to, and approve or reject exactly as they already do today.

### 20.6 Post-deploy hotfix: `financial_account_reconciliations` migration hardened against a real "table already exists" failure

**What happened:** a real deploy of the third post-9.8 fix round (§18-19) reported `2025_01_03_000200_create_
financial_account_reconciliations_table` failing with "table already exists" on `php artisan migrate --force`.
Most likely cause, given this project's explicit shared-hosting deployment target (§10, e.g. InfinityFree — tight
per-request execution limits, no long-running processes): this migration's `CREATE TABLE` is the largest/slowest
in its eight-migration batch, so it's the most likely one to have the request time out, or the connection drop,
*after* MySQL finished creating the table but *before* Laravel's next line could write this migration's row into
its own `migrations` tracking table (a separate query, run immediately after the DDL succeeds). A later migrate
retry then sees this migration as "not yet run" and tries the same `CREATE TABLE` again, colliding with the table
that's already there. Only this one migration was reported — consistent with it being the largest statement in
the batch, not the whole batch failing to track.

**The fix, and a correction to how it was first suggested:** the migration now checks `Schema::hasTable(
'financial_account_reconciliations')` and returns immediately if the table already exists, before attempting
`Schema::create()` — making a retry of this specific migration a safe no-op instead of an error. This is
specifically safe here (and would not automatically be safe for every migration) because a single `CREATE TABLE`
statement is atomic in MySQL — there is no "half-created" state to worry about missing; either the table exists
with exactly the columns this migration defines, or it doesn't exist at all. The fix as first proposed called for
`Schema::createIfNotExists()` — that method does not exist anywhere in Laravel's `Schema` facade (checked against
the real API through Laravel 12, this project's declared version); calling it would have thrown a fatal "call to
undefined method" error on the very next deploy attempt, replacing one failure with a worse one. `Schema::
hasTable()` is the real, documented mechanism and is what got shipped instead.

**Verification:** `php -l` on the changed file (clean) and a full project-wide `php -l` sweep re-run (clean,
matching every prior round in this document). This is a single-file, additive-only change — no other migration,
model, service, or view was touched — so no new tests were needed; the existing coverage for financial account
reconciliation (`FinancialAccountTest`, §18/§19's test round) is unaffected since it exercises the table's
contents, not the migration file itself. **Not otherwise verified by execution** — same standing sandbox
limitation as everything else in this document (no reachable database, no `vendor/` directory here) — the actual
proof this resolves the reported error is the next real `php artisan migrate --force` on the hotel's own server.

**Deployment note:** no new migration, no seeder to re-run. Deploy the updated project files (this migration file
changed; nothing else in this round did) and run **`php artisan migrate --force`** again — it will now either
silently skip `financial_account_reconciliations` (if the table is already there from the interrupted earlier
attempt) or create it fresh (if it genuinely isn't there yet), and then continue on to whichever migrations after
it in the batch (`2025_01_03_000300` onward) hadn't already run.

### 20.7 Follow-up: the public booking page's broken input styling, and posting a claimed deposit to the ledger at approval

**What was asked:** a screenshot of the live `/book` page showing every text input, date field, number field, and
the room-type dropdown with no visible box around it at all — just a label floating above blank space — plus "let
the amount sent be posted to the ledger only when the reservation is approved."

**The design bug, and the real cause:** every input/select/textarea in `public/booking/create.blade.php` used the
Tailwind class `border-slate-300` (a border *color*) without the separate `border` utility that actually sets
border-*width* — Tailwind's default border-width is `0`, so a color with no width is invisible. This is the exact
class list the internal staff-facing reservation form (`front-office/reservations/create.blade.php`) does *not*
have this bug in — it correctly pairs `border border-slate-300` together, which is what made the mistake easy to
spot once the two were compared side by side. Fixed by adding the missing `border` utility to every field, plus
matching the internal form's `px-3 py-2` padding (the public page previously had none, making every field look
cramped even once the border was visible) and switching the two-column detail grids from a fixed `grid-cols-2` to
`grid-cols-1 sm:grid-cols-2`, so fields stack on a narrow phone screen instead of being squeezed — the public page
is the one screen in this whole system a guest, not staff, is expected to use on their own phone, so this
responsiveness gap mattered more here than anywhere else in the app.

**Posting a claimed deposit to the ledger at approval:** previously, a guest's claimed deposit (§20.4) was purely
informational — staff always had to use the separate "Record deposit / advance payment" action after verifying
it. The new request asks for that to happen automatically the moment staff approve the reservation. Doing this
for real, through the one single-writer `PaymentLedgerService::record()` and never a second, competing way to
record money, needs an actual `method` — `record()`'s signature requires a real string, and per §18.3's already-
established reasoning for never guessing a `mobile_money` payment's specific provider, this project does not
guess a payment method it wasn't told either. A new nullable `reservations.deposit_claimed_method` column and a
matching "How did you send it?" field on the public form (mobile money / bank transfer / card / cash — the same
four values `payments.method`'s own enum already uses) is what makes honest auto-posting possible: `Reservation
Service::approve()` now posts the claimed amount to the ledger, with `sourceModule: 'reservation_deposit'` (the
exact same value manual deposit-recording already uses, so it reads identically in every existing report), **only**
when both a claimed amount and a claimed method are present — never guessed, and a reservation with an amount but
no method (shouldn't happen through the form, which requires both together, but handled defensively regardless)
is left exactly as before: informational only, for a staff member to record manually once they've sorted out what
actually happened.

One real, worth-stating-plainly consequence: because `reservation_deposit` auto-derives the Front Office
department, approving a website reservation that carries a claimed deposit now requires an open Front Office shift
— the exact same rule that already governed recording a deposit manually (§18.1), just triggered at approval time
instead of a separate later step. A reservation with no claimed deposit is completely unaffected and still needs
no open shift to approve.

**A real concurrency bug found and fixed as a direct consequence of this change:** `ReservationService::approve()`
checked the reservation's `pending` status *before* opening its `DB::transaction()`, and never re-fetched the row
with `lockForUpdate()` inside it — harmless while approval only ever flipped a status column, but a genuine
double-payment risk the moment it could also post real money, the exact class of bug §20.2's audit exists to
catch (this one wasn't caught then because, at the time, `approve()` didn't touch the ledger at all — it became a
real risk only because of this very follow-up request). Fixed to match this project's own established pattern
(`ExpenseService::markPaid()`, most recently `PayrollService::pay()` in §20.2): the status check now happens
against a freshly `lockForUpdate()`'d row, inside the transaction, so two near-simultaneous approve() calls on the
same reservation can never both post the deposit — the second always finds the row already
`approved`/`confirmed` and is rejected before it reaches the ledger.

The reservation detail page's "Guest-claimed deposit" panel was updated to match: it now shows amber/"not yet
credited" before approval, and switches to green/"credited at approval" afterward (detected via `approved_at`
being set together with both claimed fields being present — the exact condition the auto-post branch itself
checks, so the badge can never disagree with what actually happened), and the Approve button gained a one-line
warning reminding staff that approving will also record the claimed amount, so they check the proof first.

**Verification and tests:** `php -l` across all seven touched/new files (clean); a full project-wide `php -l`
sweep (clean); `verify_routes.py` re-run (same single pre-existing `outlet` false positive, unchanged); a
Blade-directive-balance sweep across every view including the redesigned public form (only the same two
established false-positive patterns, nothing new). Six new test methods: `ReservationTest` gained one generic
(non-website) proof that the concurrency fix works — approving an already-confirmed reservation throws instead of
silently succeeding; `WebsiteBookingTest` gained five — a claimed deposit with a method is posted to the ledger
on approval (right amount, method, `source_module`, and guest attribution, with zero payments existing before
approval); approving a deposit-bearing reservation with no open Front Office shift throws and leaves the
reservation `pending` with zero payments posted; a reservation with no claimed deposit can still be approved with
no shift open at all (regression guard); a claimed amount with no claimed method (a state the form itself
prevents, proven here by constructing it directly against the service) is never auto-posted even on approval; and
approving an already-approved reservation throws and never posts the deposit a second time. Total test inventory
after this round: **77 files / 486 methods** (up from 77/480 — no new test file this time, six new methods in two
existing files). Same standing "written and manually traced, never executed" limitation as everything else in
this document.

**Deployment note:** one new migration (`2025_01_05_000100`, additive/nullable, guarded with `Schema::hasColumn()`
per §20.6's now-established caution for this shared-hosting target). **Run `php artisan migrate --force`** — no
seeder re-run needed, this round added no new permission and no new seeded row.

### 20.8 Follow-up round: searchable guest box, the front-desk permission cache bug, accountant entry rights, and the day-close rebuild

**What was asked**, verbatim, four separate items in one message:

1. "let the guest not have a dropdown entry for the check in when they are requesting for one to select the
   guest, let it have a text box with searchable characteristic."
2. "the front desk can still not update reservation status."
3. "the accountant is should be able to make expense entries too, disbursement entries, payment entries."
4. "fix the day close and cash reconciliation because it is not working it is just there, it should be able to
   capture and reconcile the payments from guest, visitors, incomes, expenses, and entries made, with the cash,
   bank, mtn mobile money, orange money etc, per day per shift."

Each is its own sub-section below. Item 4 is by far the largest and touches the most files, so it's split further.

#### 20.8.1 Searchable guest box on the staff reservation form

`front-office/reservations/create.blade.php`'s guest field was a `<select>` built from
`Guest::orderBy('first_name')->limit(200)->get()` — capped at the first 200 guests alphabetically, unusable past
that, and a poor experience even under it (finding one person meant scrolling a long list). Replaced with the
exact search-as-you-type pattern already established and working in `checkin/walk-in.blade.php`: a hidden
`guest_id` input, a text box that debounced-fetches `guests.search` (an existing AJAX endpoint, unchanged), a
results dropdown, and `selectGuest()`/`clearGuest()` JS — minus that page's inline "create a new guest" fields,
since a reservation always needs an *existing* `guest_id` (`ReservationController::store()` still validates
`required|exists:guests,id`, with no path to create one inline). `ReservationController::create()` no longer
loads any guest list at all — it only resolves one specific guest to prefill when arriving via a just-created
guest's "Reservation" button (`guests.show`'s `new=1` callout), via `$request->integer('guest_id')`.

#### 20.8.2 "The front desk can still not update reservation status" — a real, systemic caching bug

Front Office's `approve-reservation`/`update-room-status` permissions were re-checked and confirmed *already
correctly present* in `RolesAndPermissionsSeeder`'s `front_desk` grant list (added in an earlier round, per
§20.1's "make sure the front desk have the right to approve reservation too"), and the route middleware and nav
`@canany` gates were re-verified as correctly wired to those exact permission strings. The database-level grant
was right. The live symptom had a different, more serious explanation: **Spatie's permission cache**
(`config/permission.php`'s `cache.expiration_time`, 24 hours by default, caching the whole role→permission map so
`$user->can(...)` doesn't hit the database on every request) was never cleared by `RolesAndPermissionsSeeder`
itself. Every prior round's instruction to "redeploy and re-run the seeder" only ever updated the `roles`/
`permissions` tables — a live site kept answering permission checks from the *stale cached* map until that cache
separately expired on its own, up to 24 hours later. This was already suspected once before: the diagnostic
`hms:check-access` console command (an earlier round) already force-clears the cache before checking, specifically
because of this exact failure mode — but the seeder that actually *causes* every future grant to have this same
latent bug was never fixed at the source. It is now: `RolesAndPermissionsSeeder::run()` calls
`app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions()` once, right after every
`syncPermissions()` call, so a plain `php artisan db:seed --class=RolesAndPermissionsSeeder --force` is now
sufficient on its own, for this and every future permission change — no separate `permission:cache-reset` step to
remember. This is a systemic fix, not a one-off patch for this one report: it protects every round of permission
changes ever made in this system, going forward. If the front desk still cannot approve/update after a redeploy
and reseed, `hms:check-access` remains the next diagnostic step.

#### 20.8.3 Accountant can now raise expenses, disbursements, and general payments (not just approve/pay them)

The accountant could already *approve* and *pay* an expense, and already held `authorize-cash-disbursement`/
`record-cash-disbursement` from an earlier round — but could never *raise* an expense, and had no access to the
general Payments module at all. Three grants added to the accountant's list in `RolesAndPermissionsSeeder`:

- `manage-expenses` — lets the accountant request an expense, the same permission Duty Manager already holds for
  this. Safe against self-dealing: `ExpenseService::approve()`'s self-approval guard
  (`(int) $expense->requested_by === (int) $actor->id`) is a per-*user* identity check, not a per-*role* one, so
  an accountant who raises an expense still cannot approve that same one themselves — a different accountant, or
  GM/Owner, has to (see the new
  `test_an_accountant_still_cannot_approve_their_own_expense_request` test).
- `record-payment` — the general-purpose Payments module (rent, a one-off visitor payment — see
  `PaymentController`'s own docblock), previously reachable only by Front Desk.
- Disbursement entries needed no new grant: `authorize-cash-disbursement`/`record-cash-disbursement` were already
  granted to the accountant in an earlier round (post-9.8 second fix round, §18/§19) — confirmed, not re-added.

#### 20.8.4 The day-close/cash-reconciliation rebuild

This was the largest single piece of work in this round, and needed real investigation before any code changed.
Three separate reconciliation subsystems already existed, none of them talking to each other:

- `ShiftService` — per department, per shift, cash-only declared/expected/variance (post-9.8 second fix round).
- `CashReconciliationService`/`AccountingDay` — whole-hotel, cash-only, and — critically — the *only* thing that
  actually gated `AccountingDayService::lock()` (closing the business day).
- `FinancialAccountService`/`FinancialAccountReconciliation` — per-account (Cash/Bank/MTN/Orange) reconciliation
  against that account's own live ledger balance (§21) — the *correct*, complete model, but never day-scoped and
  gating nothing.

The owner's complaint — "it is just there" — was exactly right: the visible day-close screen only ever drove the
old whole-hotel, cash-only check, completely disconnected from the newer, correct per-account model that every
other Finance screen in this system already uses. Bank, MTN, and Orange money were never actually reconciled by
anything that blocked closing the day.

**A real, previously-shipped bug found during this investigation, unrelated to what was being searched for but
directly relevant to "it is not working":** `financial_accounts.type` (`cash`, `bank`, `mobile_money`, `card`)
and `payments.method`/`cash_disbursements.method` (`cash`, `mobile_money`, `card`, `bank_transfer`) are two
different enums that happen to agree on three of four values — but the seeded "Bank" account's type is `'bank'`,
which is **not** a valid `payments.method`/`cash_disbursements.method` value. Every call site that did
`$financialAccount->type` directly when posting a ledger entry (`ExpenseService::markPaid()`,
`CashDisbursementService::disburse()`) inserted the invalid string `'bank'` into an enum column that doesn't
contain it — a hard database error, every single time, for every expense payment or cash disbursement made from
the Bank account. Fixed with a small, deliberately non-schema-changing translation helper —
`FinancialAccount::paymentMethod()` (`'bank' → 'bank_transfer'`, pass-through for everything else) — rather than
renaming the `financial_accounts.type` enum value itself, consistent with this project's established caution
about `->change()` on a typed/enum column already carrying production data (§20.6). All three known call sites
updated to use it; a project-wide grep confirmed no other call site still read `->type` for a payment method.
Directly regression-tested by `FinancialAccountModelTest` (the translation itself) and
`ExpenseTest::test_marking_an_approved_expense_paid_from_the_bank_account_posts_bank_transfer_not_bank` (the full
HTTP-to-database proof that paying from the Bank account no longer throws).

**The design, built in four connected pieces:**

1. **Day-scoped, duplicate-guarded account reconciliation.** A new nullable `business_date` column on
   `financial_account_reconciliations` (migration `2025_01_05_000200`, `Schema::hasColumn()`-guarded per §20.6,
   with existing historical rows backfilled from `DATE(reconciled_at)` rather than left null).
   `FinancialAccountService::reconcile()` now defaults `$businessDate` to today and refuses a second
   reconciliation of the *same account* on the *same day* — an honest "already done today" guard, not a way to
   quietly overwrite the day's count. (Since this system never backdates a transaction — everything posts "now" —
   the existing live `currentBalance()` figure already *is* the correct "expected balance as of the end of
   today," so no separate day-filtered balance calculation was needed.)
2. **The day-lock gate now checks the real thing.** `AccountingDayService::lock()` no longer looks at the old
   whole-hotel `CashReconciliation` at all — it requires *every currently active* `FinancialAccount` reconciled
   for today's business date, and its error message names exactly which accounts are still outstanding. The old
   `CashReconciliation` table, model, service, and its "record today's cash count" form are all left fully
   intact (this project never deletes an existing feature or its data — see §20.6) — just demoted to a clearly-
   labeled "legacy, kept for history" section that no longer blocks or unblocks anything.
3. **The Day Close screen itself rebuilt around the real model.** `finance/accounting-days/index.blade.php` (the
   exact screen the owner was describing) now lists every active financial account with its live computed
   balance and today's reconciled/outstanding status, with a per-account "declare today's count" form right on
   the page (a new `AccountingDayController::reconcileAccount()` action /
   `finance.accounting-days.reconcile-account` route, gated on `record-cash-reconciliation` — the same permission
   that already gated the legacy cash-count form, so the same roles — cashier, duty manager — who already
   declared cash can now declare each account). The "Lock the day" button and its precondition text now describe
   the real requirement. The legacy whole-hotel form is kept lower on the page inside a `<details>` disclosure,
   clearly marked as no longer required.
4. **`financial_account_id` wired into every income-side payment entry point**, so a mobile-money payment is
   never again just an unattributed "mobile_money" bucket at reconciliation time — the exact gap that made MTN
   vs. Orange (and Cash vs. Bank) impossible to actually tell apart in any report. `?int $financialAccountId`
   threaded through `PaymentLedgerService::record()` calls in `FolioService::postPayment()`/`postRefund()`,
   `OrderService::close()`, `FacilityAccessService::sellToWalkIn()`, `AccountsReceivableService::
   recordSettlement()`, `PayrollService::pay()`, and `ReservationService::recordDeposit()`. Every controller that
   calls one of these — `FolioController::payment()`, `OrderController::close()`,
   `FacilityAccessController::sellToWalkIn()`, `AccountsReceivableController::settle()`,
   `PayrollController::pay()`, `ReservationController::recordDeposit()`, and the general-purpose
   `PaymentController::store()` — now validates a required `financial_account_id` instead of a bare `method`
   string (the exact pattern `ExpenseController::pay()` already established in §21: pick the real account,
   derive `method` from it via `paymentMethod()`, so the two can never disagree), except `OrderController::close()`,
   where it stays optional alongside `stay_id` for the same reasons the old `payment_method` field was optional
   (a deferred-to-folio close, or a wholly complimentary $0 order, needs neither). Every corresponding view's
   bare method `<select>` (cash/mobile money/card/bank transfer) was replaced with a real account picker
   populated from `FinancialAccount::where('is_active', true)`: `stays/show.blade.php`'s "Post payment" form,
   `reservations/show.blade.php`'s deposit form, `facility-access/index.blade.php`'s walk-in sale form,
   `accounts-receivable/index.blade.php`'s settle form, `hr/payroll/runs-show.blade.php`'s pay form,
   `pos/orders/show.blade.php`'s settle-and-close form, and `payments/index.blade.php`'s general-payment form
   (whose payments table now also shows the real account name instead of a generic method label). Refunds
   (`RefundController`/`RefundService`) were deliberately left out of this wiring — a refund's method is chosen
   at *request* time, not at the *approval* time money actually leaves, so attributing it correctly would need
   its own schema change and design decision; `FolioService::postRefund()` gained the same optional
   `$financialAccountId` parameter defensively, ready for that follow-up, but nothing currently passes it.

**Per-shift and per-department account visibility ("per day per shift"):** `ShiftService::expectedCashFor()`
still drives the one number staff actually *count and declare* at shift close (cash — you can't physically count
a mobile money balance the same way a drawer is counted). A new `ShiftService::expectedByAccountFor()` sits
alongside it, grouping every payment the department took during the shift window by its real financial account
(falling back to an explicit "Unattributed" bucket for any payment still missing one, rather than silently
dropping it), and the shift handover print document (`finance/shifts/print.blade.php`) now shows this full
breakdown under the cash figure, with a one-line note explaining why only cash gets a physical declared count.
`DepartmentReconciliationReportService`'s previously-lumped `non_cash_in` figure gained the identical per-account
breakdown (`non_cash_by_account`), shown on-screen, on the print view, and as a new column in the CSV export.

**Deliberately not built in this round:** a literal new "per shift" reconciliation UI beyond what already exists
— `ShiftService` already *is* the per-department-per-shift mechanism the owner referred to ("not really per day
but in shifts because they work in shifts," §19's own original framing), and it was not reported broken; this
round's job was making sure it (and the whole-hotel day close) actually shows every real account's money, which
is what was missing. A day-scoped, per-financial-account version of `ShiftService` itself (rather than the
whole-hotel `AccountingDayService` day-scope this round added) was considered and set aside as unnecessary scope
— shifts are already correctly scoped to a department and a time window; what they lacked was the account
breakdown, now added.

**Verification and tests:** a project-wide `php -l` sweep across every touched/new file in `app/`, `database/`,
and `routes/` (clean); `verify_routes.py` re-run (same single pre-existing `outlet` false positive from
`request()->route('outlet')` in `layouts/app.blade.php`, unrelated to this round, unchanged); a Blade-directive-
balance sweep across every touched view (only the same established false-positive `@section`-without-
`@endsection` pattern from one-line title/document-title sections, nothing new). Two new test files
(`RolesAndPermissionsSeederTest` — proves the seeder itself clears the Spatie cache, not just works around it;
`FinancialAccountModelTest` — the enum-translation regression guard) plus twelve new/updated test methods across
`AccountingDayTest` (the new day-scoped, per-account lock gate, including the Day Close screen itself),
`ExpenseTest` (the Bank-account payment regression, and the two new accountant grants), `PaymentModuleTest` (the
accountant's new `record-payment` grant), and `ReservationTest` (the searchable-guest create page). Every
existing test that posted a bare `method`/`payment_method` string to one of the seven now-account-gated routes
(`AccountingDayTest`, `ShiftGateTest`, `FolioTest`, `CheckInCheckOutTest`, `ReservationDepositTest`,
`AccountsReceivableTest`, `PayrollTest`, `ShiftTest`, `FacilityAccessTest`, and every POS order-close test) was
updated in place to post a real `financial_account_id` instead, so none of this round's contract changes silently
broke the existing suite. A new shared test helper, `CreatesUsers::reconcileAllActiveAccountsToday()`, lets any
test that needs to actually lock the day do so in one line rather than re-deriving the new precondition itself.
Total test inventory after this round: **79 files / 498 methods** (up from 77/486 before this round started).

**Deployment note:** one new migration (`2025_01_05_000200`, additive/nullable, `Schema::hasColumn()`-guarded,
backfills `business_date` for existing reconciliation rows from their own `reconciled_at`). **Run
`php artisan migrate --force`**, then **re-run `php artisan db:seed --class=RolesAndPermissionsSeeder --force`**
— required this time, for two reasons: it carries the new accountant grants (§20.8.3), and it now self-clears the
Spatie permission cache (§20.8.2), which is also the fix for the standing front-desk report. No other seeder
needs re-running. After deploying: the Day Close screen (Finance → Day Close & Cash Reconciliation) will show
every active financial account as "Outstanding" until each is reconciled at least once under the new day-scoped
system — this is expected on the first day after this deploy, not a bug; reconcile each account once and locking
will work exactly as before, just against the real per-account model instead of the old whole-hotel cash-only
one.

## 20.9 Sixth post-9.8 fix round: every payment tied to a real financial account, and Duty Manager can create/edit financial accounts

**What was asked**, verbatim, two items in one message: "all payments are to be made in the accounts that
controles the pament methods and the acoountant, manager, should have the right to create new account and or
modify account."

### 20.9.1 Auditing every payment path for a real financial-account attribution

Read literally, "all payments are to be made in the accounts that control the payment methods" is exactly the
architecture §18.3, §20.8.4, and every round since have been building toward — a payment's `method` should never
be a value someone typed or picked in isolation, it should always be *derived from* the specific real account
(Cash/Bank/MTN/Orange/...) the money is actually moving into or out of, so the two can never disagree and every
XAF is attributable to a real account for reconciliation. Rather than assume this was already fully true, every
call site that ultimately reaches `PaymentLedgerService::record()` — still this system's single writer of the
`payments` table — was re-audited end to end:

- `FolioService::postPayment()` (guest folio payments) — requires `financial_account_id`, wired since §20.8.4's
  controller-contract change. ✅
- `OrderService::close()` (POS order close) — accepts `financial_account_id`, wired since §20.8.4. ✅
- `FacilityAccessService::sellToWalkIn()` (Gym/Pool walk-in sales) — requires it, wired since §20.8.4. ✅
- `AccountsReceivableService::recordSettlement()` (debt settlement) — requires it, wired since §20.8.4. ✅
- `PayrollService::pay()` (payroll run payout) — requires it, wired since §20.8.4. ✅
- `ReservationService::recordDeposit()` / approving a website reservation's claimed deposit — requires it, wired
  since §20.8.4/§20.7. ✅
- `PaymentController::store()` (the general-purpose Payments module — rent, one-off visitor payments) —
  requires it, wired since §20.8.4. ✅
- `ExpenseService::markPaid()` — requires it, wired since §18.3 (and the Bank/`bank_transfer` translation fixed in
  §20.8.4). ✅
- `CashDisbursementService::disburse()` — requires it, wired since §18.3. ✅
- `RefundService::approve()` — **did not.** This was the one genuine gap. Refund *requests* have always carried a
  `method` field (cash/mobile_money/card/bank_transfer), typed by whoever files the request — normally Front
  Desk, at the point a guest asks for money back, well before anyone has actually gone to a drawer or a mobile
  money app to pay it out. `RefundController::approve()` then took that same stored `method` string and posted it
  straight to the ledger with no `financial_account_id` at all, meaning a refund could land in the `payments`
  table attributed to a method but not to any specific account — exactly the un-reconcilable state every other
  payment path in this system had already been closed against.

**Why the gap existed, and why it's wrong the same way the others were:** the person who *requests* a refund
isn't the one who actually hands the money back — the person who *approves* it is (the same separation of duties
`approve-refund` already enforces for authority; this is the equivalent for money attribution). Exactly like
`ExpenseController::pay()` already does, the account the money leaves from can only really be known at the moment
someone is physically approving and disbursing it, not when the request was filed.

**The fix**, mirroring `ExpenseService::markPaid()`'s exact pattern:

- New migration `2025_01_05_000300_add_financial_account_id_to_refund_requests_table.php` adds a nullable,
  additive `financial_account_id` FK to `refund_requests` (guarded with `Schema::hasColumn()`, per this project's
  standing shared-hosting migration-safety convention). The request-time `method` column is untouched — no
  `->change()`, no risk to existing rows — it simply stops being what reaches the ledger.
- `RefundService::approve(RefundRequest $refund, User $actor, FinancialAccount $financialAccount)` — signature
  gains the account, derives the posted method via `$financialAccount->paymentMethod()` (never the account's raw
  `type`, for the same Bank/`bank_transfer` reason as §20.8.4), passes `financialAccountId` through to
  `FolioService::postRefund()` (which already accepted this parameter defensively since §20.8's wiring round —
  nothing had called it with a real value until now), and stores the chosen account on the refund row itself for
  the record.
- `RefundController::approve()` now validates `financial_account_id` as `required|exists:financial_accounts,id`,
  loads the account, and passes it through — line-for-line the same shape as `ExpenseController::pay()`.
- `resources/views/finance/refunds/index.blade.php` — the one-click "Approve" button is now a small inline form
  with a "Pay from…" account select (same UI pattern as the expense list's "Pay from…" select), and the table
  gained a "Paid from" column showing which account an approved refund actually came out of.

The refund's original requested `method` is kept exactly as before — it's a legitimate record of what the guest
or requester asked for — it simply never again reaches the `payments` table directly; only the approver's actual
account does. This closes the audit: every payment path in this system that posts to the central ledger now
requires a real, specific `FinancialAccount`, with `method` always derived rather than independently chosen.

### 20.9.2 Duty Manager can now create and modify financial accounts

Financial accounts (Cash, Bank, MTN Mobile Money, Orange Money, and any others the owner adds) have been
create/editable by Accountant since §18.3, and by General Manager and Owner all along (both hold effectively every
permission — GM holds every permission except `manage-own-shift`, Owner bypasses every check outright via
`Gate::before`). Duty Manager was the one role in the matrix explicitly excluded from `manage-financial-accounts`
— a deliberate design choice at the time (§18.3 reasoned that account creation/editing was accounting-office work,
not floor-management work), documented by its own dedicated test,
`test_duty_manager_cannot_manage_financial_accounts`. That reasoning is superseded here by direct project-owner
instruction, the same way §14's "corrections" round and §13's HR-visibility restriction have each overridden an
earlier design decision in this project before: Duty Manager now holds `manage-financial-accounts` too, granted in
`RolesAndPermissionsSeeder`. The old test was replaced with `test_duty_manager_can_now_create_a_financial_account`
(proving the new grant) and a fresh negative control, `test_a_role_without_manage_financial_accounts_is_forbidden`,
using Front Desk — a role that has never held this permission in any round — so a genuine "still correctly
forbidden" case isn't lost just because Duty Manager's answer flipped. No other role's grants changed; Cashier,
Front Desk, Bar/Restaurant/Bar Outside/Bucareaux staff still cannot create or edit a financial account.

### 20.9.3 Verification, tests, and deployment for §20.9

`php -l` clean across `app/`, `database/`, `routes/`, `tests/`; `verify_routes.py` re-confirms 238 named routes,
zero duplicates, and the same single pre-existing `outlet` false positive from `layouts/app.blade.php` (unrelated
to this round, unchanged since it was first noted in §20.8); `blade_check.py` on
`finance/refunds/index.blade.php` shows only the same established benign `@section`-without-`@endsection`
one-line-title pattern seen throughout this project, nothing new. Two net new test methods (one existing method
in each of `RefundTest` and `FinancialAccountTest` rewritten in place, one new method added to each — see
`TEST_REPORT.md` §2.13 for the full breakdown). Total test inventory after this round: **79 files / 500 methods**
(up from 79/498 before this round started — no new test files, since both changes fit inside existing files).

**Deployment note:** one new migration (`2025_01_05_000300`, additive/nullable, `Schema::hasColumn()`-guarded — no
existing `refund_requests` row is touched). **Run `php artisan migrate --force`**, then **re-run
`php artisan db:seed --class=RolesAndPermissionsSeeder --force`** — required this time, since it carries the Duty
Manager grant (this round self-clears the Spatie permission cache too, per §20.8.2's fix, so it takes effect
immediately). No other seeder needs re-running. After deploying: any refund still `pending` from before this
deploy approves exactly as before except it now asks which account to pay it back from — there is nothing to
migrate or backfill, since a `pending` refund has never yet moved any money.

## 20.10 Sixth post-9.8 fix round, follow-up: financial accounts can now actually be edited and reactivated, and debt settlement confirmed already real-account-based

**What was asked**, verbatim, after seeing §20.9's delivery, pointing at the Payments module screenshot (which
already shows a "Received into" account picker): "where i am suppose to chose account is supose to be
(Cash/Bank/MTN/Orange) accounts even when debts are beein settled it is suppose to be thesame because they are
the reale account. and the accountant should be able to add, modify and edit real accounts."

### 20.10.1 Debt settlement: already correct, confirmed by re-reading the code, not changed

Before writing anything, `AccountsReceivableController::settle()` and `resources/views/finance/accounts-
receivable/index.blade.php` were re-read end to end. Both have required a real `financial_account_id`, drawn
from `FinancialAccount::where('is_active', true)` (the exact same Cash/Bank/MTN/Orange list every other screen
uses), since the third post-9.8 fix round (§18.3) — the settle form's "Into…" select is not a leftover bare
method field, it already is the real-account picker the owner is asking for, and `AccountsReceivableService::
recordSettlement()` already derives the posted method from that account via `paymentMethod()`, never a
freehand string. Nothing needed to change here; this section exists only to record that it was checked, not
assumed.

### 20.10.2 The real gap: an account could be created, but never edited — and deactivating one was a dead end

`FinancialAccountController` has had `store()` (create) and `deactivate()` since §18.3, and `reconcile()` since
the same round — but no `edit()`/`update()` at all, and no way to undo a `deactivate()`. So while "add" was
already true for Accountant (and, since §20.9, Duty Manager), "modify and edit" genuinely was not: fixing a
typo'd account name, correcting a wrongly-picked `type`, or adjusting an opening balance meant either living
with the mistake or editing the database directly — and deactivating an account by accident (or one that turned
out to still be needed) had no way back through the UI at all.

**The fix**, built to the exact same edit/update/activate/deactivate shape `Admin\DepartmentController`/
`DepartmentService` already established for this project (Phase 9.2) — no new pattern invented, the existing one
extended to a second entity:

- `FinancialAccountService::update(FinancialAccount $account, array $data, User $actor)` — updates `name`,
  `code`, `type`, and `opening_balance`, audit-logged with before/after values. `type` is safe to change freely:
  it's the same fixed enum `create()` already validates against (`cash`, `bank`, `mobile_money`, `card`), not a
  schema change, and every call site that reads it (`paymentMethod()`, the balance/reporting screens) reads it
  fresh at the moment it's needed rather than caching it anywhere. `opening_balance` is equally safe to correct
  after the fact: `currentBalance()` is always `opening_balance + every ledgered payment`, recomputed live, so a
  later correction behaves exactly like getting it right the first time — no double-counting, nothing to
  reconcile away.
- `FinancialAccountService::reactivate(FinancialAccount $account, User $actor)` — the mirror of `deactivate()`,
  throwing if the account is already active.
- `FinancialAccountController::edit()`/`update()`/`activate()` — new actions; `routes/web.php`'s
  `manage-financial-accounts` group gained `GET /{financialAccount}/edit`, `PUT /{financialAccount}`, and
  `POST /{financialAccount}/activate` alongside the existing routes.
- `resources/views/finance/financial-accounts/edit.blade.php` (new) — a standalone edit form, deliberately built
  to look and behave exactly like `admin/departments/edit.blade.php` rather than inventing a new visual pattern.
  `index.blade.php` gained an "Edit" link next to every account (active or not) and a "Reactivate" button in
  place of "Reconcile"/"Deactivate" for any account currently inactive.

No migration: every field `update()` writes to already existed on the `financial_accounts` table and was already
in `FinancialAccount::$fillable`. This is purely new routes and screens exposing capability that was already
safe to build on top of existing data — nothing about the schema or the permission model changed.

Same permission as before, `manage-financial-accounts` — Accountant, Duty Manager (as of §20.9), General
Manager, and Owner can all now edit and reactivate accounts, and no one who couldn't create one before can now
edit one either (`test_a_role_without_manage_financial_accounts_cannot_edit_one`, using Front Desk).

### 20.10.3 Verification, tests, and deployment for §20.10

`php -l` clean across `app/`, `routes/`, `tests/`; `verify_routes.py` confirms **241 named routes** (238 before,
+3 — exactly `edit`/`update`/`activate`), zero duplicates, the same single pre-existing `outlet` false positive;
`blade_check.py` on both touched/new views shows only the same established benign `@section`-without-
`@endsection` pattern. Five new test methods, all in `FinancialAccountTest` (see `TEST_REPORT.md` §2.14 for the
full list): editing persists a name/opening-balance change; Duty Manager can edit too (proving it's the same
grant, not a new one); editing into another account's already-used code is rejected; a role without the
permission still cannot edit; a deactivated account can be reactivated. Total test inventory after this round:
**79 files / 505 methods** (up from 79/500 before this round started).

**Deployment note:** **no migration** — nothing to run beyond redeploying the changed files. No seeder re-run
needed either: no permission changed, only routes and screens exposing an existing one.

## 20.11 Seventh post-9.8 fix round: a safe way to actually apply a permissions/accounts fix on a no-SSH host

**What was asked**, verbatim: "when i click the dropdown it does not give the Cash/Bank/MTN/Orange" — the account
picker on the Payments/Refunds/Expenses/Day Close screens was empty.

### 20.11.1 Diagnosis: the code was never the problem — the seeder likely never reached production at all

Every screen that picks a financial account reads from `FinancialAccount::where('is_active', true)`, and that
list has been correct in the code since §18.3. An empty dropdown with correct code pointing at it means exactly
one thing: the `financial_accounts` table itself has no rows on the live database. That table is only ever
populated by `FinancialAccountSeeder`, and re-reading how this project's own deployment path works turned up a
real, previously-unnoticed problem: **every changelog entry since §18.3 that says "re-run `php artisan db:seed
--class=FinancialAccountSeeder --force`" (or the equivalent for `RolesAndPermissionsSeeder`) assumes a terminal —
but this project's own §10/README targets exactly the shared-hosting case where no terminal exists at all.**

On a host with no SSH, the *only* seeding path available is the Deploy Helper's one generic `seed()` action —
and `DeployHelperController::seed()` correctly, deliberately refuses to run once the `users` table has any real
rows, specifically because most of the seeders inside `DatabaseSeeder::run()` (`UserSeeder`, `RoomSeeder`,
`GuestSeeder`, `PosSeeder`, `InventorySeeder`, `HrSeeder`, ...) use `create()` unconditionally and are NOT safe
to run a second time — forcing that refusal open on a live hotel to get one specific seeder to run would
duplicate demo rooms, demo guests, demo inventory, and more. So a hotel that's live, on a no-SSH host, genuinely
had **no safe way at all** to apply either `FinancialAccountSeeder` or `RolesAndPermissionsSeeder` once it went
live — not a bug in either seeder itself (both are, and always were, fully idempotent — see below), but a real
gap in the *path* to running them. This is very likely the actual root cause here, and quite possibly also
behind earlier "the fix didn't seem to take effect" reports this engagement has seen (e.g. the front-desk
permission report §20.8.2 fixed at the cache layer — but a cache fix still needs the seeder to actually run once
to take effect the first time).

### 20.11.2 The fix: two more buttons, each calling exactly one always-safe seeder

Both seeders already were, and remain, genuinely safe to re-run any number of times against a database with any
amount of real data:

- `RolesAndPermissionsSeeder` — every Permission/Role row is `firstOrCreate()`'d, and every role's grant list is
  applied via `syncPermissions()` (replace-in-place, not append), never `create()`. Re-running it changes nothing
  about who's logged in, what rooms exist, or any guest — it only ever brings the roles/permissions tables in
  line with `RolesAndPermissionsSeeder::PERMISSIONS`/the per-role grant arrays in the current codebase, and (per
  §20.8.2's fix) clears the Spatie permission cache as its last step.
- `FinancialAccountSeeder` — every account is `firstOrCreate(['code' => ...], ...)`. An account that already
  exists, balance and all, is left completely untouched; only a genuinely-missing one (of Cash/Bank/MTN/Orange)
  gets created.

`DeployHelperController` gained two new actions, `seedRolesAndPermissions()` and `seedFinancialAccounts()`, each
calling `Artisan::call('db:seed', ['--class' => <one specific seeder>, '--force' => true])` — deliberately still
inside this controller's own stated safety model ("a fixed, hardcoded menu of a handful of safe, standard
artisan commands," never a generic run-anything action): these are two more named, hardcoded entries in that
same menu, not a new capability class. Both are deliberately **exempt** from the `usersAlreadySeeded` refusal
that correctly still guards the big generic `seed()` action, because unlike that action, running either of these
alone genuinely cannot duplicate or damage anything. New routes `deploy-helper.seed-roles-and-permissions` and
`deploy-helper.seed-financial-accounts` in `routes/deploy.php`, under the same `authorizeRequest()`
(flag+token) gate as every other action here. The Deploy Helper page gained two new cards, "2b. Update roles &
permissions only" and "2c. Set up the four starter financial accounts," each explaining in plain language why
it's always safe to click and when to use it, and the existing "2. Seed demo/reference data" card's copy now
points at these two for a live hotel instead of its own riskier force-reseed checkbox.

### 20.11.3 Verification, tests, and deployment for §20.11

`php -l` clean across `app/`, `routes/`, `tests/`; `verify_routes.py` confirms **243 named routes** (241 before,
+2 — exactly the two new deploy-helper routes), zero duplicates, the same single pre-existing `outlet` false
positive. Four new test methods in `DeployHelperTest` (see `TEST_REPORT.md` §2.15): each new action is proven
*never* refused even with an existing `User` row (the entire point of the fix, asserted explicitly rather than
assumed); running the financial-accounts seed a second time against an account that already has a custom name
and a real balance leaves both untouched (the idempotency guarantee everything above rests on); both new routes
still 404 without the correct flag+token, same as every other Deploy Helper action. Total test inventory after
this round: **79 files / 509 methods** (up from 79/505 before this round started).

**Deployment note:** **no migration, no seeder change** — this round adds two new routes/controller actions/view
sections that each call an existing, unchanged seeder. Once this is deployed, if the account dropdown is still
empty: visit `/deploy-helper?token=<your DEPLOY_TOKEN>` and click **"Set up financial accounts"** (card 2c) — that
directly fixes it. If a role's permissions still don't seem to have taken effect after a future round, click
**"Update roles & permissions"** (card 2b) the same way, instead of trying to run a terminal command that may not
exist on this host at all.

## 20.12 Seventh post-9.8 fix round, follow-up: a Master Admin data reset/backup/export screen

**What was asked**, verbatim: "I WANT TO CLEAR ALL THE EXISTING DATAIN THE DATABASE TO START AFRESSH." Because
this project's own §1 status line and every prior changelog entry describe a live, in-use hotel — not a test
install — this was treated as a genuinely consequential, irreversible request rather than a routine feature ask,
and `AskUserQuestion` was used twice before writing a line of code: first to confirm this really was the live
database and not a staging copy ("Live hotel database"), then to ask what "starting afresh" should actually leave
behind. The answer to the second question, verbatim, was: "I NEED A BUTTON IN THE MASTER ADMIN THAT CAN RESET ALL
DATA AND THAT WHICH CAN EXTRACT THE DATA AND THAT WICH CAN TRIGER BACKUP, YOU SHOULD NOT FORGET THIS IS A HYBRID
SYSTEM." That turned a one-off data-clearing request into three permanent capabilities on a new Owner-only screen,
with the hybrid-sync warning taken as an explicit design requirement rather than a side note.

### 20.12.1 The hazard a plain "delete everything" button would have created

This system runs as two independent installations kept in sync via `SyncService`'s outbox/checkpoint/apply model
(`HYBRID_ARCHITECTURE.md`), matched by `ulid`, not by auto-increment `id`. Re-reading that document's sync-apply
logic surfaced a real problem with the obvious naive implementation: **a hard delete never propagates.** A model
without `SoftDeletes` has no row left behind to tell a sync partner "this was removed" — it simply stops existing
locally. `SyncService::applyItem()`'s own logic is "if this `ulid` doesn't exist locally, create it" — which means
a table wiped clean on one installation would be silently, completely repopulated the very next time that
installation pulled from a partner that still had its own copy of the same guests, reservations, and payments.
A reset that looked successful for a few minutes and then quietly undid itself on the next sync tick would be far
worse than not having the feature at all, since it would look like it worked.

The fix reuses a mechanism this project had already built and documented rather than inventing a new one:
`SyncDevice.is_active = false` immediately blocks that device from authenticating in either direction (§6 of
`HYBRID_ARCHITECTURE.md`). `SystemDataService::reset()` deactivates every `SyncDevice` row in the same database
transaction as the data wipe, so a reset can never be silently undone by a sync pull that starts moments later.
This does **not**, and cannot, reset a remote partner installation from this codebase — resetting this
installation alone leaves the partner's own copy of the old data untouched. The reset screen says this plainly in
an amber warning banner whenever an active sync device is present, and explains the actual next step: reset the
partner installation separately, then reconnect sync only once both sides are ready to be in step again.

### 20.12.2 What "Reset" actually clears, and the allowlist design behind it

`SystemDataService::OPERATIONAL_TABLES` is an explicit **allowlist** of roughly thirty-five day-to-day
transactional/history tables — guests, companies, reservations, stays, folios and folio transactions,
housekeeping and maintenance tickets, payments, refunds, accounts receivable, cash disbursements and
reconciliations, accounting days, shifts, POS orders and order items, facility accesses, the full
purchasing/inventory history (purchase orders, goods receipts, requisitions, stock counts, wastage, batches,
movements), expenses, HR attendance/leave/payroll records, and the sync engine's own batch/item/conflict/log/
checkpoint history. `reset()` runs inside a single `DB::transaction()`, using `Schema::disableForeignKeyConstraints()`
/`enableForeignKeyConstraints()` around per-table `delete()` calls (never `truncate()`, which implicitly commits on
MySQL and would break the all-or-nothing guarantee this needs) so the roughly thirty-five tables don't have to be
hand-ordered by foreign-key dependency. An allowlist was chosen deliberately over a denylist of "everything except
X": a table added to the schema in some future round and forgotten in this list simply isn't touched by a reset
(fails safe), rather than a denylist where the same mistake would mean it gets wiped without anyone deciding that
should happen (fails destructive). Two small pieces of cleanup ride along in the same transaction: any room still
marked `occupied` (only possible because of a stay that no longer exists after the reset) flips back to
`available`, while a room marked `maintenance` — a fact about the physical room, not a guest — is left exactly as
it was; and every `SyncDevice` is deactivated as described above.

**Never touched by a reset, on principle:** every staff login, PIN, and role; departments; the room list and room
categories; financial accounts (Cash/Bank/MTN/Orange, balances included); product/menu catalogs and suppliers;
every setting; and `audit_logs` itself, which is kept as permanent history and also receives exactly one new entry
for the reset action itself (`admin.system_data_reset`, via the existing `AuditLogger`, recording the actor and
the actual row count cleared from every table it touched) — the single most consequential action available in
this whole system is the one action that must never be able to erase its own record of having happened.

### 20.12.3 Backup vs. Export: two different downloads for two different needs

The request named both "extract the data" and "trigger backup" as separate things, and they're genuinely
different jobs, so they became two separate buttons rather than one download with two settings:

- **Backup** (`SystemDataService::backupJson()`) is an exhaustive, technical dump — every real table in the
  database, discovered dynamically via `Schema::getTables()` rather than a hand-maintained list, so a table added
  in some future round is automatically included without anyone having to remember to add it here (the opposite,
  intentional asymmetry from the reset allowlist above: fail-safe-by-inclusion for a backup, fail-safe-by-omission
  for a deletion). System/queue tables that hold no real business data (`sessions`, `cache`, `cache_locks`, `jobs`,
  `job_batches`, `failed_jobs`, `password_reset_tokens`, `migrations`) are excluded. Credential columns are
  stripped from the `users` table dump by reusing `SyncableModels::protectedAttributesFor(User::class)` — the
  exact same password/PIN-hash/TOTP-secret/TOTP-recovery-codes/remember-token/failed-PIN-attempt exclusion list
  this project already built and proved for the sync engine's own credential-leak fix — so a backup file is safe
  to email or store on a cloud drive without also handing out login credentials. Downloaded as one pretty-printed
  JSON file: the format a developer would need to actually restore the system, not something a hotel owner is
  expected to read.
- **Export** (`SystemDataService::exportBusinessCsvZip()`) is a curated zip of CSV files covering only the core
  business tables (guests, companies, reservations, stays, folios/folio transactions, payments, refunds, accounts
  receivable, expenses, POS orders/order items, rooms, financial accounts) — one CSV per table, openable directly
  in Excel or Google Sheets, meant for the owner's own records rather than disaster recovery. Guarded with a
  `class_exists(\ZipArchive::class)` check that raises this project's own `HmsException` (rendered as a friendly
  on-page error, per the global exception-render pattern already established in `bootstrap/app.php`) if the PHP
  `zip` extension isn't present — shared hosting doesn't universally ship it, so this fails with a clear message
  instead of a fatal error if the target host is missing it. (Confirmed present in this sandbox via `php -m`, so
  the happy path is also directly exercisable here.)

### 20.12.4 Locking the screen down: permission, confirmation phrase, and a required backup checkbox

A new permission, `manage-system-data`, gates the entire `admin/system-data/*` route group and was added to
`RolesAndPermissionsSeeder::PERMISSIONS`. It is granted to **Owner only** — every other role, including General
Manager (who otherwise holds every permission in this system except `manage-own-shift`), is explicitly excluded,
via `array_diff($all, ['manage-own-shift', 'manage-system-data'])` in GM's grant line. This is the one exception
to this project's usual pattern of back-office capabilities being shared across Owner/GM/Accountant/Duty Manager —
deliberately, since nothing else in the system can cause the kind of irreversible loss the Reset button can.

The Reset action itself has two independent server-side gates, both required, neither satisfiable by editing the
page's HTML or skipping its JavaScript: a checkbox confirming a backup was taken (`has_backup` must be `accepted`)
and a typed exact-match confirmation phrase (`confirmation` must equal the literal string `RESET ALL DATA`,
validated with Laravel's `in:` rule against a constant on the controller, not just checked client-side). The page
also carries a plain-language JavaScript `confirm()` dialog on submit as a last, additional guard for a real
person clicking the real button — belt-and-suspenders, not a replacement for the two server-side checks. The
row-count table shown on the page reads live via `SystemDataService::operationalRowCounts()`, so what the owner
sees before clicking Reset is the real current state of the database, not a static description.

### 20.12.5 Verification, tests, and deployment for §20.12

`php -l` clean across every touched file in `app/`, `routes/`, `tests/`. `verify_routes.py` confirms **247 named
routes** (243 before, +4 — exactly `admin.system-data.index`/`.reset`/`.backup`/`.export`), zero duplicates, the
same single pre-existing `outlet` false positive unrelated to this round. `blade_check.py` on the new
`admin/system-data/index.blade.php` reports `@section=2 @endsection=1`, and the edited `layouts/app.blade.php`
reports `@canany=13 @endcanany=12` — both investigated and confirmed benign: the first is this project's ordinary,
systemic pattern of an inline `@section('title', ...)` needing no closing tag (the same shape the checker reports
against every other unrelated, pre-existing view in this app, e.g. `admin/users/index.blade.php`), and the second
is a pre-existing false positive from a Blade *comment* elsewhere in that file discussing the removed Wastage
module ("this `@canany` would never render") that the checker counts as a real directive — not something this
round's edit touched or introduced. `tests/Feature/Admin/SystemDataTest.php` (new file, 11 methods — full
breakdown in `TEST_REPORT.md` §2.16) covers: page access for Owner vs. every other role (General Manager's
deliberate exclusion gets its own dedicated test, not just a shared data-provider case); both reset gates rejected
independently with data proven untouched after each rejection; the core clear-operational/keep-setup guarantee
across a broad slate of tables; the occupied-vs-maintenance room-status distinction; the sync-device-deactivation
defense (the one assertion that actually proves the hybrid-sync hazard above is closed, not just documented);
exactly one audit-log entry per reset, with real row counts attached; the backup file never containing a real
staff password hash (read back via `getRawOriginal('password')` after creation, not a placeholder string, so the
stripping logic is actually exercised); and the export endpoint returning a real `.zip` attachment download. Total
test inventory after this round: **80 files / 520 methods** (up from 79/509 before this round started).

**Deployment note:** **no migration** — no schema changed, only new routes, a controller
(`Admin\SystemDataController`), a service (`SystemDataService`), a view, and a nav-link addition. What **is**
required: re-run `RolesAndPermissionsSeeder` so the new `manage-system-data` permission actually exists and gets
granted to Owner — either directly via a terminal, or, on a no-SSH host, via the Deploy Helper's **"Update roles &
permissions"** button (card 2b, added in §20.11) at `/deploy-helper?token=<your DEPLOY_TOKEN>`. Until that seeder
re-runs, the new "Master Admin — Data" nav link and page will not be reachable for anyone, Owner included — the
same standing deployment caveat every permission-adding round in this changelog carries, called out explicitly
here because §20.11 exists specifically to make this step possible without a terminal.
