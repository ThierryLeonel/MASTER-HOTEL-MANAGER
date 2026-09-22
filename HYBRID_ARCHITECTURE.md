# Hybrid Architecture: Local Server + Cloud VPS

This document describes the design of Phase 9.7's hybrid sync engine — the piece that lets this Hotel Management
System run as **two independent, fully-functional installations** (typically a local server on-property and a
cloud VPS) that stay eventually consistent with each other, rather than one install with a network dependency
baked into every request. For installation steps, see `INSTALLATION.md` §5. For the security properties of this
design (and what a compromised sync credential can and cannot do), see `SECURITY.md`.

---

## 1. Why this shape, not shared-database or real-time replication

A single-property hotel cannot stop operating — checking guests in, running the bar/restaurant/front-desk POS,
posting folio charges — because an internet link went down. Two designs were considered and rejected in favor of
this one:

- **One database, reached over the network from both a local terminal and a cloud app.** Rejected: the moment the
  link is down, the local server (where day-to-day operations actually happen) is down too. This defeats the
  entire point.
- **Real-time bidirectional database replication** (e.g. MySQL master-master). Rejected as unnecessarily complex
  for this project's actual requirement and its actual sandbox constraints (no ability to configure or test real
  MySQL replication topology here — see `TEST_REPORT.md`), and because true multi-master replication has its own
  well-known conflict semantics that would need to be reasoned about at the database engine level rather than the
  application level, where this project's existing patterns (services, `HmsException`, `AuditLogger`) already
  live.

Instead: **two ordinary, complete installations**, each with its own database, each able to run 100% of the
system's functionality entirely offline, kept **eventually consistent** by a periodic pull/push exchange this
document describes. Neither side is "the real one" — both are real, both can be written to independently, and the
sync engine's job is to reconcile that without silently losing data.

---

## 2. Data model: why every table needed a ULID

**The problem.** Every table in this project uses an auto-increment integer primary key (`id`). Auto-increment
counters are local to one database — if the local server creates `orders.id = 1` and the cloud VPS, independently,
also creates its own `orders.id = 1` before the two have ever synced, those are two different real orders that
now collide on the same identity the moment they try to reconcile.

**The fix (Phase 9.7.1).** Every syncable table gained an additive `ulid` column: a
[ULID](https://github.com/ulid/spec) (Universally Unique Lexicographically-sortable IDentifier — 128 bits,
Crockford-base32 encoded, sortable by creation time) assigned automatically on `create()` via a shared
`HasSyncUlid` trait, nullable (so already-existing rows aren't broken by the migration — they get backfilled by
`php artisan sync:backfill-ulids`, a separate, idempotent, one-time command) and unique across non-null values.
Critically, this is **additive, not a primary-key replacement**: `id` stays the real primary key, every foreign
key in the schema keeps pointing at `id` unchanged, and application code everywhere else is completely unaware
this column exists. `ulid` exists for exactly one purpose: it's the identity the sync engine uses to recognize
"this is the same real-world record" across two databases that independently assigned it two different `id`
values.

**Which tables.** `App\Services\Sync\SyncableModels::ALL` is the single source of truth — currently 48 of the
project's 55 Eloquent models. Excluded: `Setting` (administered per-installation, changes rarely, deliberately out
of scope — see that class's own migration docblock for the full reasoning) and every framework/infrastructure
table (`sessions`, `cache`, `jobs`, spatie/laravel-permission's role/permission tables — roles are fixed and
provisioned identically on both sides by `RolesAndPermissionsSeeder`, not runtime-synced data).

---

## 3. The engine: outbox, checkpoints, and apply (`App\Services\Sync\SyncService`)

`SyncService` contains all of the actual sync logic and talks to no network — it operates purely on the local
database and is fully unit-tested that way (`tests/Unit/Sync/SyncServiceTest.php`).

**Building an outbound batch** (`buildOutboxBatch()`): scans every table in `SyncableModels::ALL` for rows with a
non-null `ulid` whose `updated_at` is newer than this device's outbound checkpoint (or every such row, on the
first-ever sync), and packages them into a `SyncBatch` (`status = pending`). Each item is `{type, ulid, operation,
data}` — `operation` is `update` for anything not soft-deleted and `delete` for anything that is (see §3.1 on
deletes); `data` is the row's raw stored attributes via `exportableAttributes()`, with any of that model's
**protected attributes** stripped first (see §6 — this is the credential-leak fix from the Phase 9.8 security
re-audit).

**The checkpoint is only advanced on acknowledgement** (`acknowledgeOutboxBatch()`), never at build time. This
means: if a batch is built, sent, but the recipient never confirms receipt (network failure mid-transfer, the
recipient crashes before storing it), the next `buildOutboxBatch()` call includes exactly the same rows again
— nothing is silently skipped because a delivery could not be confirmed. Acknowledging an already-acknowledged
batch is a no-op, not a re-advance, so a retried acknowledgement can never accidentally jump the checkpoint past
changes made in the interval between two acknowledgement calls.

**Applying an inbound batch** (`applyIncomingBatch()` → `applyItem()` → `applyUpsert()`/`applyDelete()`): for each
item, looks up the local row by `ulid` (not `id`). If it doesn't exist locally, it's created. If it does, and the
local row's own `updated_at` is *newer* than this device's last-acknowledged inbound checkpoint for the sending
device, that means **this installation changed the same record independently, after the last time it heard from
that sender** — a genuine conflict, not just two sides innocently both having a copy. Rather than picking a winner
automatically, the local row is left completely untouched and a `SyncConflict` row is created holding both
versions (`local_data`/`incoming_data`) for a human to resolve later (§5). Every other, non-conflicting update
applies via `forceFill()` (deliberately bypassing `$fillable`, since a synced row's `data` is trusted, structural
payload data, not raw user input — with the one deliberate exception carved out in §6), excluding `id` (the local
auto-increment PK, never copied across installations), `ulid` (already known, handled separately), and
`created_at`/`updated_at` (left to Eloquent's own timestamp management, which is exactly what keeps the conflict
check above meaningful — a row's `updated_at` always reflects "the last time *this* installation touched it,"
sync applies included).

Every operation is recorded on a `SyncItem` row with a status (`applied`/`conflict`/`skipped`/`failed`) — a batch
partially failing (one bad item) never aborts the rest of the batch; each item's outcome is independent, and
`replaying` an already-applied batch ULID is a no-op rather than double-applying.

### 3.1 Deletes are conservative by design

A model with `SoftDeletes` (e.g. `Guest`, `User`) still has a row to find and flag `deleted_at` on — that
propagates like any other field change. Every other syncable model has no tombstone at all: this design (built
entirely around scanning rows that still exist) has no way to even detect a hard delete on the sending side, so
`buildOutboxBatch()` never emits a `delete` operation for one. If a `delete` operation for a non-soft-deleting
model is ever received anyway, it is explicitly **skipped with a recorded reason**, not silently ignored and not
performed — unconditionally deleting a business/financial record via an automated background process is exactly
the kind of silent data loss this whole engine exists to prevent.

---

## 4. Transport: three endpoints, no session (`routes/sync.php`, `SyncTransportController`)

`POST /sync/push`, `POST /sync/pull`, `POST /sync/acknowledge` — registered outside the `web` middleware group
entirely (from `bootstrap/app.php`'s `then:` callback, the same precedent as the pre-existing deploy helper's
`routes/deploy.php`), because this is server-to-server JSON traffic between two trusted installations, not a
browser with a logged-in session: no CSRF token exists to check, and a session/cookie stack would only get in the
way. Rate-limited at `throttle:60,1` — generous relative to the deploy helper's `10,1`, since a real sync schedule
legitimately calls these endpoints on every scheduled run, not once during a manual install.

- **`push`** — the caller hands over its own outbound batch; this installation applies it as an inbound batch and
  reports back a per-status breakdown (`applied`/`conflicts`/`skipped`/`failed` counts). A batch containing
  conflicts or failures is still a `200` — that's an expected, recorded outcome at the sync-logic level, not a
  transport error.
- **`pull`** — the caller is asking this installation to hand over its own outbound batch. This only *builds* the
  batch; it does **not** advance this installation's outbound checkpoint (see §3 — checkpoints only advance on
  acknowledgement).
- **`acknowledge`** — confirms a previously-pulled batch was safely received, advancing the checkpoint. Idempotent.

Every request carries `device_key` + `secret` in the JSON body, verified per-request by
`DeviceAuthService::authenticate()` — not a Laravel auth guard, since there is no logged-in user on either end,
only two installations that agree on a shared secret (`hash_equals()`-based, timing-safe comparison; a bad or
missing credential always returns the same generic `401`, never a hint about which half was wrong). See §6 for
what that secret actually trusts a caller with.

### 4.1 `SyncRunner` and `sync:run` — the actual cron entry point

`SyncRunner::runFor(SyncDevice $device)` drives both directions against one counterpart in a single pass: **push**
(skips the network call entirely if the local outbox is empty for that device, but still advances the local
checkpoint locally so the next run scans forward from the right point) then **pull** (always calls through, even
on an empty reply, and always acknowledges — skipping the acknowledge on an empty reply would leave the
counterpart's own outbound checkpoint stuck, forcing it to keep re-scanning from the same old point forever).
`php artisan sync:run` loops every `is_active` device that has an `endpoint_url` configured (a device with none
can still receive an incoming call — it just can't be the one to initiate one) and exits non-zero if any device's
push or pull failed, the same cron/CI-friendly contract `integrity:check` already established in Phase 9.6. See
`INSTALLATION.md` §5.3 for the cron entry itself.

---

## 5. Conflicts: detection and resolution

Detection is described in §3 — a `SyncConflict` row is created, never auto-resolved by anything, whenever an
incoming update would overwrite a local row that changed independently since this installation last heard from
that sender. `App\Services\Sync\SyncConflictResolver` is the **sole** place a conflict can actually be resolved
(matching this project's established single-writer convention, e.g. `AuditLogger`), reachable through the
`manage-sync`-gated admin dashboard (§7) or directly. Four resolutions, one enum, set once in the Phase 9.7.2
migration and left unused until 9.7.6:

- **`kept_local`** — no data change; the local row was never actually touched by the conflicting apply in the
  first place, so "keeping it" just marks the conflict resolved.
- **`kept_incoming`** — the only resolution that mutates anything: applies the held-back `incoming_data` onto the
  local row now, via the same `forceFill()`-and-exclude approach `applyUpsert()` itself uses (including the
  protected-attributes exclusion from §6).
- **`merged`** — a human has already reconciled the two versions by hand, outside this flow; no automatic
  field-by-field merge is attempted here, since guessing at one could produce a value neither side ever actually
  had.
- **`ignored`** — deliberately leave both versions as history with no data change.

Every resolution writes one `AuditLogger` entry. An already-resolved conflict, an unrecognized resolution string,
or `kept_incoming` against a local row that's since been deleted all throw a clear `HmsException`.
`DataIntegrityCheckerService` (Phase 9.6, extended in 9.7.6) flags any conflict left unresolved for more than 7
days as an actionable integrity issue — a stale conflict means two installations' data on that one record has been
silently diverging, unattended, since detection.

---

## 6. Security model: what a device secret actually trusts

Read this section together with `SECURITY.md`'s sync-engine findings — this is the design context behind them.

A `sync_devices.shared_secret` is not a scoped, low-privilege API key. Because `applyUpsert()` uses `forceFill()`
(deliberately bypassing normal mass-assignment protection, so structural sync data can update any column a
legitimate business action could), **anyone who holds a valid device secret can write to any column of any of the
48 syncable models on the receiving installation** — the same level of trust as full database write access, not
merely "replicate hotel business records." Treat each `shared_secret` accordingly: it's generated with
`Str::random(48)` and stored `encrypted` at rest (never displayed again after the one-time `sync:register-device`
output), but its *value*, once known, is as sensitive as a database root password.

**The one deliberate exception carved out of that blanket trust** (added in the Phase 9.8 security re-audit,
`app/Services/Sync/SyncableModels::PROTECTED_ATTRIBUTES`): `User`'s own login-credential columns — `password`,
`pin_hash`, `totp_secret`, `totp_recovery_codes`, `remember_token`, `failed_pin_attempts`, `pin_locked_until` — are
excluded from every stage of the sync pipeline: never included in an outbound payload, never applied on the
receiving side even if a payload somehow contains them anyway, and never captured in a `SyncConflict` snapshot.
Before this fix, a compromised device secret could rewrite another account's password/PIN/TOTP secret directly —
a full account-takeover path — and credential hashes could sit in cleartext-JSON inside `sync_conflicts`, visible
to any `manage-sync` holder (Duty Manager included) via the admin dashboard. The practical effect: **user accounts
are provisioned identically on both installations by the same seeder, but credentials are managed
per-installation from that point on** — resetting a PIN on the local server does not propagate to the cloud VPS
copy of that same account, and a local admin sets it separately on each side. `is_active`/`department_id`/`name`/
etc. still sync normally, so e.g. disabling a terminated employee's account on one side still locks them out of
the other.

**Revocation**: setting a `SyncDevice.is_active = false` (via `/admin/sync`) immediately blocks that device from
authenticating on any of the three transport endpoints — the correct first response to a suspected leaked secret,
followed by registering a fresh device/secret pair.

---

## 7. The admin dashboard (`/admin/sync`, Phase 9.7.6)

Gated behind a dedicated `manage-sync` permission (Owner/GM/Duty Manager), not the `manage-settings` permission
every other admin screen uses — this is operational monitoring for a specific subsystem, not general
configuration.

- **`GET /admin/sync`** — every registered device: active/revoked state, last-seen time, both checkpoints' last-
  synced times, and a pending-conflict count per device.
- **`GET /admin/sync/conflicts`** — every unresolved conflict, local vs. incoming data shown side by side (now
  scrubbed of any credential columns per §6), with a resolution form per conflict.
- **`POST /admin/sync/conflicts/{conflict}/resolve`** — the only place a conflict is actually resolved; delegates
  entirely to `SyncConflictResolver` (§5).

---

## 8. Known limitations (documented, not silently glossed over)

- **No real-time consistency.** This is eventually-consistent, on whatever schedule `sync:run` is cron'd at (see
  `INSTALLATION.md` §5.3). Two staff members on different installations can both act on stale information between
  sync cycles — e.g. both see a room as available and both start a check-in — until the next cycle surfaces the
  conflict. This is an inherent trade-off of the "keep operating offline" requirement, not an oversight.
- **Hard deletes never propagate** (§3.1) — by design, not as a gap to fix later.
- **`Setting` never syncs** — each installation's settings are managed independently. If the two installations'
  tax rate, deposit percentage, or other policy setting diverge, nothing in this engine will notice or reconcile
  that; it's an operational discipline question (keep both sides' Settings screens in agreement by hand), not a
  code gap.
- **No automatic conflict resolution heuristic** (e.g. "last write wins," or a field-level three-way merge) is
  implemented or planned — every genuine conflict requires a human decision, by design (§5).
- **Not verified against a real two-server, real-MySQL deployment** in the sandbox this project was built in — see
  `TEST_REPORT.md` for the complete, honest breakdown of what's been proven by code review versus what genuinely
  needs a live run to confirm.
