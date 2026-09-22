# Deploying the Corrected HMS 14 From Scratch (Laravel Cloud)

This is the exact, point-by-point sequence for taking this corrected build (the one in this same folder/zip) and
standing up a brand-new production environment on Laravel Cloud. It folds in everything actually found and fixed
while getting the real production deployment working during this correction round: the missing `storage/framework`
placeholder directories (500 error on every page), and the Object Storage bucket requirement (broken logo/favicon
image). Follow it in order — most of the pain in the last round came from doing the storage-bucket step *after*
the first deploy instead of before it.

If you're deploying to ordinary shared hosting or the local-server + cloud-VPS hybrid setup instead of Laravel
Cloud, use `INSTALLATION.md` §4/§5 — this document is Laravel-Cloud-specific.

---

## 1. Before you start

- A Laravel Cloud account with a team/project you can create environments under.
- This corrected code, pushed to a **git repository** (GitHub/GitLab/Bitbucket) — Laravel Cloud deploys from a git
  branch, not from a manually-uploaded zip. If this folder isn't a git repo yet:
  ```bash
  git init
  git add .
  git commit -m "HMS 14 corrected build"
  git remote add origin <your-repo-url>
  git push -u origin main
  ```
- Nothing else needs installing locally — Laravel Cloud runs `composer install` for you during its own build step.

---

## 2. Create the environment

- In the Laravel Cloud dashboard: **New Project** (or an environment inside an existing project) → connect it to
  the git repository and branch from step 1.
- Choose **PHP** / **Laravel** as the application type when prompted.
- Don't trigger the first deploy yet — steps 3–5 below (database, storage bucket, environment variables) should be
  in place *before* the first deploy so you don't need a second redeploy immediately after the first one.

---

## 3. Attach a database

- On the environment's infrastructure canvas: **Add database** → **MySQL** (this project only supports
  MySQL/MariaDB — see `INSTALLATION.md` §1).
- Laravel Cloud provisions it and injects the `DB_*` connection variables into the environment automatically — you
  don't need to type in a host/username/password by hand.

---

## 4. Attach Object Storage (do this now, not after the first deploy)

This step is what the logo/favicon feature depends on. Skipping it (or doing it after the first deploy without a
redeploy) is exactly what caused the broken-logo issue this round — see `CORRECTION_ROUND_34_DELIVERY.md` §3.9 for
the full postmortem.

- On the same infrastructure canvas: **Add bucket**.
- Either create a new bucket or select one you already made. Choose **"Laravel Object Storage"** as the bucket
  type.
- **Disk name: exactly `public`.** This has to match, character for character — this codebase calls
  `Storage::disk('public')` everywhere (logo upload, favicon, and anywhere else file storage is used). A
  different disk name means Laravel Cloud injects the bucket's config under a different key and this app never
  sees it.
- **Visibility: Public.** The logo is displayed in a plain `<img>` tag with no signed URL, so it needs to be
  publicly readable.
- You do **not** need to add `league/flysystem-aws-s3-v3` to `composer.json` yourself — it's already in this
  build's `require` block.

---

## 5. Set environment variables

Laravel Cloud gives you an environment-variables screen for the app (separate from the database/bucket variables
it injects automatically). Set these — see `.env.example` in this folder for the full reference list with
comments:

- `APP_NAME="Hotel Management System"` (or your hotel's actual name)
- `APP_ENV=production`
- `APP_DEBUG=false`
- `APP_URL=` your real production URL (e.g. `https://your-app.laravel.cloud` or a custom domain once attached)
- `SESSION_SECURE_COOKIE=true`
- `HMS_CURRENCY`, `HMS_TAX_RATE_PERCENT`, `HMS_DEPOSIT_PERCENT`, and the other `HMS_*` values if you want
  different first-install defaults than `.env.example` ships with — these only seed the `settings` table once;
  after that, change them through Administration → Settings instead, not here.
- Leave `APP_KEY` blank for now — it gets generated in step 7.
- Leave `ENABLE_DEPLOY_HELPER=false` (its default) — that feature is for shared hosting with no shell access,
  which doesn't apply here since Laravel Cloud gives you a Commands tab.

---

## 6. First deploy

- Trigger the deploy from the Laravel Cloud dashboard.
- This runs `composer install --no-dev --optimize-autoloader` (or equivalent) against `composer.json`, including
  the `league/flysystem-aws-s3-v3` package this build already declares.
- Wait for it to report a successful build before moving to step 7.

---

## 7. One-time setup commands

Run each of these from the environment's **Commands** tab, in this order:

```
php artisan key:generate --force
php artisan migrate --force
php artisan db:seed --force
php artisan storage:link --force
```

- `key:generate --force` — generates and stores `APP_KEY`. Run this once, here, and never again after real data
  exists (every encrypted column and signed URL depends on it staying the same).
- `migrate --force` — the `--force` flag is required because `APP_ENV=production` refuses migrations without it,
  as a safety rail. Migrations are already correctly timestamped to run in order.
- `db:seed --force` — runs `DatabaseSeeder`, which seeds roles/permissions, departments, settings, demo users,
  rooms, guests, POS/menu data, facilities, financial accounts, and HR data, in the correct dependency order. This
  also creates the **demo accounts** listed in `README.md` §8 — see step 9 below, you must rotate these before
  going live.
- `storage:link --force` — creates the `public/storage` symlink. Needed even though the `public` disk is now
  bucket-backed, because some parts of the app may still resolve local-relative paths.

---

## 8. Verify Object Storage is actually wired up

Don't skip this — a bucket that's merely *created* isn't the same as a bucket that's *attached and live*. Run:

```
php artisan tinker --execute="echo json_encode(config('filesystems.disks'));"
```

- Look at the `"public"` entry in the output. It must show `"driver":"s3"` with real (non-null) `bucket`, `key`,
  `secret`, and `endpoint` values.
- If `"public"` still shows `"driver":"local"`, the bucket isn't attached to this environment yet (or was attached
  without a redeploy afterward) — go back to step 4, confirm the bucket shows as attached to *this* environment on
  the infrastructure canvas, then redeploy and re-run this check.

---

## 9. Rotate every demo credential immediately

`db:seed` provisions the demo accounts documented in `README.md` §8 with known passwords/PINs, meant for
evaluating the system, not for production use.

- Log in as each demo account (or as an Owner/General Manager) and change every password/PIN before anyone else
  can reach the site.
- This is the single most important step on this whole list to not skip.

---

## 10. Upload the real hotel logo (also becomes the favicon)

- Log in as an Owner/General Manager and go to **Administration → Settings**.
- Upload your hotel's logo under **"Hotel logo"** (PNG or JPG, under 2 MB). There's no separate favicon upload —
  the same image is read directly by the favicon `<link>` tag on every page, so it becomes the browser tab icon
  automatically too. A roughly square image looks cleanest as a small tab icon.
- Do a hard refresh (or open an incognito window) if the favicon doesn't update right away — browsers cache
  favicons aggressively.

---

## 11. Final verification pass

- Visit the site's real URL and confirm `/login` loads without a 500 (this is what the missing
  `storage/framework` placeholder directories broke before this build — already fixed here, nothing to do, just
  confirm).
- Log in and confirm the dashboard loads for at least one role.
- Run through `INSTALLATION.md` §7's security checklist: `APP_ENV=production`, `APP_DEBUG=false`,
  `SESSION_SECURE_COOKIE=true`, real `APP_KEY` in place, demo credentials rotated, HTTPS actually terminated in
  front of the app (Laravel Cloud does this for you on its own domains and any custom domain you attach).
- Optionally run `php artisan integrity:check` (Phase 9.6) to confirm the freshly-seeded data is internally
  consistent — expect a clean pass on a brand-new install.
- If cron-dependent features matter to you (scheduled reports, integrity checks), confirm Laravel Cloud's
  scheduler is running against this environment — it doesn't need a manual cron entry the way shared hosting does
  (see `INSTALLATION.md` §4 step 6 for what shared hosting requires instead).

---

## 12. What's already fixed in this build (context, not action items)

Nothing to do here — this section just explains why steps above work the way they do, in case something doesn't
go as expected:

- **`storage/framework/{cache,cache/data,sessions,views}` placeholder directories** — added so Laravel's Blade
  compiled-view cache path resolves correctly on a truly fresh checkout. Without these, every page 500s with
  `"Please provide a valid cache path."` on first render. See `CORRECTION_ROUND_34_DELIVERY.md` §3.8.
- **`config/filesystems.php`'s `public` disk** — supports a manually-configured `AWS_BUCKET`-style S3 setup as a
  generic fallback (useful outside Laravel Cloud). On Laravel Cloud itself, the bucket is actually wired up by
  Laravel's own framework code (`Illuminate\Foundation\Cloud`), which reads Laravel Cloud's injected
  `LARAVEL_CLOUD_DISK_CONFIG` variable and overwrites the `public` disk's config automatically — which is why step
  4's disk name has to be exactly `public`. See `CORRECTION_ROUND_34_DELIVERY.md` §3.9.
- **`composer.json`** already includes `league/flysystem-aws-s3-v3`, the package the `s3` driver needs — no
  manual `composer require` step is needed on your part.
