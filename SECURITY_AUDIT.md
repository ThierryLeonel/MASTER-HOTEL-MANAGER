# Phase 7 — Security Audit

**What this document is, and isn't.** This is a manual, grep-and-read static code review performed by hand across
the entire Phase 1–6 codebase, checking for the eight categories the project roadmap named (SQL injection, XSS,
CSRF, IDOR, mass assignment, file upload handling, rate limiting, secret handling). **It is not a substitute for an
automated SAST/DAST scan or a real penetration test**, and no such tool was run — this cloud sandbox cannot reach
Packagist to install one (the same network restriction documented in README §7 for `composer install`/`php artisan
test`). Every finding below was traced to specific file/line evidence, not asserted from general impressions, and
every fix was applied to real source files and lint-checked (`php -l`) plus route-integrity-checked
(`verify_routes.py`) afterward — see the "What was actually run" section at the bottom. No test in this or any
prior phase has been executed in this sandbox for the reasons already documented in README §7; that caveat applies
to this phase's new/updated tests too.

**Scope.** Every PHP file under `app/`, every Blade view under `resources/views/`, `routes/web.php`,
`bootstrap/app.php`, `config/session.php`, and `.env.example`, covering the complete Phase 1–6 feature set.

**Result summary.** 4 of the 8 categories were already clean with no changes needed (mass assignment, raw SQL
injection, XSS, CSRF). File upload handling doesn't apply — no such feature exists in this codebase yet. The
remaining 3 categories (IDOR/authorization, rate limiting, secret handling) turned up 6 real, fixed issues, detailed
below. None of them affects data already in the database; all are either input-validation-shaped checks or
configuration defaults.

---

## 1. Mass assignment (`$fillable`/`$guarded`) — CLEAN

- All 48 Eloquent models declare either `$fillable` or `$guarded`; zero declare neither, and zero use the
  dangerous fully-open `$guarded = []`.
- Zero controllers pass `$request->all()` / `request()->all()` into `create()`/`update()`/`fill()`.
- Every controller `::create(...)`/`->update(...)` call was traced by hand: each builds its `$data` array from
  `$request->validate([...])` first (never raw input), then optionally appends explicit server-set fields (like
  `created_by`) before writing.

## 2. Raw SQL injection — CLEAN

- Only three raw-SQL fragments exist anywhere in the app, all in `FinancialReportService` (Phase 6's
  `selectRaw('type, SUM(amount) as total')` and two siblings) — every one is a hardcoded column/aggregate
  expression with zero user input concatenated in.
- No `whereRaw`/`orderByRaw`/`havingRaw`/`DB::statement`/`DB::unprepared`/`DB::select` anywhere in the codebase.
- No controller or service ever takes a sort/filter *column name* from request input — only filter *values* do,
  and those are always parameter-bound automatically by Eloquent/the query builder.

## 3. XSS via raw Blade output — CLEAN

- Exactly one `{!! !!}` unescaped echo in the entire view tree: the TOTP-enrollment QR code
  (`resources/views/auth/totp-setup.blade.php`). Traced end to end: it renders an `otpauth://` URI (built by
  `pragmarx/google2fa`, which URL-encodes its own parameters) through `bacon/bacon-qr-code`'s SVG writer, which
  draws a QR matrix as `<rect>`/`<path>` elements — the input string is never written into the page as text. Not
  an XSS vector.
- Every other dynamic value in every other view uses Blade's auto-escaping `{{ }}`.
- Checked every `<script>` block in every view (11 total) for a `{{ }}` echo inside it — the classic "HTML-escaped
  but not JS-string-escaped" trap. Zero matches; every inline script is static UI logic.

## 4. CSRF coverage — CLEAN

- `bootstrap/app.php` declares no CSRF exceptions of any kind — every `web` route is protected.
- Every one of the 89 `<form>` blocks in the view tree was parsed programmatically for its `method` attribute and
  the presence of `@csrf`: every POST/PUT/PATCH/DELETE form has it. The only forms without it are 9 legitimate
  `method="GET"` filter/search forms (e.g. the attendance date picker, the reports date-range filter) that never
  mutate anything.

## 5. IDOR / record-level authorization — 3 findings, all fixed

Every route-bound model parameter and every "my own record" self-service code path was reviewed. Front
Office/POS/Inventory/Finance access in this system is *permission-scoped, not identity-scoped* by design (any Front
Desk agent can touch any guest's folio; any Duty Manager can approve any purchase order) — correct for a
single-property hotel PMS with no guest self-service portal, and the same pattern used consistently since Phase 1.
That is not IDOR. The real problems were all in HR self-service and approval "who signs off" logic:

**Finding 1 — any employee could cancel any other employee's pending leave request (real IDOR).**
`request-leave` is granted to all 8 roles as a self-service permission, but `LeaveService::cancel()` never checked
that the caller was the request's own owner — only that the route's blanket `request-leave` permission middleware
passed, which every account satisfies. Any authenticated user could cancel a colleague's leave request by posting
to its ID.
*Fix*: `LeaveService::cancel()` now requires the actor be the leave's own `user_id`, or hold `approve-leave`.

**Finding 2 — a single Duty Manager account could request and approve its own leave or expense (broken separation
of duties).** Every other approval pair in the system (purchase orders, payroll, accounting-day lock) deliberately
keeps the approve-tier permission out of the Duty Manager's hands when they also hold the matching request-tier
permission — except Leave (`request-leave` + `approve-leave`) and Expense (`manage-expenses` + `approve-expense`),
where Duty Manager held both. One account could file and immediately approve its own request with zero second-party
review — the existing Phase 4 test `test_duty_manager_can_approve_a_small_expense` had literally encoded this as
correct behavior, using the same Duty Manager for both steps.
*Fix*: `LeaveService::approve()` and `ExpenseService::approve()` now reject a self-approval with `HmsException`,
regardless of role (a hard business rule, like the accounting-day lock, not a bypassable permission check). The
same guard was added to `RefundService::approve()` for consistency (GM holds both `request-refund` and
`approve-refund`). The old test was rewritten to use two different Duty Manager accounts; new tests assert the
self-approval block in all three services. Payroll's generate/approve/pay split was left as-is — it already
excludes Accountant from `approve-payroll-run` for exactly this reason, and the residual GM/Owner overlap mirrors
those two roles' consistently broad authority everywhere else in the permission matrix, not a new defect.

**Finding 3 — every employee could view every other employee's attendance log (information disclosure).**
`clock-own-attendance` is granted to all 8 roles, and `hr.attendance.index` was gated only by
`clock-own-attendance|record-attendance` — so its full-roster query (everyone's clock-in/out times, for any date)
was visible to any Bar/Restaurant Staff account.
*Fix*: `AttendanceController::index()` now shows the full roster only to a caller holding `record-attendance`;
everyone else sees only their own "My attendance today" card.

`hr.my-payslips` and attendance clock-in/out were already correctly scoped to `$request->user()` with no
manipulable parameter — no change needed there.

## 6. File upload handling — not applicable

No file-upload feature exists anywhere in this codebase: no `UploadedFile`/`->file(`/`storeAs(`/`->store(`/
`Storage::` usage, no `multipart/form-data` form, no migration column named path/photo/image/attachment/upload
(beyond an unrelated code comment). Nothing in the Phase 1–6 spec called for one. Noted here so the requirements
aren't forgotten if a future phase adds one: validate extension *and* MIME type server-side, store outside the
public web root or behind an authenticated route, generate a random filename, and enforce a size limit.

## 7. Rate limiting coverage — 1 gap, fixed

- Password login and the TOTP login challenge already had their own account+IP-scoped `RateLimiter` checks
  (5 attempts / 60s lockout) — confirmed correct.
- PIN login has genuine account-level lockout (`failed_pin_attempts`/`pin_locked_until`, from the configurable
  `pin_max_attempts`/`pin_lockout_minutes` settings) — the right control for a 4-digit PIN's small keyspace,
  confirmed correct.
- **Gap**: `/login` (the username-lookup step) and `/login/totp/setup` (confirming the very first TOTP code during
  MFA enrollment) had *no* rate limiting at all — inconsistent with the routes file's own comment claiming every
  login step is individually throttled.
*Fix*: wrapped the whole unauthenticated login route group in Laravel's built-in `throttle:20,1` (20 requests/
minute/IP), layered on top of (not replacing) the existing account-scoped checks. Confirmed no other unauthenticated
route exists in the app besides this login flow and the framework's own `/up` health check.

## 8. Secret handling / config exposure — 2 gaps, fixed

- `.env`/`.env.backup`/`.env.production` are gitignored; `.env.example` ships no real secrets.
- `.env.example` defaults `APP_ENV=production` and `APP_DEBUG=false`.
- `User` model: `password`/`pin_hash` are one-way `'hashed'`; `totp_secret`/`totp_recovery_codes` are
  `'encrypted'`/`'encrypted:array'` (correctly reversible, since TOTP verification needs the raw secret back) —
  and all four are in `$hidden`, so none can leak through an accidental model serialization.
- No debug package (Telescope/Debugbar) installed; no code path logs a password/PIN/secret; no hardcoded
  credentials anywhere in `app/`/`config/`.
- **Gap 1**: `config/session.php`'s `secure` cookie option had no explicit default, so the session cookie (a bearer
  credential for every logged-in account) could travel over plain HTTP even on an HTTPS deployment.
  *Fix*: `.env.example` now sets `SESSION_SECURE_COOKIE=true` by default.
- **Gap 2 (documentation accuracy)**: `.env.example` described `ENABLE_DEPLOY_HELPER`/`DEPLOY_TOKEN` as an active
  security gate for `app/Http/Controllers/DeployHelperController.php` — a file that **does not exist anywhere in
  the codebase** (confirmed by `find`/`grep`). That's precisely the kind of claim this project's own honesty rule
  exists to prevent — a reader could believe a security control is live when the feature isn't built yet (it's
  correctly listed as not-yet-built in README §11).
  *Fix*: rewrote the comment to say plainly these variables are reserved for that future phase and have no effect
  yet, while keeping the same "default false, never commit a real token" guidance for when it is built.

---

## Files changed in this phase

- `app/Services/Hr/LeaveService.php` — self-approval guard in `approve()`, ownership/manager check in `cancel()`.
- `app/Services/Finance/ExpenseService.php` — self-approval guard in `approve()`.
- `app/Services/Finance/RefundService.php` — self-approval guard in `approve()`.
- `app/Http/Controllers/Hr/AttendanceController.php` — scope the full roster query to `record-attendance` holders.
- `resources/views/hr/attendance/index.blade.php` — hide the roster table from non-managers.
- `routes/web.php` — `throttle:20,1` on the unauthenticated login route group.
- `.env.example` — `SESSION_SECURE_COOKIE=true` default; corrected the deploy-helper comment.
- Tests: `tests/Feature/Finance/ExpenseTest.php`, `tests/Feature/Finance/RefundTest.php`,
  `tests/Feature/Hr/LeaveTest.php`, `tests/Feature/Hr/AttendanceTest.php`, `tests/Feature/Auth/LoginTest.php` —
  one pre-existing test rewritten to stop encoding the self-approval bug as correct behavior, 9 new test methods
  covering all 6 fixes.

## What was actually run in this session (and what wasn't)

**Run and verified:**
- `php -l` on every file changed in this phase, individually.
- A full-tree `php -l` sweep (`find . -path ./vendor -prune -o -name "*.php" -print | xargs -n1 php -l`) — zero
  syntax errors across the whole Phase 1–7 codebase.
- `verify_routes.py` (the route-name cross-check script from Phase 3, unchanged) — 132 named routes, zero
  duplicates, zero unresolved `route(...)` references, confirming the `throttle` middleware addition didn't break
  any route registration.

**NOT run (same standing limitation as every prior phase):** `composer install`/`php artisan test` — this sandbox
cannot reach Packagist. The 9 new/updated test methods in this phase were written and manually traced against the
service/controller logic they test, exactly like every test in Phases 1–6, but never executed. Run
`composer install && php artisan test` yourself to get a real result.

**NOT performed, and out of scope for a manual code review regardless of environment:** an automated SAST/DAST
scan, dependency vulnerability scanning (`composer audit` — also blocked by the same Packagist restriction), a
real penetration test, or a review of infrastructure/hosting-level controls (TLS configuration, firewall rules,
the actual InfinityFree account's settings). Those require tools and a running deployment this sandbox doesn't
have.

---

# Phase 9.0 Addendum — Route-Level Authorization Gaps Found During the Phase 9 Audit

The Phase 7 sweep above focused on IDOR (is the *right* record being acted on) and self-approval/separation-of-duty
issues on routes that already had a `permission:` gate. It did not re-check whether every route had a gate *at
all*. The Phase 9 audit (`PHASE9_AUDIT_AND_PLAN.md`) did that broader sweep directly against `routes/web.php` and
found four gaps that predate Phase 9 and were live in the code as shipped through Phase 8 — not something Phase 9
introduced. All four are fixed here, first, ahead of any new Phase 9 feature work, because they were live
exposures.

**Finding 4 — `stays.index`/`stays.show` had no permission gate at all (real financial-data exposure).**
Only the `stays.checkout` POST route carried `permission:checkout-guest`; the two GET routes had none.
`StayController::show()` loads `folio.transactions.user` and `folio.accountsReceivable`, and
`resources/views/front-office/stays/show.blade.php` renders the folio balance, the full transaction ledger, and
outstanding Accounts Receivable unconditionally. Any authenticated role — Bar Staff, Restaurant Staff, Cashier
included — could open any guest's complete financial history by visiting `/stays/{id}`.
*Fix*: both routes now require `permission:manage-guests`, the same permission `GuestController`'s routes already
use, so Owner/GM/Duty Manager/Front Desk are unaffected and everyone else is now correctly excluded. See
`tests/Feature/FrontOffice/StayAuthorizationTest.php`.

**Finding 5 — `rooms.index` had no permission gate at all (occupancy + guest-name exposure).**
`RoomController::index()` eager-loads `currentStay.guest`, so the room list shows live occupancy and the current
occupant's name for every room, to every authenticated role.
*Fix*: gated on a new `view-rooms` permission, deliberately kept separate from `manage-rooms` (which controls
add/edit) so Front Desk can view the room board without being able to create rooms. Granted to
Owner/GM/Duty Manager/Front Desk. See `tests/Feature/FrontOffice/RoomAuthorizationTest.php`.

**Finding 6 — `maintenance.index` (viewing tickets) had no permission gate (lower severity).**
`store`/`approve`/`resolve` were already correctly gated; only the ticket list itself was not.
*Fix*: gated on `report-maintenance`, the broadest maintenance permission — held by every role that currently uses
this screen (Owner/GM/Duty Manager/Front Desk/Bar Staff/Restaurant Staff), excluding only Accountant/Cashier, who
hold no maintenance permission at all. See `tests/Feature/FrontOffice/MaintenanceAuthorizationTest.php`.

**Finding 7 — `DashboardController` contradicted its own docblock and leaked a financial total to every role.**
The docblock claimed "Role-specific dashboards (spec section 25)"; the code built one identical `$data` array,
including `openReceivablesTotal` (a hotel-wide financial figure), for every authenticated user.
`RolesAndPermissionsSeeder` already grants a distinct `view-dashboard-{role}` permission per role, but nothing in
the controller ever read them.
*Fix*: `openReceivables`/`openReceivablesTotal` are now `null` unless the caller holds `view-financial-reports` or
`view-operational-reports` (Owner/GM/Accountant/Duty Manager), and the Blade view hides that card entirely when
null. The docblock now describes actual behavior instead of an aspirational one, and explicitly notes that full
per-role dashboard layouts (using the already-seeded `view-dashboard-*` permissions) remain future work, tracked as
Phase 9.4 in `PHASE9_AUDIT_AND_PLAN.md` — this fix closes the financial-data leak, it does not build the full
per-role redesign. See `tests/Feature/DashboardAuthorizationTest.php`.

## Files changed in this addendum

- `routes/web.php` — added `permission:view-rooms` to `rooms.index`, `permission:manage-guests` to
  `stays.index`/`stays.show`, `permission:report-maintenance` to `maintenance.index`.
- `database/seeders/RolesAndPermissionsSeeder.php` — new `view-rooms` permission; granted to `duty_manager` and
  `front_desk` explicitly (Owner/GM already receive every permission).
- `app/Http/Controllers/DashboardController.php` — `openReceivables`/`openReceivablesTotal` scoped behind
  `view-financial-reports`/`view-operational-reports`; corrected the docblock.
- `resources/views/dashboard.blade.php` — hide the "Open receivables" card when the new flag is false; the
  maintenance-approval banner now also checks `report-maintenance` so it never links to a route the viewer can't
  open.
- `resources/views/layouts/app.blade.php` — wrapped the "Rooms", "Active Stays", and "Maintenance" nav links in
  `@can`, matching every other nav link, which was already following this pattern everywhere else.
- Tests (new): `tests/Feature/FrontOffice/RoomAuthorizationTest.php`,
  `tests/Feature/FrontOffice/StayAuthorizationTest.php`, `tests/Feature/FrontOffice/MaintenanceAuthorizationTest.php`,
  `tests/Feature/DashboardAuthorizationTest.php`.

## What was actually run for this addendum (and what wasn't)

**Run and verified, with real output:**
- `php -l` on every changed/created PHP file individually — zero syntax errors on all 9 files
  (`routes/web.php`, `RolesAndPermissionsSeeder.php`, `DashboardController.php`, and the 6 new/changed test/view
  files that are valid PHP; the two `.blade.php` files are not raw PHP and were instead checked by counting
  `@can`/`@endcan`/`@canany`/`@endcanany`/`@if`/`@endif` directive pairs, which balance).
- `verify_routes.py` — 137 named routes, 0 duplicates, 136 distinct `route(...)` references, 0 unresolved. Route
  count is unchanged from before this addendum (137), confirming no route was accidentally dropped or renamed
  while adding the new middleware.
- A permission-list parse (stripping comments first) confirming `RolesAndPermissionsSeeder::PERMISSIONS` has 59
  unique entries (58 before this addendum, +1 for `view-rooms`) with zero duplicates, and that `view-rooms` appears
  in the master list plus the `duty_manager` and `front_desk` grant arrays as intended.

**NOT run (same standing sandbox limitation as every prior phase, re-confirmed this session):**
`composer install` — `repo.packagist.org` returns a 403 policy denial through this session's egress proxy (org
policy, not a transient failure); `apt-get update` also cannot reach the Ubuntu mirrors from this sandbox. Without
`vendor/`, `php artisan test` cannot execute, so the 4 new test files above (12 test methods, which PHPUnit will
expand to 42 individual test runs once their `@dataProvider` role lists are enumerated) are written and manually
traced against the actual route/controller/view changes, but not executed. To get a real pass/fail count:

```
composer install
php artisan test --filter=RoomAuthorizationTest
php artisan test --filter=StayAuthorizationTest
php artisan test --filter=MaintenanceAuthorizationTest
php artisan test --filter=DashboardAuthorizationTest
php artisan test   # full suite, to confirm no regression in the other 146 pre-existing test methods
```
