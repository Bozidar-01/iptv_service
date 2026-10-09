# Deployment: shared hosting (e.g. Hostinger)

Shared hosting (Hostinger Premium/Business/Cloud, cPanel-style hosts) has PHP, MySQL, SSH and cron, but no long-running processes. The app works there because:

- `/play` only redirects to the provider, so video never passes through the hosting.
- Imports stream the playlist line by line and write 500 rows at a time, so memory stays low.
- No Node build is needed: the panel's CSS is plain `public/css/admin.css`.
- The queue worker can run from the scheduler (`QUEUE_SCHEDULED_WORKER=true`), so a **single cron job** handles imports, auto-refresh and session pruning.

For a VPS, use the Docker stack instead ([getting-started.md](getting-started.md)).

## 1. Upload the code

Put the project **outside** `public_html`, e.g. with SSH + git, or hPanel → Git:

```bash
cd ~/domains/yourdomain.com
git clone <repo-url> nebotv
```

Point the web root at `public/` (Laravel must not serve the project root):

```bash
cd ~/domains/yourdomain.com && rm -rf public_html && ln -s nebotv/public public_html
```

## 2. PHP and database

- hPanel → Advanced → PHP Configuration: **PHP 8.4** (8.3 minimum).
- hPanel → Databases: create a MySQL database and user; note name, user, password.

## 3. Install and configure

```bash
cd ~/domains/yourdomain.com/nebotv
composer install --no-dev --optimize-autoloader
cp .env.example .env
php artisan key:generate
```

Edit `.env`:

```dotenv
APP_ENV=production
APP_DEBUG=false
APP_URL=https://yourdomain.com
DB_HOST=localhost
DB_DATABASE=...
DB_USERNAME=...
DB_PASSWORD=...
QUEUE_CONNECTION=database
DB_QUEUE_RETRY_AFTER=900
QUEUE_SCHEDULED_WORKER=true
ADMIN_USERNAME=yourname
ADMIN_PASSWORD=a-long-password
# Alert emails via Brevo: see features/alerts.md#email-with-brevo
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=...
MAIL_PASSWORD=...
MAIL_FROM_ADDRESS=...
ALERT_MAIL_TO=you@example.com
```

Then:

```bash
php artisan migrate --force
php artisan db:seed --force      # creates the admin (ADMIN_USERNAME / ADMIN_PASSWORD, or a random password it prints) and, on an empty database, the default sources
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

Re-run the `*:cache` commands after every `.env` change or code update.

## 4. Cron (one job)

hPanel → Advanced → Cron Jobs → every minute (`* * * * *`):

```
cd ~/domains/yourdomain.com/nebotv && php artisan schedule:run >> /dev/null 2>&1
```

If hPanel asks for a PHP binary, use the full path it suggests (e.g. `/usr/bin/php` or `/opt/alt/php84/usr/bin/php`) instead of `php`.

This runs everything in `routes/console.php`:

| Task | When |
|---|---|
| `queue:work --stop-when-empty --tries=1 --timeout=600 --max-time=240` (only with `QUEUE_SCHEDULED_WORKER=true`) | every minute, never two at once (15-minute lock), in the background |
| `iptv:sync-sources --due` | hourly |
| `iptv:check-streams` / `iptv:check-streams --watched` | daily at 04:00 / every 15 minutes (queued) |
| `iptv:send-alerts` | every 5 minutes |
| `iptv:heartbeat` | every minute (feeds `/health` and the dashboard warning) |
| `iptv:check-receivers` | every 5 minutes (satellite receiver relays) |
| `iptv:sync-epg` | daily at 03:30 (TV guides) |
| `iptv:expiry-reminders` | daily at 08:00 |
| `model:prune` for `ActiveSession`, `StreamLog` and `LoginEvent` | every 15 minutes |
| `iptv:backup` | daily at 02:30 |

A newly added source starts importing within about a minute.

**Monitoring:** add a free monitor (e.g. UptimeRobot, HTTP(s) type, every 5 minutes) for `https://yourdomain.com/health`. It returns `503` when the cron job or the queue stops, so you get an email even though the app itself can't send one then.

## 5. Updating

With [Automatic deploy](#automatic-deploy) set up this happens by itself on every push to `main`. By hand:

```bash
cd ~/domains/yourdomain.com/nebotv
git pull
composer install --no-dev --optimize-autoloader
php artisan migrate --force
php artisan config:cache && php artisan route:cache && php artisan view:cache
```

## 6. Backups

`php artisan iptv:backup` runs every night at 02:30 (the same cron job). It writes a gzipped SQL dump to `storage/app/private/backups/iptv-YYYYMMDD-HHMMSS.sql.gz` and deletes dumps older than `BACKUP_KEEP_DAYS` (14, the newest is always kept). The dump has all your data except what the app rebuilds by itself (queue, cache, sessions, TV guide programmes, which the nightly guide import refills). If it fails, an alert appears in the panel and email.

- **Needs `mysqldump`** on the server (Hostinger has it; the Docker image installs it). Set `BACKUP_MYSQLDUMP=/full/path` if it isn't on the PATH, and `BACKUP_DUMP_OPTIONS=--skip-ssl` if the database uses a self-signed certificate (the Docker setup does this).
- **Keep a copy off the server.** A backup on the same disk doesn't help when the disk is lost. Set `BACKUP_COPY_DISK=s3` and every backup is also written to an S3-compatible bucket (the driver is installed; the local copy stays). Cheap or free options: **Backblaze B2** (10 GB free), **Cloudflare R2** (10 GB free), Wasabi, or AWS S3. Create a private bucket and a key that can only write to it, then in `.env`:

```
BACKUP_COPY_DISK=s3
AWS_ACCESS_KEY_ID=...
AWS_SECRET_ACCESS_KEY=...
AWS_BUCKET=nebotv-backups
AWS_DEFAULT_REGION=eu-central-003            # B2: the region in the endpoint; R2: auto
AWS_ENDPOINT=https://s3.eu-central-003.backblazeb2.com   # leave empty for AWS S3
AWS_USE_PATH_STYLE_ENDPOINT=true             # B2 / R2 / Wasabi: true; AWS S3: false
```

  then `php artisan config:cache` and `php artisan iptv:backup`: it must print `Backup written` and the file must appear in the bucket. If the copy fails (wrong key, bucket, endpoint) the command fails and a `backup_failed` alert appears; the local backup is kept. Old backups are removed from both disks after `BACKUP_KEEP_DAYS`. Backups hold your data (and provider logins in channel addresses): keep the bucket private.
- **A missing backup is noticed.** If no backup succeeded on every configured disk for 36 hours, a `backup_stale` alert appears once a day (panel and email).
- **Keep `APP_KEY` with your backups.** Source addresses, 2FA secrets and relay secrets are stored encrypted with it: a restore on a new server without the same `APP_KEY` can't read them (sign-in and 2FA then fail until you set them up again).
- **Restore** (into an empty database, or over an existing one: tables are replaced):

```bash
gunzip -c storage/app/private/backups/iptv-20261008-025000.sql.gz | mysql -u USER -p DATABASE
php artisan migrate --force   # in case the backup is older than the code
php artisan iptv:sync-epg --now   # refill the TV guide
```

Test a restore into a scratch database once, so you know it works before you need it.

## 7. Error tracking (optional)

Set `SENTRY_LARAVEL_DSN` in `.env` (a free Sentry project is enough) and unhandled errors are reported there. Nothing is sent without a DSN. Calls to providers are not recorded as breadcrumbs or spans (their addresses hold logins), and log lines and other breadcrumbs go through the same cleaning. Before sending, `App\Support\SentryScrubber` hides what must not leave the server: playlist, play and login tokens in addresses, query strings, cookies, and everything after the host in provider addresses inside error messages. Run `php artisan config:cache` after changing `.env`. Logs are written to a file per day and kept for `LOG_DAILY_DAYS` (14).

## Automatic deploy

Every push to `main` is put on the server by the **Deploy to the server** job in `.github/workflows/ci.yml`. A push to `main` can only be a merged pull request (branch protection), and the checks (tests, style, analysis, browser tests, MySQL migrations, relay) already passed on that pull request with the branch up to date, i.e. on exactly this code, so they are not run a second time: the deploy starts right after the merge (about 30 seconds). It sends `scripts/deploy.sh` over SSH, so the server always runs the script of the commit being deployed. The script: stops if files were changed on the server → fast-forwards to that commit (never rewrites history) → dumps the database to `~/deploy-backups/` (the last 5 are kept) → `composer install --no-dev` → `migrate --force` → rebuilds the caches → `iptv:backup` → opens `APP_URL/login` and expects 200. It stops at the first error and prints how to go back (git can't undo migrations: use `migrate:rollback` or the dump). Pushes are deployed one at a time (a later push waits). Without the secrets below, the job does nothing.

**One-time setup**

1. On the server, make a key just for this and allow it:

```bash
ssh-keygen -t ed25519 -N "" -C "github-deploy" -f ~/.ssh/github_deploy && cat ~/.ssh/github_deploy.pub >> ~/.ssh/authorized_keys
```

2. Add these as GitHub secrets (repository → Settings → Secrets and variables → Actions, or `gh secret set NAME`):

| Secret | Value |
|---|---|
| `DEPLOY_HOST` | the server's address (hPanel → SSH access) |
| `DEPLOY_PORT` | the SSH port (Hostinger: usually `65002`; empty = 22) |
| `DEPLOY_USER` | the SSH user name |
| `DEPLOY_PATH` | the project folder on the server, e.g. `/home/u123/domains/example.com/public_html/nebotv` (the folder with `artisan` and `.env`) |
| `DEPLOY_SSH_KEY` | the content of the **private** key file `~/.ssh/github_deploy` |
| `DEPLOY_KNOWN_HOSTS` | the server's host key, from `ssh-keyscan -p PORT HOST` run on your computer (so a fake server can't receive the key) |

3. Delete the private key from the server (`rm ~/.ssh/github_deploy`) once it is in GitHub.

The job uses the `production` environment: in Settings → Environments → production you can require an approval before each deploy. To pause deploying, delete the `DEPLOY_SSH_KEY` secret. To remove access, delete the line `github-deploy` from `~/.ssh/authorized_keys` on the server. Deploy by hand: `bash scripts/deploy.sh "$PWD"` in the project folder.

## Uptime monitor

The **Uptime** workflow (`.github/workflows/uptime.yml`) opens `/login` and `/health` of the live site every 30 minutes (3 tries each) and fails when either does not answer 200. `/health` also fails when the scheduler or queue has stopped. A failed run emails you (GitHub → Settings → Notifications → Actions → "Send notifications for failed workflows only"). It stays off until the repository variable is set:

```bash
gh variable set SITE_URL --body "https://nebotv.bozidarpejoski.dev"
```

GitHub runs schedules on a best-effort basis (a run can start a few minutes late). For faster alerts add a free external monitor (UptimeRobot, Better Stack) on the same two addresses.

## Staging

A second copy of the site (own subdomain, own database, own `.env`) to try a change for real before merging it. Push any branch there: `git push -f origin <branch>:staging`; the checks run and then **Deploy to staging** updates the staging folder (`DEPLOY_RESET=1`: the server is moved to that commit with `git reset --hard`, never used for production). Staging never touches the live site or database.

**One-time setup on Hostinger**

1. hPanel → Websites → *Subdomains*: create `staging.yourdomain.com` (note its folder). Databases → create a second MySQL database and user.
2. Over SSH, in the folder that belongs to the subdomain:

```bash
git clone <repo-url> nebotv-staging && cd nebotv-staging && cp .env.example .env && php artisan key:generate
```

3. Edit `.env`: the new `DB_*`, `APP_URL=https://staging.yourdomain.com`, `APP_ENV=staging`, `APP_DEBUG=false`, `SESSION_SECURE_COOKIE=true`, `QUEUE_SCHEDULED_WORKER=true`, **`MAIL_MAILER=log`** and no `ALERT_MAIL_TO` (staging must not email anyone), no real provider sources, and its **own** `ADMIN_PASSWORD`. Never copy the live `APP_KEY`.
4. `composer install --no-dev --optimize-autoloader && php artisan migrate --force && php artisan db:seed --class=AdminSeeder --force`, point the subdomain's web root at `nebotv-staging/public`, add the cron job (see section 4) for it, and (optional) protect it with a password in hPanel (*Password protect directories*) so search engines and strangers stay out.
5. In GitHub add the secret `STAGING_PATH` = the absolute path of `nebotv-staging` (the other `DEPLOY_*` secrets are shared with production):

```bash
gh secret set STAGING_PATH --body "/home/u123/domains/yourdomain.com/staging/nebotv-staging"
```

Without `STAGING_PATH` the staging job does nothing. Data on staging is its own: use throw-away sources and users.

## Dependencies

`composer audit` reports known security advisories. The **Dependency audit** workflow (`.github/workflows/audit.yml`) runs it every Monday and whenever `composer.lock` changes; it is not a required check (a new advisory would block unrelated work), so a red run is your signal. Dependabot (`.github/dependabot.yml`) opens a weekly pull request for PHP packages, one for the browser-test tools and one for GitHub Actions. Updating by hand: branch, `composer update`, `composer check` + `npm run e2e`, try playing a channel through *Hide provider address* in both modes (the proxy uses Guzzle directly), then ship as usual.

## Branches and releases

`main` is production: a push to it deploys. Work therefore happens on branches and reaches `main` only when finished:

1. **Start:** `git fetch && git switch --no-track -c feature/short-name origin/main` (or `fix/…`, `docs/…`, `chore/…`). Commit as often as you like; branch pushes never deploy.
2. **Check:** `composer check` (Pint, PHPStan, tests), `npm run e2e` for UI changes, migrations tried up and down on MySQL, docs and translations updated.
3. **Pull request:** `gh pr create --base main`. The five checks run on it (about 90 seconds). Write down in the description what the server needs (migrations, new `.env` variables: the deploy never edits the server's `.env`, add them *before* merging).
4. **Merge:** `gh pr merge --auto --squash` turns on auto-merge: GitHub merges the moment the checks are green and deletes the branch (repository settings *Allow auto-merge* and *Automatically delete head branches* are on). Do it only when you decided the change is ready: the merge is the release, and the deploy job updates the server right after it.
5. **Verify** the deploy log (`Done: old -> new`, `/login answers 200`) and the live site. Going back: revert on a new branch and ship that, plus `migrate:rollback` or the dump from `~/deploy-backups/` if a migration ran.

The Claude Code skills `start-work` and `ship-to-main` (`.claude/skills/`) follow exactly these steps. **Protection on GitHub (turned on 2026-10-09):** `main` accepts changes only through a pull request whose five checks (*Tests (PHP 8.4)*, *Code style and static analysis*, *Browser tests*, *Migrations on MySQL*, *Receiver relay*) are green and whose branch is up to date with `main`; no direct pushes (also not for the owner), no force pushes, no deleting `main`, linear history (squash merges). Settings → Branches → rule for `main`. In a real emergency untick *Do not allow bypassing the above settings* there, push the fix, and turn it back on. A required reviewer for the `production` environment (a click to approve each deploy) would be the next step, but GitHub offers it for private repositories only on a paid plan; until then the go-ahead is given to Claude / yourself when merging.

## Hide provider address (on your own server)

Per source, **Hide provider address** plays channels through your server so viewers can't read the provider's login, see [playlist-and-streaming.md](features/playlist-and-streaming.md#hide-provider-address-stream-proxy). Set `STREAM_PROXY` in `.env`:

- **Hostinger / shared hosting: `php`, and only for a few viewers.** Each viewer of a live MPEG-TS channel keeps one PHP process busy for as long as they watch, and shared plans have only a handful. Your upload bandwidth is also the video bandwidth. If the site slows down, turn the source's option off.
- **Your own server (nginx): `nginx`.** Add the two files to nginx and include them in the `server` block (the Docker setup does this already):

```nginx
location ~ \.php$ {
    # ...your usual fastcgi settings, plus:
    fastcgi_param STREAM_PROXY_ENGINE nginx;
}
include /etc/nginx/stream-proxy.conf;   # copy docker/nginx/stream-proxy.conf and stream-proxy-common.conf there
```

  Edit the `resolver` lines in `stream-proxy.conf` to a DNS server your host can reach (Docker's `127.0.0.11` only exists inside Docker). Check with `nginx -t`, then play a channel of a source with the option on and confirm the address bar / app shows only your domain. Plan for the bandwidth: 20 viewers of a 5 Mbit/s channel is 100 Mbit/s out of your server and 5 Mbit/s in per distinct channel.
- Behind HTTPS (Caddy, a load balancer): turn off any response buffering for `/play`, `/live` and `/stream`.

## Behind a load balancer, Cloudflare or another proxy

Set `TRUSTED_PROXIES` in `.env` (`*` or a comma-separated list of the proxy IPs). Without it the app sees the proxy's address as every visitor's: the sign-in limits, request limits and device slots then apply to everyone together, the sign-in history shows the wrong IP, and HTTPS isn't noticed (no HSTS). Leave it empty when visitors reach the server directly.

## Automated checks (CI)

GitHub Actions (`.github/workflows/ci.yml`) runs on every pull request (and on pushes to the `staging` branch), not again after the merge to `main` (see *Automatic deploy*): the test suite, the receiver relay tests, and a MySQL job that migrates up, all the way down and up again, seeds, and makes a backup. A red check means something is broken, don't deploy it.

### Making the checks faster

Pull request checks take about 60–70 s since the browser tests run in two halves and composer's `vendor/` is cached in every job (before: 85–100 s). What is left, by benefit:

1. **Skip the browser tests for docs-only pull requests** (`paths` filter on `docs/**`, `*.md`). Those then finish in about 30 s. The required check `Browser tests` must still report: a skipped required job counts as passing only when skipped by a workflow condition, so test this on a throwaway pull request first.
2. **Larger runners** (paid) would cut the browser halves further without changing the tests.

Done: the MySQL job keeps its data in memory (`tmpfs`) and does not flush on every commit; the browser tests are split in two (`--shard`, each half on its own runner; the required check `Browser tests` is a small job that passes only when both halves pass) and `vendor/` is cached by `composer.lock` in all four PHP jobs.

Not recommended: dropping the MySQL up/down/up job or the browser tests. They are what caught the date-shortcut bug and the migration-order mistakes.

## Limits to expect

- **Very large playlists** (roughly 50k–100k entries) may be stopped by the host's CPU/process limits. The source is then marked **Failed** with "The import was stopped before it finished." (`SyncSource::failed`, after `DB_QUEUE_RETRY_AFTER`). Channels written so far are kept and nothing is deleted, because removing vanished channels is the last step. Tick **Skip VOD** to shrink the playlist, or run that source over SSH: `php artisan iptv:sync-sources <id> --now`.
- **HTTPS:** enable the free SSL certificate in hPanel; playlist and play links use the request host, so they follow your domain. Then set `SESSION_SECURE_COOKIE=true` in `.env` so the sign-in cookie is only sent over HTTPS (the panel also sends HSTS on HTTPS).
- **Terms of service:** hosts forbid distributing content you don't have rights to. Only use provider streams you are licensed to redistribute.
