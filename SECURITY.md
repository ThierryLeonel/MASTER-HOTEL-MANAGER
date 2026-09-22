# Security

This is the current, consolidated security posture of the project as of Phase 9.8 — what's been reviewed, what
was found, and what was fixed. It supersedes nothing in `SECURITY_AUDIT.md` (that document's Phase 7 audit and
Phase 9.0 addendum remain the full, original record of those two passes, referenced rather than copied here) but
is the single place to look for the complete picture, including everything reviewed since.

**What this document is, and isn't**, exactly as stated at the top of `SECURITY_AUDIT.md`: a manual, grep-and-read
static code review across the whole codebase, checking the same eight categories each time (mass assignment, raw
SQL injection, XSS via raw Blade output, CSRF coverage, IDOR/record-level authorization, file upload handling,
rate limiting coverage, secret handling/config exposure). **It is not a substitute for an automated SAST/DAST
scan, `composer audit`, or a real penetration test** — none of those were run, because this sandbox has no
outbound access to Packagist and no live server to test against (see `TEST_REPORT.md`). Every finding below was
traced to specific file/line evidence; every fix was applied to real source and lint-checked (`php -l`) plus
route-integrity-checked (`verify_routes.py`) afterward.

---

## 1. Audit history at a glance

| Pass | Scope | Findings | Status |
|---|---|---|---|
| Phase 7 audit | Phases 1–6 codebase, all 8 categories | 6 real issues (3 IDOR/authorization, 1 rate limiting, 2 secret handling) | All fixed — see `SECURITY_AUDIT.md` §1–8 |
| Phase 9.0 addendum | Route-level gate sweep across the whole app (a broader check than Phase 7's IDOR-within-a-gated-route focus) | 4 missing/wrong route-level permission gates | All fixed — see `SECURITY_AUDIT.md`'s addendum |
| **Phase 9.8 re-audit** (this document, new) | Everything built in Phase 9.1–9.7.6 plus the two out-of-band additions (Front Desk petty sales, complimentary items/stays) | 3 genuine gaps + 1 defensive hardening item | **All fixed — detailed below** |

The rest of this document covers only the Phase 9.8 pass in full; see `SECURITY_AUDIT.md` for the two earlier
passes' complete findings and fixes.

---

## 2. Phase 9.8 re-audit: scope

Every route, controller, and service added or materially changed since the Phase 9.0 addendum: the Admin screens
(Users/Roles/Departments/Settings/Room Categories, Phase 9.2), financial/operational reports and their CSV
exports (Phase 9.3/9.5), the dashboard's pending-approvals and weekly-summary widgets (Phase 9.4), printable
documents (Phase 9.5), the entire hybrid sync engine (Phase 9.7.1–9.7.6 — given the most attention, as the newest
and most security-sensitive surface in the project, being the first machine-to-machine, network-reachable feature
this codebase has ever had), the Front Desk POS outlet, and complimentary items/stays. Not re-reviewed: Phase 1–8
code already covered by the two earlier passes, except where Phase 9 code shares a code path with it (e.g.
`Pos\OrderController`, which predates Phase 9 for Bar/Restaurant but was widened to a third outlet this phase —
in scope below).

**Counts at the time of this audit**: 190 named routes (`routes/web.php` + `routes/sync.php` + `routes/deploy.php`,
via `verify_routes.py`, 0 duplicates, 0 unresolved references), 62 unique permissions, 55 Eloquent models, 306 PHP
files under `app/`/`tests/`/`database/`/`routes/`/`config/` — `php -l` clean across all of them.

---

## 3. Findings and fixes

### 3.1 Mass assignment — clean, with one architectural note

Every Phase 9.1–9.7.6 `create()`/`update()` call was traced by hand: every one builds an explicit array from
validated input, never `$request->all()`. `OrderItem`/`Stay`'s new comp-related `$fillable` entries are only ever
set via `OrderService::addItem()`/`StayController::markComplimentary()` — no other mass-assignable path reaches
them.

The one place mass-assignment protection is **deliberately** bypassed is the sync engine's `forceFill()` calls
(`SyncService::applyUpsert()`, `SyncConflictResolver::applyIncoming()`) — by design, since a sync payload is
trusted structural data, not raw user input. This is documented fully in `HYBRID_ARCHITECTURE.md` §6 and is the
root cause behind Findings 1–2 below, not a separate issue.

### 3.2 Raw SQL injection, XSS, CSRF — clean

No new `whereRaw`/`orderByRaw`/`havingRaw`/`DB::statement`/`DB::unprepared`/`DB::select` anywhere in the reviewed
scope. The one new raw-SQL surface (`add_front_desk_outlet_support.php`'s `ALTER TABLE ... MODIFY COLUMN`
statements widening MySQL `ENUM`s) uses hardcoded value lists with zero request input, and is DDL, not a query —
not an injection vector (though genuinely unverified against real MySQL — see `TEST_REPORT.md`).

Zero `{!! !!}` unescaped Blade output anywhere in the reviewed views, including the sync dashboard's
`json_encode($conflict->local_data, JSON_PRETTY_PRINT)`, which is passed through `{{ }}` and therefore
HTML-escaped regardless of its content.

`routes/deploy.php` and `routes/sync.php` are registered outside the `web` middleware group (from
`bootstrap/app.php`'s `then:` callback), so they correctly carry **no** CSRF middleware — appropriate for
server-to-server JSON traffic with no browser session. No exception was added inside the `web` group itself.
Every new `<form>` in every reviewed view has a matching `@csrf`.

### 3.3 IDOR / authorization — 2 genuine gaps found and fixed

**Finding 1 (fixed) — `Pos\OrderController` authorized against the URL's `{outlet}` string, never against the
route-bound `$order`'s actual `outlet` column.** Every action (`show`, `printReceipt`, `addItem`, `removeItem`,
`confirm`, `close`, `cancel`) called only `authorizeOutlet($request, $outlet)` — checking the caller's permission
for the *URL's* outlet — while `$order` is an implicit, unscoped route-model binding (`Order::findOrFail($id)`,
no outlet scoping, no global scope on `Order`). A Bar Staff account, holding only `operate-bar-pos`, could
substitute a Restaurant or Front Desk order's numeric ID into a `/pos/bar/orders/{id}/...` URL and every action
would succeed against the *wrong* outlet's order — viewing it, adding/removing items, confirming, closing it
(posting to the central payment ledger under the wrong outlet's `source_module`), or cancelling it — directly
contradicting the controller's own docblock claim that this exact scenario is prevented.
*Fix*: `authorizeOutlet()` now takes an optional `?Order $order` and additionally verifies `$order->outlet ===
$outlet`, aborting `404` otherwise; every action passing a bound `$order` now passes it through.
`removeItem()` additionally gained an `$item->order_id === $order->id` check — `{order}` and `{item}` are
independently bound route parameters, so an item belonging to a *different* order (even within the same outlet)
must not be removable by substituting a mismatched pair. See
`app/Http/Controllers/Pos/OrderController.php` and its own updated docblock; regression-covered by
`tests/Feature/Pos/OrderCrossOutletTest.php` (6 methods, including a same-outlet regression guard confirming the
ordinary case is unaffected).

**Finding 2 (fixed) — a User row's credential columns could leave the installation via the sync engine, and could
sit visible in the sync-conflicts admin UI.** `SyncService::exportableAttributes()` used `Model::getAttributes()`
— the raw, as-stored attribute array, which deliberately bypasses `$hidden` (that's the whole reason it's used
instead of `toArray()`, so casts round-trip exactly on the receiving side). This meant a `User` row's
`password`/`pin_hash` bcrypt hash and `totp_secret`/`totp_recovery_codes` ciphertext were included in every
outbound sync payload and every `SyncConflict` snapshot (`local_data`/`incoming_data`) — the latter rendered as
plaintext JSON in `/admin/sync/conflicts` to any `manage-sync` holder, Duty Manager included, not only the
Owner/GM who actually manage user credentials via `manage-users`. Combined with `applyUpsert()`'s unrestricted
`forceFill()` (the same mechanism, working in the other direction), this meant a compromised `sync_devices.shared_secret`
could be used to directly rewrite another account's password/PIN/TOTP secret on the receiving installation — a
full account-takeover path, not merely unwanted data exposure.
*Fix, three layers, all pointed at one new source of truth* (`SyncableModels::PROTECTED_ATTRIBUTES` /
`protectedAttributesFor()`, listing `password`, `pin_hash`, `totp_secret`, `totp_recovery_codes`,
`remember_token`, `failed_pin_attempts`, `pin_locked_until` for `User`):
1. `exportableAttributes()` strips them before an outbound payload or a conflict snapshot is ever built — they
   are never transmitted or stored in a `SyncConflict` row in the first place.
2. `applyUpsert()` also strips them from an *incoming* payload before `forceFill()`, independent of whether the
   sender's own export was patched — so the receiving side is not exploitable even given an adversarial sender
   who explicitly crafts a payload containing a credential key.
3. `SyncConflictResolver::applyIncoming()` (the `kept_incoming` resolution) strips them a third time, as its own
   self-contained guarantee rather than relying on an earlier caller having already done it.

Every other column (`is_active`, `department_id`, `name`, etc.) still syncs normally and deliberately — e.g.
disabling a terminated employee's account on one installation still locks them out of the counterpart. The
practical effect, documented in `HYBRID_ARCHITECTURE.md` §6: user accounts are provisioned identically on both
installations by the same seeder, but credentials are managed **per-installation** from that point on. See
`app/Services/Sync/SyncableModels.php`, `app/Services/Sync/SyncService.php`,
`app/Services/Sync/SyncConflictResolver.php`; regression-covered by two new tests in
`tests/Unit/Sync/SyncServiceTest.php` (an outbox entry never carries the protected columns; an incoming update
with an explicit `password` key never overwrites the local hash) and one in
`tests/Unit/Sync/SyncConflictResolverTest.php` (the same guarantee via `kept_incoming`).

**Confirmed clean, with specific evidence:**
- Admin routes (`admin/users`, `admin/departments`, `admin/settings`, `admin/room-categories`, `admin/sync`) are
  all correctly `permission:`-gated at the route-group level.
- Report routes correctly split across `view-financial-reports`/`view-operational-reports`/`view-inventory-reports`/
  `view-hr-reports`, matching README §9's matrix.
- The Phase 9.0 `DashboardController` fix (open receivables gated behind
  `view-financial-reports|view-operational-reports`) remains intact; `PendingApprovalsService`/
  `WeeklyOwnerSummaryService` are purely permission-driven.
- `FolioService::postCharge()`'s complimentary-stay guard cannot be bypassed via an alternate `type` string —
  `FolioTransaction::CHARGE_TYPES` is a strict allowlist checked first.
- `mark-stay-complimentary` is correctly route-gated and correctly excluded from Front Desk's grant list.
- Sync device authentication cannot be enumerated: `device_key` alone (without the matching `shared_secret`)
  yields nothing — `hash_equals()`-based, timing-safe comparison, indistinguishable error for "unknown key" vs.
  "wrong secret," checked on all three transport endpoints before anything else runs.

### 3.4 File upload handling — was "not applicable" in Phase 7; now a real feature (Phase 9.2), 1 gap found and fixed

Phase 9.2's hotel-logo upload (`SettingsController::uploadLogo()`) is the **first** file-upload feature in this
codebase — Phase 7's "no such feature exists" conclusion no longer holds. It correctly size-limits (2MB), uses a
random server-generated filename, and content-sniffs via Laravel's `image` rule.

**Finding 3 (fixed) — SVG was in the upload allowlist with no sanitization.** SVG is a scriptable XML format; a
crafted `logo.svg` containing inline `<script>` would be accepted, stored on the public disk, and served at a
direct, predictable-prefix URL. The only exploit path was a user navigating directly to that raw file URL (the
logo is only ever rendered via `<img src>` elsewhere, which does not execute embedded SVG script), and only
`manage-settings` holders (Owner/GM) can upload — low severity, but a real gap against the very guidance Phase 7
itself wrote down for whenever a file-upload feature arrived.
*Fix*: dropped `svg` from the `mimes:` allowlist — `png`/`jpg`/`jpeg` are the only formats this feature actually
needs. See `app/Http/Controllers/Admin/SettingsController.php`; already covered by the existing
`tests/Feature/Admin/SettingsAdminTest.php` non-image-rejection test in spirit (a one-line allowlist change, not a
new code path).

### 3.5 Rate limiting coverage — clean

`routes/sync.php` wraps all three transport endpoints in `throttle:60,1` — confirmed present, not merely claimed.
`routes/deploy.php` keeps its own pre-existing `throttle:10,1`. No admin/report/print/CSV route needs its own rate
limit beyond the standing session-based access control; the sync transport is the one genuinely new,
unauthenticated-by-session, network-reachable surface added this phase, and it is throttled.

### 3.6 Secret handling / config exposure — clean, plus one defensive hardening item applied elsewhere

- `.env.example` has no `SYNC_*` variable, correctly — device secrets are DB-resident (`sync_devices.shared_secret`,
  `encrypted` cast), not environment configuration, so there is nothing to document there.
- `SyncDevice::$hidden = ['shared_secret']` and the model casts it `'encrypted'` — both confirmed present; the
  admin sync dashboard never displays it.
- Every `SyncLogger` call across the engine passes only device relations, event names, and non-secret context
  (`type`, `ulid`) — no device secret or key is ever written to `sync_logs`.
- **Minor, non-blocking, fixed as a defensive measure**: the CSV exports built on `ExportsCsv` (Payment Summary,
  Expense Report, Guest Stay History) wrote every cell through `fputcsv()` verbatim. A cell value starting with
  `=`, `+`, `-`, or `@` is interpreted as a formula by Excel/Sheets/LibreOffice when opened, not as plain text —
  classic CSV/formula injection. These exports are already gated behind `view-financial-reports`/
  `view-operational-reports` (Owner/GM/Accountant/Duty Manager only), so real-world exposure was low, but the fix
  is free and closes the category fully rather than depending on every current and future export column being
  reviewed by hand: any such leading character is now prefixed with an apostrophe, which every spreadsheet
  application treats as "force text" with no visible change to the value. See
  `app/Http/Controllers/Concerns/ExportsCsv.php`; covered by `tests/Unit/Http/ExportsCsvTest.php` (3 methods).
- One operational (not code) observation: `SyncRunner` builds its outgoing request URL from whatever
  `endpoint_url` an operator registers with no scheme enforcement — an `http://` endpoint would carry the shared
  secret in cleartext on that leg. This is a deployment/TLS configuration matter, the same category Phase 7 itself
  placed out of scope for a static code review (see `INSTALLATION.md` §7's checklist, which calls this out as a
  go-live requirement).

---

## 4. What was actually run (and what wasn't) for this pass

**Run and verified, with real output:**
- `php -l` across all 306 PHP files under `app/`, `tests/`, `database/`, `routes/`, `config/` — zero syntax
  errors, both before and after every fix in §3 was applied.
- `verify_routes.py` — 190 named routes, 0 duplicates, 0 unresolved `route(...)` references, unchanged by this
  pass (no route was added or removed; every fix here changed authorization/validation logic, not routing).
- A comment-stripped parse of `RolesAndPermissionsSeeder::PERMISSIONS` — 62 unique entries, 0 duplicates.
- A full grep sweep for `{!! !!}`, `whereRaw`/`DB::statement`/family, and `fputcsv` across every file touched or
  added since the Phase 9.0 addendum.

**Written and manually traced, but NOT executed** (same standing sandbox limitation as every prior phase — no
Composer/Packagist access, no MySQL, see `TEST_REPORT.md`): the 6 new/updated test methods this pass added
(`OrderCrossOutletTest` — 6, two new `SyncServiceTest` methods, one new `SyncConflictResolverTest` method, 3 new
`ExportsCsvTest` methods) plus every pre-existing test in files this pass's fixes touch (`OrderTest`,
`SyncServiceTest`, `SyncConflictResolverTest`, `SettingsAdminTest`) — a full re-run of those specific files is the
single most valuable verification step available once a real environment exists:

```bash
php artisan test --filter=OrderCrossOutletTest
php artisan test --filter=OrderTest
php artisan test --filter=SyncServiceTest
php artisan test --filter=SyncConflictResolverTest
php artisan test --filter=ExportsCsvTest
php artisan test --filter=SettingsAdminTest
php artisan test   # full suite, to confirm no regression anywhere else
```

**Not performed, and out of scope for a manual code review regardless of environment**: an automated SAST/DAST
scan, `composer audit` (also blocked by the Packagist restriction), a real penetration test, or infrastructure/
hosting-level review (TLS configuration, firewall rules, the actual hosting account's settings) — see
`INSTALLATION.md` §7 for the pre-launch checklist covering exactly those items operationally.
