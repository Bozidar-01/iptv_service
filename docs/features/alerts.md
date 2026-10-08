# Stream check and alerts

## Stream check (`StreamCheckService`)

Finds channels whose stream no longer works and switches them off, so users don't see dead channels in their playlists.

- **What is checked:** enabled channels, plus channels the check switched off itself (`auto_disabled = true`). Channels an admin disabled are skipped, and so are channels of sources with **Check streams** turned off (`sources.check_streams`). Turning that off switches the source's `auto_disabled` channels back on. Use it for channels that play only in some countries.
- **How:** concurrent GET requests (`Http::pool`, 50 at a time, 10 s timeout, 6 s connect timeout). Each request sends the channel's own `http_user_agent` (default: VLC) and `http_referrer`, if the playlist had them. `App\Support\CappedStream` keeps only the first 4 KB and then aborts, so endless live streams don't block. A stream is **online** when it answers 2xx and the body contains `#EXTM3U`, or the content type is media (`mpegurl`, `video`, `audio`, `octet-stream`, `mp2t`, `dash`), or the body has at least 188 bytes (one MPEG-TS packet) and is not HTML. Anything else is **offline** (`StreamCheckService::diagnose`).
- **Retry:** streams that fail are tried once more after `RETRY_DELAY` (5 s) in the same job, so a single timeout doesn't switch a channel off.
- **Reason:** `stream_error` keeps why it failed: `http:{code}`, `timeout`, `connect`, `html` (a web page instead of a stream) or `empty`. `StreamCheckService::describeError()` turns it into readable text: "Refused or blocked in this country (HTTP 403)", "Stream not found (HTTP 404)", "No answer in time", and so on. It is shown in the *Not working* badge tooltip, the dashboard's Watched channels card, and alerts and emails.
- **Result:** `stream_status` (`online`/`offline`), `stream_error` and `stream_checked_at` are saved. An enabled channel that is offline is switched off with `auto_disabled = true`. When a later check finds it online, it is switched back on. All writes are set-based (`whereIn` per chunk).
- **Backups:** a dead channel isn't switched off when a working channel shows the same programme. That's the admin's chosen **Backup channel** (`channels.backup_channel_id`, channel form), else another channel with the same `tvg-id`. A backup must be enabled, working, and not on a backup itself. The channel stays on with `using_backup_id` set, and `/play`, `/live` and the preview send viewers to the backup (`Channel::playbackChannel()`). When its own stream works again, `using_backup_id` is cleared. The list shows a yellow *Playing a backup* badge, and the status filter has a *Playing a backup* option. On 2026-10-07, 20 switched-off channels had a working twin.
- **The admin decides:** enabling or disabling a channel by hand (edit form or bulk action) clears `auto_disabled`. A channel the admin disabled stays off. A channel the admin enabled is checked again and switched off if its stream still fails.
- **Running it:** `iptv:check-streams {source?} {--watched} {--now}` queues one `CheckStreams` job per 250 channels (about a minute each at most). `--now` runs it in the command, `--watched` checks only channels with alerts on. The **Check streams** button on the Channels page (`POST admin/channels/check-streams`) queues a check of all channels, or only the selected source when the list is filtered by one.
- **Schedule:** all channels daily at 04:00. Watched channels every 15 minutes.
- **One check at a time:** `queueCheck()` stores the number of queued jobs in the cache (`stream-check:pending`, expires after 2 hours). Each `CheckStreams` job counts itself off when it ends, including when it fails. While a check is running, the button shows "A stream check is already running", and the command and schedule skip queuing. So a stopped queue worker doesn't pile up checks.
- **Limits:** the check runs from the server, so a stream blocked in the server's country counts as offline even if it plays elsewhere. On 2026-10-07: 3,619 of 5,798 channels online. Of the 2,179 offline, 884 were 403 (mostly geo-blocks), 355 timeouts, 341 connection failures and 292 were 404s.

## Alerts

Admins can ask to be told when something stops working.

- **What to watch:** tick **Send me alerts** on a channel (edit form, or the *Turn alerts on / off* bulk actions), or on a source to watch all its channels. `Channel::scopeWatched()` covers both. Watched channels show a bell icon in the channel list. The *Alerts on* status filter lists them.
- **When an alert is created** (`stream_alerts`, `StreamAlert`):
  - `down`: a watched channel's check fails and its last status was not `offline`. That includes its first check.
  - `up`: a watched channel works again after being `offline`.
  - `sync_failed`: a watched source's import fails (`SourceSyncService`).
  - `queue_stuck` and `jobs_failed`: background jobs stopped (see below). Always created, no watching needed.
  - `renewal_requested`: a subscriber pressed *Ask to renew* on their own page (`SelfServiceController`). Once per user per 24 hours; linked to the user page; in the *Subscriptions* group of the email.
  - `backup_failed`: the nightly `iptv:backup` failed (`detail` = start of the error, the full one is in the log). See [deployment.md](../deployment.md#6-backups).
  - `expiring` / `expired`: a subscriber's `expiration_date` is within `EXPIRY_REMINDER_DAYS` (3), or has passed in the last week (`SubscriptionReminderService`, `iptv:expiry-reminders` daily at 08:00). Once per user and expiry date (`stream_alerts.user_id`, `detail` = date), so an extended subscription gets new reminders. Active subscribers only. Linked to the user page; a *Subscriptions* group in the email.
  - `receiver_down` / `receiver_up`: a satellite receiver's relay stopped / started answering ([receiver.md](receiver.md)). Always created.
  - `down` alerts store the reason in `detail`. The message then reads e.g. "Telma stopped working: No answer in time".
- **In the panel:**
  - A bell in the header (top right on desktop, in the top bar on phones) shows the number of unread alerts on a red badge (round bordered button, highlighted while open). It opens a menu with the 8 latest alerts, **Mark all as read** (`POST admin/alerts/read`) and **See all alerts**.
  - The full list is at `admin/alerts` (sidebar → **Alerts**).
  - The dashboard has a **Watched channels** card listing watched channels whose last check failed and watched sources whose import failed.
  - The **Channels** stat shows how many channels the check switched off, linked to the *Not working* filter.
  - Alerts are shared by all admins. Reading them marks them read for everyone.
- **By email:** `iptv:send-alerts` runs every 5 minutes. It sends all new alerts from the last 24 hours as **one** email (`StreamAlertsMail`, at most 200 alerts per email) to `ALERT_MAIL_TO`, then sets `mailed_at`. Older unsent alerts are marked as sent without being emailed. With `ALERT_MAIL_TO` empty, nothing is sent. If sending fails, the alerts stay unsent and are tried again on the next run. `iptv:send-alerts --test` sends a test email.

**Email design:** `emails/stream-alerts` (HTML) and `emails/stream-alerts-text` (plain-text part). The HTML is email-safe: tables and inline styles only, so it looks the same in Gmail, Outlook and Apple Mail. It shows the NeboTV logo (embedded inline, so it shows without loading remote images), a headline with the number of problems, and the alerts grouped as *failed imports → stopped working → working again* (`StreamAlertsMail::groups()`). Each alert has its source, time and a coloured status pill. An **Open alerts** button links to `admin/alerts`, and inbox preview text sums up the email. Links use `APP_URL`, so set it to the panel's public address in production. The test email uses the same layout.

## Background tasks (`HealthService`)

Imports, stream checks and alert emails all depend on the scheduler (cron) and the queue. If either stops, nothing else would tell you.

- `iptv:heartbeat` runs every minute. It stores the time in the cache (`health:scheduler-heartbeat`) and checks two things:
  - **Stuck queue:** a queued job ready for `QUEUE_STALE_MINUTES` (15) or more creates a `queue_stuck` alert, at most once every 6 hours. Only the `database` queue driver can be checked.
  - **Failed jobs:** new rows in `failed_jobs` since the last run create a `jobs_failed` alert with the count.
- **Dashboard:** a red banner when the scheduler hasn't run for `SCHEDULER_STALE_MINUTES` (5) or has never run, or when the queue is stuck.
- **`GET /health`** (no login, no session): `{"ok", "scheduler": {"ok", "last_run"}, "queue": {"ok", "oldest_job_minutes"}}`. Answers `200` when everything runs and `503` otherwise. The scheduler can't report that it stopped, so point an outside monitor at this address. UptimeRobot's free plan checks every 5 minutes and emails you on 503.

## Email with Brevo

Set these in `.env`:

```dotenv
MAIL_MAILER=smtp
MAIL_HOST=smtp-relay.brevo.com
MAIL_PORT=587
MAIL_USERNAME=xxxxxxx@smtp-brevo.com   # Brevo → SMTP & API → SMTP tab: "Login"
MAIL_PASSWORD=xsmtpsib-...             # an SMTP key generated on the same page (not your account password)
MAIL_FROM_ADDRESS="alerts@yourdomain.com"   # must be a sender or domain verified in Brevo
MAIL_FROM_NAME="${APP_NAME}"
ALERT_MAIL_TO=you@example.com          # comma-separated for several people
```

Then run `php artisan config:clear` (or `config:cache` in production), and `php artisan iptv:send-alerts --test` to check that it works. With `MAIL_MAILER=log`, emails are written to `storage/logs/laravel.log` instead.
