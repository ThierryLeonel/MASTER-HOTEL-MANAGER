# Installation Guide

This is the standalone installation reference for the Hotel Management System (HMS), covering three deployment
shapes: local development, a single production install on ordinary shared hosting, and the two-node hybrid setup
(a local server on-property + a cloud VPS) built in Phase 9.7. If you only need the fast path, `README.md` §4–§6
covers local development in a few commands; this document goes deeper and adds the shared-hosting and hybrid
paths README doesn't cover in full.

**What this document is, and isn't.** Every command below is the real command you would run — nothing here is
paraphrased or simplified away from what the code actually expects. **None of these commands have been executed
in the sandbox this project was built in** (see `TEST_REPORT.md` for why: no outbound access to Packagist, no
MySQL server). They are correct by inspection — cross-checked against `composer.json`'s declared dependencies,
every migration's actual schema, and the artisan commands' own source — not verified by a real run. Follow this
guide and you will find out for real; if any step doesn't work exactly as described, that's more informative than
this document glossing over the gap.

---

## 1. Requirements

```
PHP:                8.2 minimum (also runs on 8.3–8.5)
PHP extensions:     pdo_mysql, mbstring, openssl, tokenizer, xml, ctype, json, bcmath, fileinfo, gd (or imagick)
Database:           MySQL 8.0+ or MariaDB 10.6+
Composer:           2.x (needed to install/build locally and in CI; a shared-hosting target itself needs no
                     Composer if you upload a pre-built vendor/ directory — see §3)
Web server:         Apache or Nginx with PHP-FPM, or `php artisan serve` for local development only
Node.js:            not required — there is no frontend build step; every view is server-rendered Blade with
                     Tailwind loaded from a CDN `<link>`, no npm/webpack/vite pipeline exists in this project
```

Confirm what you actually have before starting:

```bash
php -v
php -m | grep -E 'pdo_mysql|mbstring|openssl|bcmath|gd'
composer --version
mysql --version
```

---

## 2. Getting the code

This project is not currently in a git repository in the environment it was built in (no `.git` directory) — it
ships as a plain directory tree. If your copy came as an archive, extract it; if you're setting up version control
yourself, `git init` and commit the tree as-is before making any local changes, so you have a clean baseline to
diff against later.

---

## 3. Local development install

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```
APP_ENV=local
APP_DEBUG=true
APP_URL=http://localhost:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=hms
DB_USERNAME=root
DB_PASSWORD=

SESSION_SECURE_COOKIE=false   # only false for plain-HTTP local dev — see §7's warning for anywhere else
```

Then:

```bash
php artisan migrate
php artisan db:seed
php artisan serve
```

Visit the URL `artisan serve` prints (typically `http://127.0.0.1:8000`) and sign in with one of the demo accounts
in README §8. `php artisan migrate:fresh --seed` rebuilds the schema and demo data from scratch at any point during
development — **never run this against a database holding real guest or financial history**, it drops every
table first.

Run the test suite (see `TEST_REPORT.md` for the full inventory of what it covers and honest execution status):

```bash
php artisan test
```

---

## 4. Production install on ordinary shared hosting (single node, no hybrid sync)

This is the target the Phase 1–8 spec was written for: cPanel-style shared hosting (the project was built against
InfinityFree as the reference target — see README §10 for that specific host's constraints) with no shell access
beyond what the host's file manager/Composer-in-cPanel tooling provides, no ability to run a persistent process,
and cron limited to the host's scheduler.

1. **Build `vendor/` somewhere you have Composer** (your own machine or CI — most shared hosts don't offer a full
   Composer environment): `composer install --no-dev --optimize-autoloader`. Upload the whole project tree,
   `vendor/` included, to the host.
2. **Point the host's document root at `public/`**, not the project root — this is the single most common shared-
   hosting misconfiguration for a Laravel app, and it exposes `.env` and the entire application source as static
   files if done wrong. If your host cannot set a subdirectory as the document root, follow Laravel's documented
   workaround of moving `public/`'s contents up a level and adjusting the two path references in its `index.php`.
3. **Create `.env`** from `.env.example` (see §6 for the full settings reference) with real production values:
   `APP_ENV=production`, `APP_DEBUG=false`, a freshly generated `APP_KEY` (`php artisan key:generate` run once,
   locally, then copy the value — don't regenerate it after go-live, every existing encrypted column and signed
   URL depends on it staying the same), real `DB_*` credentials for the database your host provisions, and
   `SESSION_SECURE_COOKIE=true` (the `.env.example` default, correct once you're actually on HTTPS — see §7).
4. **Run migrations and seed** the one time via whatever shell access your host offers (SSH, a cron-triggered
   artisan runner, or cPanel's "Run PHP script" tooling): `php artisan migrate --force` (the `--force` flag is
   required in a `production`-environment `.env` — Laravel refuses to run a migration against production without
   it as a safety rail) then `php artisan db:seed --force` for the first install only.
5. **Rotate every demo credential** from README §8 before anyone else can reach the site — the demo
   users/passwords/PINs are meant for evaluating the system, not for a real production login.
6. **Cron**, if your host offers it (needed only for the scheduled reports/integrity-check jobs, not for basic
   operation): point one cron entry at Laravel's scheduler, `* * * * * php /path/to/artisan schedule:run >>
   /dev/null 2>&1`, which then dispatches whatever's actually registered in the app's console kernel/scheduler at
   its own configured times — check `app/Console/Kernel.php` (or `routes/console.php` on Laravel 11+'s structure)
   for what's currently scheduled, since this project's schedule has grown across phases and this document isn't
   the source of truth for it.

This shape has **no** sync engine involved at all — it's a single database, single install, exactly like Phases
1–8 shipped. Skip straight to §7 (security) once it's live.

---

## 5. Hybrid install: local server + cloud VPS (Phase 9.7)

This is the topology `HYBRID_ARCHITECTURE.md` describes in full — read that document for the design; this section
is just the install sequence. In short: two **independent, fully-functional** installs of this same application,
each with its own database, kept eventually-consistent by a pull/push sync engine rather than sharing one
database over the network. Use this shape when the property needs to keep operating (POS, check-in, folios) during
an internet outage on a local server, with a cloud VPS as the durable off-site copy and the online-anywhere access
point.

### 5.1 Install both nodes

Follow §3 (or §4, if the cloud VPS is itself going to be reached over the public internet rather than just via
this project's own sync transport) **twice, completely independently** — two separate `composer install`s, two
separate `.env` files pointing at two separate databases, two separate `php artisan migrate --seed` runs. Do not
point both installs at the same database; that defeats the entire purpose of this topology and the sync engine
has no logic for that case (see `HYBRID_ARCHITECTURE.md` §2 for why the two databases must be genuinely
independent, starting from the ULID-based primary-key design). Rotate the demo credentials on **both** installs
before going live, exactly as in §4 step 5 — they are seeded identically on both by design (`RolesAndPermissionsSeeder`
is deterministic), which is a deliberate simplification for setup, not a claim that a shared demo password is safe
to keep on a live property.

### 5.2 Register each side as the other's sync device

`php artisan sync:register-device` (on either node) creates a new `SyncDevice` row and prints a **device key** and
a **shared secret** — the secret is shown exactly once, in plaintext, at creation time (it's encrypted at rest
from that point on and never displayed again anywhere in the app, including the admin sync dashboard). Copy both
values immediately.

```bash
# On the local server, registering the cloud VPS as its sync counterpart
# ("name" is a positional argument, not an option). --endpoint is the
# VPS's BASE URL — SyncRunner appends /sync/push, /sync/pull,
# /sync/acknowledge itself, so do NOT include /sync here:
php artisan sync:register-device "Cloud VPS" --endpoint="https://your-vps-domain"

# On the cloud VPS, registering the local server as ITS counterpart, importing
# the SAME key/secret pair the local server was just given for itself — this
# is the one place the two sides share a value on purpose, so each side can
# authenticate the other's push/pull calls with the same credential:
php artisan sync:register-device "Local Server" --import-key="<the device_key from above>" --import-secret="<the shared_secret from above>"
```

Repeat in the other direction if you want each side to be able to *initiate* a push/pull against the other (an
`endpoint_url`-less device can still receive an incoming call, but can't be the one to dial out) — see
`HYBRID_ARCHITECTURE.md` §3 for exactly what `endpoint_url` does and doesn't gate. A device with no working
network path in one direction is a legitimate, supported configuration (e.g. the local server is behind a NAT/
firewall with no public endpoint, so only the cloud VPS initiates pulls from it) — it just changes which side runs
`sync:run`.

### 5.3 Schedule the sync loop

`php artisan sync:run` is the actual work — it loops every `is_active` device with an `endpoint_url` set and
pushes+pulls against it. This needs to run repeatedly and unattended, so it belongs on whichever side(s) actually
have an `endpoint_url` configured for their counterpart, via cron:

```
*/5 * * * * cd /path/to/hms && php artisan sync:run >> storage/logs/sync-run.log 2>&1
```

(Every 5 minutes is a starting point, not a requirement — pick an interval that matches how fresh the two sides
need to be relative to each other; this project places no hard floor or ceiling on it.) `sync:run` exits non-zero
if any device's push or pull failed, so a cron-monitoring tool (or a simple `&& echo ok || mail -s "sync failed"
you@example.com` wrapper) can alert on a real failure rather than silently accumulating an ever-larger backlog.

### 5.4 Verify

Once both sides are seeded, registered, and the cron is running:

1. Create a test record on one side (e.g. a `Department`) and wait for the next `sync:run` cycle (or trigger it
   manually: `php artisan sync:run`) — it should appear on the other side.
2. Open `/admin/sync` (the `manage-sync` permission, held by Owner/GM/Duty Manager) on either install — it lists
   both registered devices, each device's last-seen time, and both directions' last-synced checkpoints.
3. Deliberately create a genuine conflict (edit the *same* record differently on both sides between two sync
   cycles) to confirm `/admin/sync/conflicts` picks it up and that resolving it (kept_local/kept_incoming/merged/
   ignored) behaves as `HYBRID_ARCHITECTURE.md` §5 describes.

**Not verified in this sandbox**: none of steps 5.2–5.4 above has actually been run against two real, networked
Laravel installs and a live MySQL pair — there is no second server or live database available here (see §2's
standing sandbox-limitation note, restated in `TEST_REPORT.md`). The commands are correct against the actual
`SyncTransportController`/`SyncRunner`/artisan-command source, not against an observed real run.

---

## 6. Configuration reference

Business policy is **not hardcoded** — it lives in the `settings` table (`App\Models\Setting`, read through
`App\Services\SettingsService`), seeded from `.env` on first install by `SettingsSeeder`, and editable afterward
through Administration → Settings (no code deploy needed for a policy change once the system is live) or directly
via `php artisan tinker` before that screen existed. See README §5 for the full settings table and §9 for the
permission matrix controlling who can change what. `.env` itself only matters for infrastructure-level
configuration (`APP_*`, `DB_*`, `SESSION_*`) and the one-time seed values for the `settings` table — after seeding,
changing an `HMS_*` `.env` variable has no further effect; change the setting through the app instead.

---

## 7. Security checklist before going live (either shape)

This repeats guidance that also lives in `SECURITY.md` — collected here as a pre-launch checklist rather than an
audit narrative:

- `APP_ENV=production`, `APP_DEBUG=false` — a `true` debug flag on a live site leaks stack traces, `.env` values,
  and query bindings to any visitor who triggers an error.
- `SESSION_SECURE_COOKIE=true` — the session cookie is a bearer credential for every logged-in account; this
  ensures it never travels over plain HTTP. Only ever set this `false` for local HTTP-only development.
- A real `APP_KEY`, generated once and never regenerated after real data exists — every `encrypted`/`'hashed'`
  cast column (passwords, PINs, TOTP secrets, sync device secrets) depends on it.
- Every demo account's password/PIN from README §8 rotated — on **every** installed node in a hybrid setup, since
  the seeder provisions them identically on each.
- HTTPS actually terminated in front of the app (at the load balancer, reverse proxy, or the shared host's own
  TLS layer) — this project's own code cannot enforce transport security; it can only assume it's there once
  `SESSION_SECURE_COOKIE` is on.
- In a hybrid install, each `sync_devices.shared_secret` treated as a full-trust credential to that installation's
  database, not a low-value API key — see `HYBRID_ARCHITECTURE.md` §6 and `SECURITY.md` for exactly what a
  compromised one can and cannot do after the Phase 9.8 security re-audit fixes.

`SECURITY.md` is the full audit trail behind this checklist, including what was found and fixed at each phase and
what remains an open, documented trade-off rather than a fixed bug.
