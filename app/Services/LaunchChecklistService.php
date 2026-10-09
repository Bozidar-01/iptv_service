<?php

namespace App\Services;

use App\Models\Bouquet;
use App\Models\Channel;
use App\Models\ChecklistTick;
use App\Models\Source;
use App\Models\User;
use Illuminate\Support\Facades\Hash;

/**
 * The launch checklist: what to have in place before real viewers and resellers use the panel.
 * Most items are checked by the app itself (settings, backups, background jobs, content);
 * the rest can't be seen from here (a password manager, an outside monitor) and an admin ticks them off.
 */
class LaunchChecklistService
{
    /**
     * @return list<array{title: string, items: list<array{key: string, title: string, help: string, done: bool, optional: bool, manual: bool, route: ?string}>}>
     */
    public function sections(User $admin): array
    {
        $ticked = ChecklistTick::pluck('item')->all();
        $health = app(HealthService::class)->status();
        $backups = app(BackupService::class);

        $auto = fn (string $key, string $title, string $help, bool $done, ?string $route = null, bool $optional = false) => compact('key', 'title', 'help', 'done', 'optional', 'route') + ['manual' => false];
        $manual = fn (string $key, string $title, string $help, ?string $route = null) => ['key' => $key, 'title' => $title, 'help' => $help, 'done' => in_array($key, $ticked, true), 'optional' => false, 'route' => $route, 'manual' => true];

        $lastBackup = $backups->lastSuccess();

        return [
            [
                'title' => __('Security'),
                'items' => [
                    $auto('two_factor', __('Two-factor sign-in is on for your account'), __('The admin account can do everything. Turn it on under Security.'), app(TwoFactorService::class)->isEnabled($admin), 'admin.security.index'),
                    $auto('admin_password', __('No admin still uses the sample password'), __('Change the password of any admin who still signs in with admin123.'), !User::where('role', 'admin')->get()->contains(fn (User $u) => Hash::check('admin123', $u->password)), 'admin.users.index'),
                    $auto('production', __('The site runs in production mode'), __('APP_ENV=production and APP_DEBUG=false in .env. Debug mode shows secrets in error pages.'), config('app.env') === 'production' && !config('app.debug')),
                    $auto('https', __('The site address uses https'), __('APP_URL starts with https:// and SESSION_SECURE_COOKIE=true, so sign-ins are encrypted.'), str_starts_with((string) config('app.url'), 'https://') && (bool) config('session.secure')),
                    $manual('secrets_rotated', __('Passwords and keys shared in chats are changed'), __('Anything you pasted somewhere (relay secret, receiver password, DuckDNS token, SMTP key, database and admin passwords) should be replaced.')),
                ],
            ],
            [
                'title' => __('Backups'),
                'items' => [
                    $auto('backup_recent', __('A backup ran in the last :hours hours', ['hours' => BackupService::STALE_HOURS]), $lastBackup ? __('Last successful backup: :when.', ['when' => $lastBackup->diffForHumans()]) : __('No backup has succeeded yet. Run php artisan iptv:backup on the server.'), $lastBackup !== null && $lastBackup->diffInHours(now()) < BackupService::STALE_HOURS, 'admin.backups.index'),
                    $auto('backup_offsite', __('Backups are also copied off the server'), __('Set BACKUP_COPY_DISK=s3 and the bucket keys in .env (Backblaze B2 or Cloudflare R2 are free at this size). See docs/deployment.md.'), !empty(config('iptv.backup.copy_disk')), 'admin.backups.index'),
                    $manual('app_key_saved', __('APP_KEY is saved outside the server'), __('Keep it in a password manager. Without it a restore can not read the stored passwords, source addresses or two-factor secrets.')),
                    $manual('restore_tested', __('A restore has been tested once'), __('Press Check on the Backups page (it ticks this by itself), or better restore a backup into a scratch database to know it works before you need it.'), 'admin.backups.index'),
                ],
            ],
            [
                'title' => __('Running in the background'),
                'items' => [
                    $auto('scheduler', __('The scheduler (cron job) is running'), __('Needed for backups, expiry reminders, the TV guide and stream checks.'), $health['scheduler']['ok']),
                    $auto('queue', __('The queue is moving'), __('Needed for importing sources. Jobs stuck for 15 minutes mean the worker is not running.'), $health['queue']['ok']),
                    $auto('mail', __('Alert emails can be sent'), __('Set MAIL_MAILER (not log) and ALERT_MAIL_TO in .env, so a stopped channel or a failed backup reaches you.'), !in_array(config('mail.default'), ['log', 'array', 'null'], true) && !empty(config('iptv.alert_mail_to'))),
                    $manual('uptime_monitor', __('An outside monitor watches the site'), __('Free: UptimeRobot or Better Stack on the home page and /health. The GitHub check only runs every 30 minutes.')),
                    $auto('error_tracking', __('Error tracking (Sentry) is on'), __('Optional. Set SENTRY_LARAVEL_DSN to be told about errors you would not otherwise see.'), !empty(config('sentry.dsn')), null, true),
                ],
            ],
            [
                'title' => __('Ready for viewers and resellers'),
                'items' => [
                    $auto('source', __('A source is imported with channels'), __('Add a source and wait for the import to finish.'), Source::exists() && Channel::exists(), 'admin.sources.index'),
                    $auto('bouquet', __('A bouquet has channels in it'), __('Users only see the channels of their bouquets.'), Bouquet::whereHas('channels')->exists(), 'admin.bouquets.index'),
                    $auto('user', __('A first user exists'), __('Create a user with a bouquet and an end date.'), User::where('role', 'user')->exists(), 'admin.users.index'),
                    $manual('tv_tested', __('A channel played on a real TV or phone app'), __('Open the user\'s playlist link in your IPTV app and watch a channel for a few minutes.')),
                    $manual('reseller_tried', __('A reseller account was tried end to end'), __('Create a reseller, give them credits, sign in as them and add a user. Check the wording makes sense to them.'), 'admin.users.index'),
                ],
            ],
        ];
    }

    /** @return array{done: int, total: int, percent: int} Optional items are not counted. */
    public function progress(User $admin): array
    {
        $items = collect($this->sections($admin))->pluck('items')->flatten(1)->where('optional', false);
        $done = $items->where('done', true)->count();
        $total = $items->count();

        return ['done' => $done, 'total' => $total, 'percent' => $total ? (int) floor($done * 100 / $total) : 100];
    }

    /** The manual item keys. */
    public function manualKeys(User $admin): array
    {
        return collect($this->sections($admin))->pluck('items')->flatten(1)->where('manual', true)->pluck('key')->all();
    }

    /** Tick a manual item, or untick it when it is ticked. */
    public function toggle(string $item, User $admin): void
    {
        $tick = ChecklistTick::where('item', $item)->first();

        $tick ? $tick->delete() : ChecklistTick::create(['item' => $item, 'user_id' => $admin->id]);
    }
}
