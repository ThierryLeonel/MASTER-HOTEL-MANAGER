# Test Report

## The one fact that governs this entire document

**No test in this project — not one, across all 9 phases — has ever been executed.** Every test file described
below was written, reviewed, and manually traced against the exact controller/service/model code it exercises,
following this project's established conventions (`Tests\Concerns\CreatesUsers`, `RefreshDatabase`, the
`seedRolesAndSettings()` helper). None of it has run against a real PHPUnit process, a real database, or real
autoloaded dependencies, because the sandbox this project was built in cannot get them:

```
composer install          → fails: repo.packagist.org returns a 403 policy denial through this
                              sandbox's egress proxy (an organizational policy, not a transient
                              network blip — re-confirmed at the start of every phase in this project)
apt-get update             → fails: cannot reach the Ubuntu package mirrors either
→ no vendor/ directory exists anywhere in this project
→ php artisan test         → fails immediately: vendor/autoload.php does not exist
→ php artisan migrate      → no MySQL/MariaDB server is reachable from this sandbox either
```

This is not a claim unique to this document — it's stated at the top of `SECURITY_AUDIT.md`, in `README.md` §7,
in every phase entry of `PHASE9_AUDIT_AND_PLAN.md`, and here again because this is the document whose entire job
is to make sure no reader mistakes "written" for "passing." **Run the commands in §4 yourself, on a machine or CI
runner with normal internet access, to get the first real pass/fail numbers this project has ever had.**

**Update — the first real execution has now happened, outside this document's `composer install`/PHPUnit sense,
and it already proved the point above.** A real deployment attempt (Laravel Cloud) ran `composer install` for
real and then genuinely booted the framework — and the boot crashed immediately with a real, application-breaking
routing bug (`routes/web.php`'s POS route group misused the group-level `where()`) that no amount of `php -l` or
manual review had ever caught, across every phase since it was introduced. It's fixed now — full writeup in
`PHASE9_AUDIT_AND_PLAN.md` §12. This is not the same thing as a passing PHPUnit run (the test suite itself still
hasn't executed), but it is this project's first real, external confirmation that "written and reviewed" and
"actually run" can disagree, exactly as this whole document has warned. Treat it as one data point, not a
clean bill of health — the very next deploy attempt is what actually confirms this specific fix, and the test
suite is still owed its first real run.

---

## 1. What "written and manually traced" actually means

Every test method in this suite was produced the same way: read the exact service/controller/model code it's
meant to exercise, write assertions against what that code actually does (not what it's supposed to do), and — for
anything non-trivial — hand-trace the execution path line by line against the test's own setup data. Three
categories of real, executed verification back this up, run after every single phase and re-run at the end of
this one:

- **`php -l`** (PHP's own lint checker) — catches syntax errors, and did catch at least one real bug in this
  project (`SyncRun.php`'s docblock accidentally self-closing on a literal `*/` inside a crontab example — see
  `PHASE9_AUDIT_AND_PLAN.md` §7's Phase 9.7.5 entry).
- **`verify_routes.py`** (a custom brace-depth-aware script, not a Laravel tool) — parses `routes/web.php` +
  `routes/sync.php` + `routes/deploy.php` for every named route and every `route(...)` call site across the whole
  codebase (controllers, views, tests), confirming no duplicate names and no reference to a route that doesn't
  exist. This has caught real authoring mistakes in past phases (a genuine duplicate route name in an early Phase
  4 draft — see README §7).
- **Manual cross-reference** — for anything a script can't check (does this test's factory data actually produce
  the state its assertions expect, does a relationship name really exist on the model being called), read the
  actual source side by side with the test and traced it by hand.

None of these three is a substitute for actually running the suite. They catch a different, narrower class of
mistake (a typo, a wrong route name, a factory that doesn't build what a test assumes) — not a genuine logic bug
in the code under test, which only a real PHPUnit run against real data can surface.

---

## 2. Current totals

```
Test files:     80
Test methods:   520
```

(Both counts are real, produced by `find tests -iname "*.php" | wc -l` and
`grep -rhoE "public function test_[a-zA-Z0-9_]+" tests --include="*.php" | wc -l` run directly against this
project's actual `tests/` tree after the seventh post-9.8 fix round follow-up (the Master Admin reset/backup/export
screen — `PHASE9_AUDIT_AND_PLAN.md` §20.12) — not carried forward by arithmetic from an earlier phase's count. The
prior round (§2.15) was 79 files / 509 methods; §2.16 below accounts for the +1 file / +11 methods added since.)

By directory:

```
tests/Feature/                5 files  (DeployHelperTest, SmokeTest, PrintableDocumentsTest, DashboardAuthorizationTest,
                                         MyPinTest — new the "corrections" fix round, §2.4)
tests/Feature/Admin/          6 files  (User/Department/Settings/RoomCategory admin, Sync dashboard,
                                         SystemData — new §2.16)
tests/Feature/Auth/           2 files  (LoginTest, LoginEnumerationTest — new this fix round, §2.2)
tests/Feature/Console/        5 files  (RegisterSyncDevice, BackfillSyncUlids, RunIntegrityCheck, SyncRun,
                                         CheckUserAccess — new §2.8)
tests/Feature/Dashboard/      1 file   (PendingApprovalsServiceTest)
tests/Feature/Finance/        8 files  (Expense, Refund, AccountingDay, CashDisbursement — new §2.8,
                                         Shift — new §2.8, ShiftGate, FinancialAccount,
                                         AccountsReceivable — all three new §2.9)
tests/Feature/FrontOffice/    12 files (Reservation, ReservationDeposit — new §2.5, CheckInCheckOut, Folio,
                                         AuditLog, Room/Stay/Maintenance Authorization, StayComplimentary,
                                         GuestSearch, PaymentModule, FacilityAccess — new §2.8)
tests/Feature/Hr/              4 files  (Employee, Attendance, Leave, Payroll)
tests/Feature/Inventory/       4 files  (PurchaseOrder, Requisition, StockCount, Wastage)
tests/Feature/Pos/             6 files  (Order, RecipeStockDeduction, FrontDeskPettySale, ComplimentaryOrder,
                                         OrderCrossOutlet, AdditionalOutlets — new §2.8)
tests/Feature/Public/          1 file   (WebsiteBookingTest — new §2.10)
tests/Feature/Reporting/       9 files  (Financial, Operations, Inventory, Hr, WeeklyOwnerSummary,
                                         PaymentSummary, ExpenseReport, Management,
                                         DepartmentReconciliation — new §2.8)
tests/Feature/Sync/            1 file   (SyncTransportTest)
tests/Unit/                    3 files  (SettingsServiceTest, RolesAndPermissionsSeederTest — new §2.12,
                                         FinancialAccountModelTest — new §2.12)
tests/Unit/Hr/                 1 file   (PayrollDeductionServiceTest)
tests/Unit/Http/               1 file   (ExportsCsvTest — new this phase, §3.6 security fix)
tests/Unit/Integrity/          1 file   (DataIntegrityCheckerServiceTest)
tests/Unit/Inventory/          1 file   (StockServiceTest)
tests/Unit/Sync/               7 files  (HasSyncUlid, DeviceAuth, SyncRegistryModels, Syncable Models,
                                         SyncService, SyncConflictResolver, SyncRunner)
```

For a full one-line-per-file description of what each file actually covers, see README §7 — that inventory is
kept current there rather than duplicated here, to avoid the two documents drifting apart. This document's job is
the honest totals and the run commands, not a second copy of the same list.

### 2.1 What's new since the last full count (Phase 9.7.6, 304 files project-wide)

This phase (9.8) added or changed:
- `tests/Feature/Pos/ComplimentaryOrderTest.php` (6 methods) and `tests/Feature/FrontOffice/StayComplimentaryTest.php`
  (9 methods) — the complimentary items/stays feature (out-of-band addition, `PHASE9_AUDIT_AND_PLAN.md` §9).
- `tests/Feature/Pos/OrderCrossOutletTest.php` (6 methods, new file) and `tests/Unit/Http/ExportsCsvTest.php`
  (3 methods, new file) — regression coverage for two of the security fixes below.
- 2 new methods added to the existing `tests/Unit/Sync/SyncServiceTest.php`, and 1 new method added to the
  existing `tests/Unit/Sync/SyncConflictResolverTest.php` — regression coverage for the sync-credential-protection
  fix.

All of the above are **new, written test code from this session, none of it executed** — same standing limitation
as every other test in the project.

### 2.2 What's new since Phase 9.8 (the post-9.8 fix round, `PHASE9_AUDIT_AND_PLAN.md` §11)

A follow-up task fixing six specific, pre-identified flaws (concurrency, PO line ownership, POS outlet ownership,
an SQLite-incompatible migration, login enumeration, and the POS lifecycle) added or changed:

- `tests/Feature/Auth/LoginEnumerationTest.php` (7 methods, new file) — regression coverage for the login
  enumeration fix.
- `tests/Feature/Pos/OrderCrossOutletTest.php` (+5 methods) — outlet-ownership coverage for tables and menu items,
  at both the request-validation and service layers.
- `tests/Feature/Pos/OrderTest.php` (+1 method: `test_a_draft_order_cannot_be_closed`) and 7 pre-existing methods
  across `OrderTest`, `ComplimentaryOrderTest`, `RecipeStockDeductionTest`, and `FrontDeskPettySaleTest` updated
  (not counted as new) to confirm an order before closing it, so the POS-lifecycle fix doesn't silently change
  what they test.
- `tests/Feature/Inventory/PurchaseOrderTest.php` (+1 method: `test_a_line_from_one_purchase_order_cannot_be_received_through_another`).
- `tests/Feature/Auth/LoginTest.php` (1 existing method's assertions updated, not counted as new) — the
  inactive-user case no longer produces a `username` session error, per the enumeration fix.

Net: +1 file, +14 methods (7 + 5 + 1 + 1) over the Phase 9.8 totals. Same standing limitation as everything else in
this document: **written and manually traced, not executed.**

### 2.3 What's new since the post-9.8 fix round (the HR visibility restriction, `PHASE9_AUDIT_AND_PLAN.md` §13)

A project-owner-directed permission change, not a bug fix: HR & Payroll self-service (`clock-own-attendance`,
`request-leave`, plus Duty Manager's `record-attendance`/`approve-leave`) was removed from every role except
Owner, General Manager, and Accountant. No new test files — both existing HR test files were rewritten in place:

- `tests/Feature/Hr/AttendanceTest.php`: 7 → 10 methods. Every test that previously exercised self-service
  attendance as Bar Staff or Duty Manager now uses Accountant or General Manager instead (the roles that still
  hold these permissions); added `test_a_role_without_hr_access_cannot_clock_in`,
  `test_duty_manager_can_no_longer_manually_record_attendance`, and
  `test_a_role_without_hr_access_cannot_view_the_attendance_page` to cover the newly-forbidden paths.
- `tests/Feature/Hr/LeaveTest.php`: 10 → 12 methods. Every test that previously used Front Desk or Duty Manager
  now uses Accountant or General Manager instead; added `test_a_role_without_hr_access_cannot_request_leave` and
  `test_duty_manager_can_no_longer_approve_leave`.

Net: +0 files, +5 methods (3 + 2) over the post-9.8 fix-round totals. Same standing limitation as everything else
in this document: **written and manually traced, not executed** — and this round has a sharper edge than most,
since the whole point of the change is a permission being *removed*; if the seeder change and the test rewrite
ever drifted out of sync, the failure mode is a false "still forbidden" pass, which is exactly why every rewritten
test above asserts a concrete `403`/`assertForbidden()` rather than just asserting the nav link is hidden.

### 2.4 What's new since the HR visibility restriction (the large "corrections" fix round, `PHASE9_AUDIT_AND_PLAN.md` §14)

A large, user-directed batch of ~16 feature corrections and additions (room status, check-in guest search, VAT
removal, self-service PIN, click-to-cart POS, branding, folio/receipt redesign, a new Payments module, wastage
hiding, and general UI/responsiveness work — see §14 for the full list). Added or changed:

- `tests/Feature/Inventory/WastageTest.php` — fully rewritten (4 → 5 methods) from a permission-matrix test into a
  "the module is fully hidden" test: Owner/General Manager/Bar Staff/Restaurant Staff are all now proven forbidden
  from every wastage route, plus one test calling `WastageService` directly (bypassing HTTP/permissions) to prove
  the underlying mechanism and data are untouched, only the permission grants changed.
- `tests/Feature/FrontOffice/RoomAuthorizationTest.php` (+3 methods) — the new `update-room-status` permission:
  Front Desk can set a non-occupied room's status, a role without the permission cannot, and an occupied room's
  status cannot be changed directly (must go through an actual check-out).
- `tests/Feature/FrontOffice/CheckInCheckOutTest.php` (+2 methods) — walk-in check-in creating a new guest inline
  when no existing guest was selected, and the validation error when neither an existing guest nor a new name is
  given.
- `tests/Feature/FrontOffice/GuestSearchTest.php` (4 methods, new file) — the new guest search JSON endpoint: name
  match, phone match, an empty query returning no results (never the whole guest list), and the permission gate.
- `tests/Feature/MyPinTest.php` (5 methods, new file) — self-service PIN change: correct current PIN succeeds,
  wrong current PIN is rejected, confirmation mismatch is rejected, a password-login account is refused, and a
  proof that the existing admin PIN-reset route was already Owner/General-Manager-only before this round (no code
  change was needed for that half of the request).
- `tests/Feature/FrontOffice/PaymentModuleTest.php` (3 methods, new file) — the new general-purpose Payments
  module: a payment not tied to any guest, a payment tied to an existing guest, and the permission gate.
- `tests/Feature/Pos/OrderTest.php` (+1 method) — proves a nonzero `tax_rate_percent` setting (deliberately left
  in Settings as a dormant field) has no effect on an order's totals now that VAT has been switched off everywhere.

Net: +3 files, +19 methods (0 + 3 + 2 + 4 + 5 + 3 + 1 = 18, plus one pre-existing `RoomAuthorizationTest` method
count correction) over the HR-visibility-restriction totals. Same standing limitation as everything else in this
document: **written and manually traced, not executed.** This round also touched a large number of Blade view
files (room board, check-in, POS orders, folio/receipt, settings, the main app layout) that carry no dedicated
test coverage of their own — their correctness rests on the controller/service-level tests above plus the
`php -l` and Blade-directive-balance checks described in `PHASE9_AUDIT_AND_PLAN.md` §14, not on a browser-level
UI test, since this sandbox has no way to execute JavaScript or take a screenshot to verify one.

### 2.5 What's new since the "corrections" round (real lodging charges, deposits as payments, automatic checkout — `PHASE9_AUDIT_AND_PLAN.md` §15)

The user's own words: "let the total cost be in the folio... the advance on reservation are also payments...
make some modifications following global hotel (hospitality) standards." Added or changed:

- `tests/Feature/FrontOffice/CheckInCheckOutTest.php` (+5 methods) —
  `test_checking_in_a_confirmed_reservation_creates_stay_and_open_folio_and_occupies_room` extended with
  assertions that the folio balance now equals `rate × expectedNights()` and `nights_charged` is set correctly;
  `test_check_in_applies_a_previously_recorded_deposit_as_a_folio_credit_without_double_ledgering` (new) is the
  critical end-to-end proof for the whole round — records a deposit through `ReservationService::recordDeposit()`,
  confirms exactly one row lands in `payments`, checks the reservation in, and confirms the folio balance is
  `(rate × nights) − deposit` while `payments` still has exactly one row (the credit at check-in did not ledger it
  again); `test_walk_in_guest_can_be_checked_in_directly_without_a_reservation` extended to assert the walk-in's
  full stay cost is posted; `test_checkout_automatically_charges_for_a_genuine_extension_beyond_the_original_stay`
  (new) — a stay checked in 3 days ago for a quoted 2 nights (already charged) checks out today, and checkout
  posts exactly the 1 extra night, nothing more; `test_checkout_does_not_auto_credit_an_early_departure` (new) — a
  stay charged upfront for 4 nights checks out after 1, and checkout posts no charge and no credit, leaving the
  balance untouched. The two existing checkout/Accounts-Receivable tests
  (`test_checkout_with_unpaid_balance_creates_accounts_receivable_and_still_succeeds`,
  `test_checkout_with_fully_settled_folio_creates_no_accounts_receivable`) now set `nights_charged` explicitly on
  their directly-factory-created stays, since checkout's new automatic-extension logic would otherwise (correctly)
  treat their un-tracked existing balance as an unbilled stay and charge it again.
- `tests/Feature/FrontOffice/ReservationDepositTest.php` (5 methods, new file) — a deposit recorded through the
  new route lands in the central `payments` ledger with the correct amount/method/`source_module`/`guest_id` and
  increments `Reservation::deposit_paid`; a deposit can be recorded in more than one instalment and the running
  total is exact (`bcadd`, not float addition); a deposit cannot be recorded on a cancelled reservation; a role
  without `post-folio-payment` is forbidden; a non-positive amount is rejected by validation.
- `tests/Feature/FrontOffice/StayComplimentaryTest.php` — the two checkout tests were renamed and updated for the
  removed `post_final_night` request field (checkout is now unconditional/automatic — a complimentary stay is
  still never charged, a normal one now charges its full un-tracked stay automatically); +1 new method,
  `test_marking_complimentary_after_check_in_reverses_the_already_posted_room_charge` — checks a guest in for real
  (posting a real 60,000 XAF room charge), marks the stay complimentary, and confirms the folio balance returns to
  0.00 via a genuine reversing `adjustment` transaction (the original charge is never edited or deleted).
- `tests/Feature/PrintableDocumentsTest.php` and `tests/Feature/FrontOffice/CheckInCheckOutTest.php`/
  `StayComplimentaryTest.php` — every remaining call to `CheckOutService::checkOut()`/`route('stays.checkout', ...)`
  updated for the removed third parameter/`post_final_night` field.

Net: +1 file (`ReservationDepositTest.php`, 5 new methods), +4 new methods across the existing files touched
(3 in `CheckInCheckOutTest.php`, 1 in `StayComplimentaryTest.php` — two of that file's existing methods were
renamed and updated in place rather than counted as new, and several other existing methods gained assertions
without becoming new methods) since §2.4's totals — 9 new test methods in total this round. Same standing
limitation as everything else in this document:
**written and manually traced, not executed.** This round also touched four Blade view files (`stays/show.blade.php`,
`reservations/show.blade.php`, `folio-print.blade.php`, `_folio-receipt-copy.blade.php`) — checked with `php -l`
(N/A for Blade, so directive-balance counting was used instead) and manual read-through, not a browser test.

### 2.6 What's new since §15 (removing the room's separate "clean/dirty" housekeeping status — `PHASE9_AUDIT_AND_PLAN.md` §15.7)

No new test file and no new test methods — this was a pure removal. Two existing files were updated because they
directly referenced the now-dropped `rooms.housekeeping_status` column:

- `tests/Feature/FrontOffice/CheckInCheckOutTest.php` —
  `test_checkout_with_unpaid_balance_creates_accounts_receivable_and_still_succeeds` no longer asserts
  `$room->fresh()->housekeeping_status === 'dirty'` (that column no longer exists); its other assertions
  (folio/stay/room `status`, the Accounts Receivable record) are untouched.
- `tests/Feature/Console/BackfillSyncUlidsTest.php` — its raw `DB::table('rooms')->insert()` (deliberately
  bypassing Eloquent to simulate a pre-Phase-9.7.1 "legacy" row — see the file's own docblock) had
  `'housekeeping_status' => 'clean'` removed from the column list it inserts, since that column no longer exists
  to insert into.

Totals are unchanged from §2.5 (67 files / 408 methods) — this round removed code and assertions, it didn't add or
delete any test methods. Same standing limitation as everything else in this document: **written and manually
traced, not executed.**

### 2.7 What's new since §2.6 (printable-receipt redesign — `PHASE9_AUDIT_AND_PLAN.md` §16)

No test changes at all. This round redesigned two Blade view files (removed the folio receipt's redundant third
header block; gave the bar/restaurant/front-desk POS receipt a dedicated narrow thermal-printer layout instead of
the shared A4 one) and investigated, but made no code change for, a reported front-desk permission issue that
traced to a live-deployment data/seeding question rather than a code defect (§16.1). The two existing tests that
touch the POS receipt route (`tests/Feature/PrintableDocumentsTest.php`'s
`test_pos_receipt_is_reachable_only_by_the_matching_outlet_role` and `tests/Feature/Pos/OrderCrossOutletTest.php`)
only assert HTTP status codes, not the receipt's HTML content, so neither needed updating and both still hold.
Verified with `php -l`, Blade-directive-balance counts on every touched view, and `verify_routes.py` (no route
names changed). Totals remain 67 files / 408 methods.

### 2.8 What's new since §2.7 (the second post-9.8 fix round — outlets, cash disbursement, shifts, Gym & Pool, department reconciliation, printable reports — `PHASE9_AUDIT_AND_PLAN.md` §17 (§17.1-17.9))

Six new test files, 22 new test methods:

- `tests/Feature/Pos/AdditionalOutletsTest.php` (3 methods, new file) — Bucareaux staff can run a full order
  lifecycle and close it with cash, attributed to the correct department; Bar Outside staff cannot operate the
  main Bar outlet and vice versa (the same outlet-ownership guard `OrderCrossOutletTest` already covers for
  Bar/Restaurant/Front Desk, exercised here for the two new outlets).
- `tests/Feature/Finance/CashDisbursementTest.php` (5 methods, new file) — a manager's ticket can be disbursed
  against by a cashier (and the ticket is marked used); a disbursement cannot exceed its ticket's authorized
  amount; a ticket-less disbursement without a named authorizer is rejected; a ticket-less disbursement with a
  named authorizer succeeds; only an open (not cancelled) ticket can be disbursed against.
- `tests/Feature/Finance/ShiftTest.php` (4 methods, new file) — opening a shift, taking a cash payment, and
  closing it with the exact declared amount yields zero variance; a shortfall yields a negative variance; a
  department cannot have two open shifts at once; `manage-own-shift` does not itself restrict which department a
  shift is opened for (documented, not a gap — see the test's own comment).
- `tests/Feature/FrontOffice/FacilityAccessTest.php` (4 methods, new file) — free access is granted to a
  checked-in guest's active stay and posts no payment; free access is refused for a stay that isn't active; a
  walk-in's paid access posts a payment attributed to the Front Office department; a rate belonging to a
  different facility is rejected.
- `tests/Feature/Reporting/DepartmentReconciliationReportTest.php` (3 methods, new file) — a closed Bar order's
  total appears as both Bar "sales" and Bar "cash in" for the day; the print and CSV variants are reachable; a
  cashier (who holds `view-shift-reports` but not `view-financial-reports`) can still reach the report.
- `tests/Feature/Console/CheckUserAccessTest.php` (3 methods, new file) — the `hms:check-access` diagnostic exits
  0 for a user who holds the permission, and exits 1 both for an unknown email and for a role that doesn't hold
  it.

No existing test needed updating: the eight report print views added this round (§21) reuse the same controller
data the already-tested screen views already reuse, and every route-permission table this round changed
(`RolesAndPermissionsSeeder`) is additive — every permission an existing test's user already held is still held.

New totals: **73 files / 430 methods** (67 + 6 files, 408 + 22 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic). Same standing limitation as
everything else in this document: **written and manually traced against the actual current source of every
service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory and no
reachable database. Two things this round could specifically not verify by execution: whether `Payment.method`
being `nullable` in the schema but required by every ledger-writing call site actually holds under SQLite's
CHECK-constraint enforcement in the test database (reviewed by inspection only), and whether the raw `ALTER TABLE
... MODIFY COLUMN` statements in `2025_01_02_001200_add_bar_outside_and_bucareaux_outlets.php` succeed against a
real MySQL/MariaDB server — both flagged for the first real `php artisan test`/`php artisan migrate` run to
confirm, exactly as §4.1 already flags for the equivalent front_desk-outlet migration from an earlier round.

### 2.9 What's new since §2.8 (the third post-9.8 fix round — mandatory shift gating, reconciled financial accounts, client debt — `PHASE9_AUDIT_AND_PLAN.md` §18-19)

Three new test files (25 methods) plus 2 new methods added to an existing file — 27 new methods from direct
authoring — and 22 existing files across `tests/Feature/Finance`, `tests/Feature/FrontOffice`,
`tests/Feature/Pos`, and `tests/Feature/Reporting` updated (no new methods in those 22 — each needed either a
`CreatesUsers::openShiftFor('<CODE>')` call so its pre-existing assertions still fire correctly under the new
shift gate, or a `method` string → `financial_account_id`/`FinancialAccount` conversion for the new required
field on `ExpenseController::pay()`/`CashDisbursementController::store()`/`ExpenseService::markPaid()`). Full
file-by-file list of what changed and why: `PHASE9_AUDIT_AND_PLAN.md` §18.4; one-line descriptions of every test
file: README §7.

- `tests/Feature/Finance/ShiftGateTest.php` (10 methods, new file) — the dedicated negative-path coverage no
  other file provides: a missing shift blocks a folio payment, blocks a check-in, and blocks opening a POS order
  for each of the five gated departments in turn; the Front Office folio-payment path also gets a positive-path
  confirmation once its shift is open; a shift open for Bar does not also unblock Restaurant (department
  isolation, not "any shift open anywhere"); checkout and an expense payout both succeed with zero shifts open
  anywhere in the system, confirming the two deliberately-ungated paths actually stay ungated.
- `tests/Feature/Finance/FinancialAccountTest.php` (6 methods, new file) — an accountant can create a financial
  account, a Duty Manager cannot (403); a duplicate account code (exact-case match, so the assertion holds
  regardless of the test database's string collation) is rejected by the `unique` validation rule;
  `currentBalance()` correctly reflects `opening_balance` plus a real ledgered cash payment, proving the
  auto-attribution from `method` to the seeded Cash account works end to end; reconciling computes `variance` as
  `declared - expected`; an already-inactive account cannot be deactivated a second time.
- `tests/Feature/Finance/AccountsReceivableTest.php` (9 methods, new file) — a partial settlement reduces
  `amount_outstanding` and leaves status `partially_paid`, posting an `in`-direction payment with
  `source_module = 'ar_settlement'`; a full settlement flips status to `paid`; a settlement exceeding the
  outstanding balance is rejected and posts nothing; an already-paid debt cannot be settled again; Bar Staff
  (lacking `manage-accounts-receivable`) is forbidden; settling a debt succeeds with zero shifts open anywhere,
  confirming `ar_settlement` really is excluded from the shift-gate's department auto-derivation; a company's
  `totalOutstanding()` correctly sums two open/partially-paid receivables while excluding a fully-paid one and a
  different company's debt entirely; Front Desk can register a new company, Bar Staff (lacking `manage-guests`)
  cannot.
- `tests/Feature/FrontOffice/CheckInCheckOutTest.php` (+2 methods) — a walk-in check-in can be billed to a
  company (`stays.company_id` is set); an unpaid checkout for a company-billed stay tags the resulting
  `AccountsReceivable` row with that company and the company's `totalOutstanding()` reflects it — the full
  check-in-to-checkout path for §19.2's company billing, traced end to end rather than each half tested in
  isolation.

Existing files updated (no new methods; behavior-preserving under the new gate/required-field, verified by
tracing the exact order of checks each controller/service now performs): `ShiftTest` (rewritten for the
automatic-department behavior, §18.2), `AdditionalOutletsTest`, `DepartmentReconciliationReportTest`, `OrderTest`,
`OrderCrossOutletTest`, `ComplimentaryOrderTest`, `FrontDeskPettySaleTest`, `RecipeStockDeductionTest`,
`PrintableDocumentsTest`, `FolioTest`, `PaymentModuleTest`, `ReservationDepositTest`, `StayComplimentaryTest`,
`FacilityAccessTest`, `AuditLogTest`, `AccountingDayTest`, `CashDisbursementTest`, `ExpenseTest`,
`FinancialReportTest`, `PaymentSummaryReportTest`, `OperationsReportTest`.

New totals: **76 files / 458 methods** (73 + 3 files, 430 + 28 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic; the 27 methods added by direct
authoring plus a net +1 from the file-count delta pulling in `tests/Concerns/CreatesUsers.php`'s own
`openShiftFor()`/`seedFinancialAccounts()`/`financialAccount()` helper additions, which the file-count command
counts but the method-count command correctly does not, since they aren't `test_*` methods). Same standing
limitation as everything else in this document: **written and manually traced against the actual current source
of every service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory
and no reachable database. One thing this round could specifically not verify by execution: whether SQLite's
default `BINARY` string collation (vs. the production MySQL target's typical `utf8mb4_unicode_ci`, which is
case-insensitive) could ever make `FinancialAccountTest`'s duplicate-code assertion behave differently between the
two — addressed by deliberately testing with an exact-case duplicate (`'CASH'` against the already-seeded
`'CASH'`), which is guaranteed to collide under either collation, rather than relying on case-insensitivity that
only one of the two databases actually provides.

### 2.10 What's new since §2.9 (the fourth post-9.8 fix round — shift scope-down, a payroll double-payment audit fix, front-desk reservation approval, website booking — `PHASE9_AUDIT_AND_PLAN.md` §20)

One new test method in `tests/Feature/Hr/PayrollTest.php` — `test_an_already_paid_run_cannot_be_paid_again_and_posts_no_duplicate_payment()` — proving the concurrency gap found during this round's "money not double recorded" audit is actually closed: two near-simultaneous "pay this run" calls result in exactly one `Payment` row and the run stays `paid`, not double-paid.

Two new test methods in `tests/Feature/Finance/ShiftTest.php` — confirming Accountant/General Manager/Duty Manager can no longer open a shift (403), and that all three can still reach shift reports (`view-shift-reports` untouched).

`tests/Feature/FrontOffice/ReservationTest.php` — the now-incorrect `test_front_desk_lacks_approval_permission_and_is_forbidden()` removed and replaced with three methods: front desk can approve a pending reservation, front desk can reject one with a reason, and a role genuinely without `approve-reservation` (Bar Staff) is still forbidden — net +2 methods.

`tests/Feature/Dashboard/PendingApprovalsServiceTest.php` — one existing method corrected for the same permission change (front desk now sees the reservations-approval dashboard item; Bar Staff is the new negative control) — no new method.

- `tests/Feature/Public/WebsiteBookingTest.php` (17 methods, new file, new `tests/Feature/Public/` directory) — the
  public booking form renders without authentication; a submission creates a `pending`/`source = website`
  reservation attributed to the seeded `WebsiteBookingSeeder` system user; a returning guest is matched by phone
  rather than duplicated; the success page shows the reservation reference; a deposit screenshot/claimed
  amount/reference is stored on the `public` disk and surfaced without ever creating a `payments` row (asserted
  directly — `assertDatabaseCount('payments', 0)`); a non-image/non-PDF proof file is rejected; every required
  field is validated (via a data provider); an inactive or nonexistent room category is rejected; check-out must
  be after check-in; a past check-in date is rejected; the honeypot field silently no-ops a spam submission
  (redirects to success, creates no reservation or guest); the `throttle:10,1` rate limit trips on the 11th
  submission within a minute; staff can approve or reject a website-sourced reservation exactly like any other
  pending one; the reservations list shows a "Website" badge; and the seeded system user itself holds no role and
  `is_active = false`.

New totals: **77 files / 480 methods** (76 + 1 file, 458 + 22 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic; the 22 methods are 17 new in
`WebsiteBookingTest` + 1 in `PayrollTest` + 2 in `ShiftTest` + 2 net in `ReservationTest`). Same standing
limitation as everything else in this document: **written and manually traced against the actual current source
of every service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory
and no reachable database. One thing this round could specifically not verify by execution: `UploadedFile::fake()`
against `Storage::fake('public')` is Laravel's own well-established testing pattern (already used elsewhere in
this suite for the hotel-logo upload), but whether the deposit-proof file genuinely round-trips through a real
`multipart/form-data` POST to `WebsiteBookingController::store()` on a real PHP/MySQL server has not been
confirmed by an actual HTTP request in this sandbox.

### 2.11 What's new since §2.10 (follow-up: public booking design fix and claimed deposits posted to the ledger at approval — `PHASE9_AUDIT_AND_PLAN.md` §20.7)

No new test files. Six new methods in two existing files:

- `tests/Feature/FrontOffice/ReservationTest.php` (+1) — `test_approving_an_already_confirmed_reservation_throws_instead_of_silently_succeeding()`: the generic (non-website) proof that `ReservationService::approve()`'s new `lockForUpdate()`-inside-transaction guard actually works — approving a reservation that's already `confirmed` throws `HmsException` and leaves it untouched, rather than silently re-approving it.
- `tests/Feature/Public/WebsiteBookingTest.php` (+5) — `test_approving_a_website_reservation_with_a_claimed_deposit_and_method_posts_it_to_the_ledger()` (submitting the request posts zero payments; approving posts exactly one, with the right amount, method, `source_module`, and guest attribution, and `deposit_paid` reflects it); `test_approving_a_website_reservation_with_a_deposit_claim_requires_an_open_front_office_shift()` (departments seeded but no shift opened — approval throws, the reservation stays `pending`, zero payments posted); `test_a_website_reservation_with_no_deposit_claim_can_still_be_approved_without_an_open_shift()` (regression guard — a reservation with no claimed deposit is completely unaffected); `test_a_claimed_amount_with_no_claimed_method_is_never_auto_posted_even_on_approval()` (a state the public form's own validation prevents, constructed directly against the service to prove it's never auto-posted even so — no guessed payment method, ever); `test_approving_an_already_approved_reservation_throws_and_never_posts_the_deposit_twice()` — the deposit-specific version of the same concurrency guard, mirroring `PayrollTest`'s established "call the service method twice directly, assert the second throws and nothing duplicates" pattern from §20.2.

New totals: **77 files / 486 methods** (77 + 0 files, 480 + 6 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic). Same standing limitation as
everything else in this document: **written and manually traced against the actual current source of every
service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory and no
reachable database. This round's concurrency fix specifically cannot be verified by execution here for the same
reason `PayrollService::pay()`'s equivalent fix couldn't in §20.2: proving two requests race for real needs an
actual concurrent load against a real database connection, not a single-threaded test process calling a service
method twice in sequence (which is what the new test does — it proves the *guard* rejects a second call, not that
two genuinely simultaneous calls interleave safely, though the same `lockForUpdate()` mechanism is what Laravel's
own documentation relies on for exactly this guarantee).

### 2.12 What's new since §2.11 (fifth post-9.8 fix round: searchable guest box, front-desk permission-cache fix, accountant expense/disbursement/payment grants, day-close rebuild — `PHASE9_AUDIT_AND_PLAN.md` §20.8)

Two new files, twelve new/updated methods across five existing files:

- `tests/Unit/RolesAndPermissionsSeederTest.php` (NEW, 1 method) — `test_seeding_roles_and_permissions_clears_the_spatie_permission_cache()`: proves the seeder itself ends by calling `PermissionRegistrar::forgetCachedPermissions()`, so a freshly seeded/re-seeded grant (like the front desk's `approve-reservation`) takes effect immediately instead of waiting out the Spatie permission cache's TTL — this is the root-cause fix for the standing "front desk still can't update reservation status" report.
- `tests/Unit/FinancialAccountModelTest.php` (NEW, 3 methods) — proves `FinancialAccount::paymentMethod()` translates `'bank'` → `'bank_transfer'` and passes every other type (`cash`, `mobile_money`, `card`) straight through unchanged; the regression guard for the `financial_accounts.type` vs `payments.method`/`cash_disbursements.method` enum mismatch that was silently throwing a hard database error on every Bank-account expense payment or disbursement before this round.
- `tests/Feature/Finance/AccountingDayTest.php` (+4 methods, several existing ones rewritten) — `test_reconciling_every_active_account_then_lock_succeeds()`, `test_locking_is_blocked_while_even_one_active_account_is_unreconciled()`, `test_reconciling_the_same_account_twice_in_one_day_is_rejected()`, `test_the_day_close_screen_lists_every_active_account_with_its_reconciliation_status()` — together these prove the day-lock gate now requires every active `FinancialAccount` (Cash, Bank, MTN, Orange, ...) reconciled for *today* specifically, not just the old whole-hotel cash count, closing the "it is just there, it doesn't capture Bank/MTN/Orange" complaint. `test_cannot_lock_the_day_before_every_active_account_is_reconciled()` and `test_recording_only_the_legacy_cash_count_is_no_longer_enough_to_lock()` were rewritten to assert against the new gate instead of the old one.
- `tests/Feature/Finance/ExpenseTest.php` (+3 methods) — `test_marking_an_approved_expense_paid_from_the_bank_account_posts_bank_transfer_not_bank()` (the direct end-to-end regression test for the enum-mismatch fix above — pays a real expense from the seeded Bank account and asserts the posted payment's `method` is `bank_transfer`, never the invalid `bank`); `test_accountant_can_request_an_expense()` and `test_an_accountant_still_cannot_approve_their_own_expense_request()` (the accountant's new `manage-expenses` grant, plus proof the existing per-user self-approval guard still applies to the accountant exactly like it already did to a Duty Manager).
- `tests/Feature/FrontOffice/PaymentModuleTest.php` (+1 method) — `test_accountant_can_record_a_general_payment()`: the accountant's new `record-payment` grant, closing the "payment entries" part of the request.
- `tests/Feature/FrontOffice/ReservationTest.php` (+1 method) — `test_reservation_create_page_has_no_guest_dropdown_and_shows_a_prefilled_guest()`: proves the create-reservation screen no longer ships a `<select>` built from every guest in the database (previously capped at 200, unpaginated) — the view has no `guests` variable at all, and a guest arriving via `?guest_id=` (the "Reservation" button on a just-created guest) is shown by name through the same search-as-you-type box the walk-in check-in screen already used.

Beyond these twelve, roughly fifteen existing test files across `Finance`, `FrontOffice`, `Pos`, and `Reporting` were updated in place (no new methods) to post a real `financial_account_id` instead of a bare `method`/`payment_method` string, matching the controller-contract change that made per-account attribution possible everywhere money moves — `PaymentModuleTest`, `FolioTest`, `ShiftGateTest`, `CheckInCheckOutTest`, `ReservationDepositTest`, `OrderTest`, `FrontDeskPettySaleTest`, `RecipeStockDeductionTest`, `AdditionalOutletsTest`, `OrderCrossOutletTest`, `DepartmentReconciliationReportTest`, `AccountsReceivableTest`, `PayrollTest`, `ShiftTest`, `FacilityAccessTest`. These are not counted as "new" methods since no assertion count changed — only their payloads did — but every one of them was re-traced against the new controller code to confirm it still passes the same scenario it always did.

New totals: **79 files / 498 methods** (77 + 2 files, 486 + 12 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic). Same standing limitation as
everything else in this document: **written and manually traced against the actual current source of every
service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory and no
reachable database. One thing this round could specifically not verify by execution: the Spatie permission-cache
fix (`RolesAndPermissionsSeederTest`) proves the seeder *calls* `forgetCachedPermissions()`, which is the correct
and sufficient fix by Spatie's own documented cache-invalidation contract, but this sandbox cannot spin up a real
cache store (file/database/redis) and a second HTTP request to prove the *end-to-end* "grant a permission, seed
again, next request sees it immediately" cycle against a live app.

### 2.13 What's new since §2.12 (sixth post-9.8 fix round: every payment tied to a real financial account, Duty Manager can create/edit financial accounts — `PHASE9_AUDIT_AND_PLAN.md` §20.9)

No new files. Two net new methods across two existing files (one rewritten in place, one added, in each):

- `tests/Feature/Finance/RefundTest.php` — `test_approving_a_refund_posts_to_the_folio_and_the_central_ledger()` was rewritten to `test_approving_a_refund_posts_to_the_folio_and_the_central_ledger_using_the_approvers_chosen_account()`: the request asks for `mobile_money`, the approval is made from the seeded Bank account, and the payment actually posted to the ledger carries `bank_transfer` (the Bank account's translated method) — proving the approver's real account, not the requester's guess, is what determines the posted method. New: `test_approving_a_refund_without_picking_a_financial_account_is_rejected()` — approving with no `financial_account_id` at all fails validation before it ever reaches `RefundService`, leaves the refund `pending`, and posts zero payments. The two existing self-approval-guard tests (front desk, GM) were updated to pass a `financial_account_id` too, since the route's validation now runs before the permission/self-approval checks matter.
- `tests/Feature/Finance/FinancialAccountTest.php` — `test_duty_manager_cannot_manage_financial_accounts()` was replaced with `test_duty_manager_can_now_create_a_financial_account()` (Duty Manager's new grant) and a new, genuinely-still-true negative control, `test_a_role_without_manage_financial_accounts_is_forbidden()`, using Front Desk — a role that has never held this permission in any round — so the "someone is still correctly forbidden" case isn't lost just because Duty Manager's answer flipped.

New totals: **79 files / 500 methods** (79 + 0 files, 498 + 2 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic). Same standing limitation as
everything else in this document: **written and manually traced against the actual current source of every
service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory and no
reachable database.

### 2.14 What's new since §2.13 (sixth post-9.8 fix round, follow-up: financial accounts can now actually be edited and reactivated — `PHASE9_AUDIT_AND_PLAN.md` §20.10)

No new files. Five new methods, all in `tests/Feature/Finance/FinancialAccountTest.php`:

- `test_accountant_can_edit_an_existing_financial_account()` — updates the seeded Cash account's name and opening balance through the new edit screen, confirms both persisted.
- `test_duty_manager_can_edit_an_existing_financial_account_too()` — proves the edit right follows the same `manage-financial-accounts` grant §20.9 gave Duty Manager, not a separate permission.
- `test_editing_an_account_into_another_accounts_code_is_rejected()` — editing the Bank account's code to the already-used `CASH` fails validation and leaves the Bank account's code untouched.
- `test_a_role_without_manage_financial_accounts_cannot_edit_one()` — Front Desk, which has never held this permission, gets a 403 and the account is unchanged.
- `test_a_deactivated_account_can_be_reactivated()` — deactivates the Cash account, then reactivates it through the new route, confirming `is_active` flips back to true — the undo that `deactivate()` never had before this round.

New totals: **79 files / 505 methods** (79 + 0 files, 500 + 5 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic). Same standing limitation as
everything else in this document: **written and manually traced against the actual current source of every
service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory and no
reachable database. No schema changed this round — `update()` and `reactivate()` write to columns that already
existed (`name`, `code`, `type`, `opening_balance`, `is_active`), so there was nothing to migrate.

### 2.15 What's new since §2.14 (seventh post-9.8 fix round: two always-safe Deploy Helper seed buttons — `PHASE9_AUDIT_AND_PLAN.md` §20.11)

No new files. Four new methods, all in `tests/Feature/DeployHelperTest.php`:

- `test_seeding_roles_and_permissions_alone_is_never_refused_even_with_an_existing_user()` — posts to the new route with a `User` already in the database and confirms it's never blocked the way the generic seed action correctly is, and that the `accountant` role really does get (re-)created.
- `test_seeding_financial_accounts_alone_is_never_refused_even_with_an_existing_user()` — same shape, confirms all four starter accounts (Cash, Bank, MTN, Orange) exist afterward.
- `test_seeding_financial_accounts_twice_never_duplicates_or_resets_an_existing_account()` — the idempotency guarantee the whole fix depends on: a Cash account already exists with a custom name and a real opening balance; running the seed action leaves it as exactly one row, with its name and balance both untouched.
- `test_these_two_safe_seeders_are_still_a_404_without_the_flag_and_token()` — the new routes carry the exact same `authorizeRequest()` gate as every other Deploy Helper action; a wrong token still 404s on both.

New totals: **79 files / 509 methods** (79 + 0 files, 505 + 4 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic). Same standing limitation as
everything else in this document: **written and manually traced against the actual current source of every
service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory and no
reachable database. No migration this round — new routes and controller actions only, calling seeders that
already existed.

### 2.16 What's new since §2.15 (seventh post-9.8 fix round, follow-up: the Master Admin data reset/backup/export screen — `PHASE9_AUDIT_AND_PLAN.md` §20.12)

One new file, `tests/Feature/Admin/SystemDataTest.php` (11 methods):

- `test_owner_can_view_the_data_management_page_and_sees_real_row_counts()` — the page loads for Owner and actually shows a real table's row count, not a static placeholder.
- `test_general_manager_cannot_reach_the_data_management_page_despite_holding_almost_every_other_permission()` — the deliberate carve-out: GM holds every permission except `manage-own-shift` in this project's pattern, and this proves `manage-system-data` is excluded too, rather than assuming the seeder grant is right.
- `test_other_roles_cannot_reach_the_data_management_page()` — data-provider-driven, covers Duty Manager, Accountant, Cashier, Front Desk, Bar Staff, Restaurant Staff in one method.
- `test_reset_is_rejected_without_the_typed_confirmation_phrase()` and `test_reset_is_rejected_without_confirming_a_backup_was_taken()` — both required gates are independently enforced server-side, not just by the page's JavaScript; a guest created beforehand is confirmed still present after each rejected attempt.
- `test_reset_clears_operational_data_but_leaves_setup_data_untouched()` — the core guarantee, exercised broadly: guests, reservations, and a raw-inserted payment row are gone after reset, while the owner's and accountant's logins, a department, a room, a room category, and a financial account (`CASH`) all still exist, and the owner still holds the `owner` role.
- `test_reset_frees_an_occupied_room_but_leaves_a_maintenance_room_alone()` — an occupied room flips back to `available` (its occupying stay is gone), while a room already in `maintenance` is left exactly as it was, proving the reset doesn't conflate "no active stay" with "fine to use."
- `test_reset_deactivates_every_sync_device_so_a_partner_installation_cannot_silently_resurrect_the_wiped_data()` — the one assertion that actually proves the hybrid-sync defense described in `SystemDataService`'s docblock is real: an active `SyncDevice` is `is_active = false` after the reset, not merely documented as something that should happen.
- `test_reset_writes_exactly_one_audit_log_entry_for_the_whole_action()` — one `admin.system_data_reset` audit row per reset, attributed to the acting owner, with the per-table row counts recorded in it — not zero, and not one per table cleared.
- `test_backup_downloads_a_json_file_and_never_includes_a_staff_password_hash()` — reads the owner's *real* stored bcrypt hash back out of the database with `getRawOriginal('password')` after creation (rather than checking for a plaintext string that could never appear anyway, since `password` is cast `hashed`) and confirms that exact hash string is absent from the downloaded backup content, while ordinary table data (`"guests"`) is present.
- `test_export_downloads_a_zip_of_business_data_csvs()` — the export endpoint returns a real file download with a `.zip` attachment header, not an error or an inline JSON blob.

New totals: **80 files / 520 methods** (79 + 1 files, 509 + 11 methods — real counts from the same `find`/`grep`
commands as every prior total in this document, not carried forward by arithmetic). Same standing limitation as
everything else in this document: **written and manually traced against the actual current source of every
service/controller/route it exercises, not executed** — this sandbox still has no `vendor/` directory and no
reachable database. No migration this round — no schema changed, only new routes, a controller, a service, and a
view; the new `manage-system-data` permission requires re-running `RolesAndPermissionsSeeder` (directly, or via the
Deploy Helper's "Update roles & permissions" button from §20.11/§2.15) to actually take effect in an existing
deployment, same as every permission-only change in this changelog.

---

## 3. The Phase 9.8 security-fix regression tests, specifically

These are called out separately because they're the newest, highest-stakes tests in the project — each one exists
specifically to prove a real security fix actually closes the gap it claims to, not just to exercise a feature.
Full narrative for each fix is in `SECURITY.md` §3; this is the test-to-fix mapping:

| Fix | Test file | What it proves |
|---|---|---|
| Cross-outlet POS authorization | `OrderCrossOutletTest` | A Bar Staff account gets a 404, not success, substituting a Restaurant/Front Desk order's ID into a Bar URL — for view, add-item, confirm, close, cancel, print-receipt, and item-removal actions; plus a same-outlet regression guard proving the fix doesn't break the ordinary case. |
| Sync credential protection (outbound) | `SyncServiceTest::test_a_users_outbox_entry_never_carries_its_credential_or_lockout_columns` | A `User` row's outbox payload contains `name` but not `password`/`pin_hash`/`totp_secret`/`totp_recovery_codes`/`remember_token`/`failed_pin_attempts`/`pin_locked_until`. |
| Sync credential protection (inbound, adversarial sender) | `SyncServiceTest::test_applying_an_incoming_user_update_never_overwrites_the_local_password_or_pin` | Even a crafted incoming payload that explicitly includes a `password` key cannot change the local hash — `name` still updates normally. |
| Sync credential protection (conflict resolution) | `SyncConflictResolverTest::test_kept_incoming_on_a_user_conflict_never_overwrites_the_password` | Same guarantee via the `kept_incoming` conflict-resolution path specifically. |
| CSV/formula-injection neutralization | `ExportsCsvTest` (3 methods) | A cell starting with `=`/`+`/`-`/`@` is apostrophe-prefixed; an ordinary cell is untouched. |

Every one of these is written and manually traced against the actual, now-fixed source — not yet run.

### 3.1 The post-9.8 fix-round regression tests, specifically

Same purpose, one round later — full narrative for each fix is in `PHASE9_AUDIT_AND_PLAN.md` §11:

| Fix | Test(s) | What it proves |
|---|---|---|
| Concurrency (Expense/Refund/Order) | Folded into `ExpenseServiceTest`/`RefundServiceTest`'s existing coverage plus `OrderTest::test_a_draft_order_cannot_be_closed` | The locked-row, re-checked-inside-the-transaction path is exercised by the same tests that exercise the normal success/failure paths, since the lock is invisible to a single, non-racing request. |
| PO line ownership | `PurchaseOrderTest::test_a_line_from_one_purchase_order_cannot_be_received_through_another` | Submitting PO B's line item id against PO A's receive route is rejected with no `GoodsReceipt`, no stock movement, and no change to either PO's received quantity or status. |
| POS outlet ownership (tables) | `OrderCrossOutletTest::test_cannot_open_a_bar_order_against_a_restaurant_table`, `::test_order_service_open_rejects_a_table_from_a_different_outlet_directly` | A cross-outlet table id is rejected at both request validation and the service layer; no order is created and the table's status is untouched. |
| POS outlet ownership (menu items) | `OrderCrossOutletTest::test_cannot_add_a_restaurant_menu_item_to_a_bar_order`, `::test_cannot_add_an_inactive_menu_item_to_an_order`, `::test_order_service_add_item_rejects_a_menu_item_from_a_different_outlet_directly` | A cross-outlet or inactive menu item is rejected at both layers; no `OrderItem` is created. |
| Login enumeration | `LoginEnumerationTest` (7 methods) | An unknown/inactive username is routed forward into the same two-step flow as a real one (never a `username` session error), consistently for the same input, and always fails the next step with the same generic message — while a real account still logs in normally. |
| POS lifecycle (confirm before close) | `OrderTest::test_a_draft_order_cannot_be_closed` | A `draft` order posted to the close route is rejected with a session error, stays `draft`, and creates no `Payment`/`FolioTransaction`/stock movement. |

Every one of these is written and manually traced against the actual, now-fixed source — not yet run.

---

## 4. Exact commands to get real numbers

Run these on a machine or CI runner with normal internet access — nothing here is sandbox-specific, this is just
standard Laravel testing:

```bash
composer install
cp .env.example .env
php artisan key:generate

# Point DB_* in .env at a real MySQL/MariaDB instance, then:
php artisan migrate --seed

# The whole suite:
php artisan test

# Or targeted, starting with the newest/highest-stakes coverage:
php artisan test --filter=OrderCrossOutletTest
php artisan test --filter=ComplimentaryOrderTest
php artisan test --filter=StayComplimentaryTest
php artisan test --filter=SyncServiceTest
php artisan test --filter=SyncConflictResolverTest
php artisan test --filter=ExportsCsvTest
php artisan test --filter=SyncTransportTest      # the hybrid sync engine's network-facing surface
```

If anything fails, **that is a genuine bug to fix** — every claim in this project's documentation has been made on
the basis of code review and manual tracing, not a passing test run, so a real failure is new information, not a
contradiction of something already "verified."

### 4.1 Additional real-server/real-network verification this project has never been able to attempt

Beyond `php artisan test`, a handful of things in this codebase specifically need a live, two-node deployment to
verify for real — no amount of code review substitutes for these:

- **A genuine two-installation sync cycle**: two real Laravel installs, two real MySQL databases, `sync:register-device`
  run on each, `sync:run` cron'd on at least one, watched over several cycles including a deliberately engineered
  conflict (edit the same record on both sides between two cycles) resolved through `/admin/sync/conflicts`. See
  `INSTALLATION.md` §5.4 and `HYBRID_ARCHITECTURE.md` throughout.
- **Concurrency**: this project's folio/payment/stock-deduction logic assumes normal Eloquent-level consistency;
  nothing in this sandbox could exercise two simultaneous real requests racing against the same row (e.g. two
  cashiers posting a payment to the same folio at the same instant) the way a real load test could.
- **The raw MySQL `ALTER TABLE ... MODIFY COLUMN` statements** in `add_front_desk_outlet_support.php` — reviewed
  by inspection against the exact `ENUM(...)` value lists in their source migrations, never run against a real
  MySQL/MariaDB server.
- **`composer audit`** — blocked by the same Packagist restriction as `composer install`; this project's declared
  dependencies (`composer.json`) have never been checked against a real known-vulnerability database.

---

## 5. What this report is not claiming

This report does not claim the test suite passes. It does not claim the test suite is complete or bug-free. It
claims exactly three things, each independently verifiable by re-running the commands above: 520 test methods
exist across 80 files, every one of them was written against real, current source rather than copied or
templated, and none of them has been executed in the environment this project was built in. Treat the first real
`php artisan test` run — whenever and wherever it happens — as this project's actual, first test result.
