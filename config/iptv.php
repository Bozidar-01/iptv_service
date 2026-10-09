<?php

return [

    /*
    | Who gets alert emails (comma-separated). Empty = alerts only show in the panel.
    | Mail itself is set up with the MAIL_* variables (e.g. Brevo SMTP, see docs/features/alerts.md).
    */
    'alert_mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALERT_MAIL_TO', ''))))),

    /*
    | Minutes a signed satellite-receiver link stays valid (ReceiverService). Players that
    | reconnect after a drop reuse the link, so keep this longer than a typical viewing.
    */
    'receiver_link_minutes' => (int) env('RECEIVER_LINK_MINUTES', 180),

    /*
    | Days before a subscription's expiry date that admins get a reminder (iptv:expiry-reminders).
    */
    /*
    | Email subscribers (that have an email address) the same expiry reminder the admins get, with the
    | link to their own page. Needs working MAIL_* settings. RENEWAL_CONTACT is a line added to the
    | email, e.g. "Reply to this email or call 070 123 456."
    */
    'reminder_email_users' => (bool) env('REMINDER_EMAIL_USERS', true),
    'renewal_contact' => env('RENEWAL_CONTACT', ''),

    // How many trial accounts one reseller can make per 24 hours (admins are not limited)
    'trials_per_day' => (int) env('TRIALS_PER_DAY', 5),

    'expiry_reminder_days' => (int) env('EXPIRY_REMINDER_DAYS', 3),

    /*
    | Days of TV guide kept and sent to apps (EpgImportService, EpgService).
    */
    'epg_days' => (int) env('EPG_DAYS', 3),

    /*
    | Days of play history (stream_logs) kept; older rows are deleted by model:prune.
    */
    'log_retention_days' => (int) env('LOG_RETENTION_DAYS', 90),

    /*
    | Requests per minute for the player links (AppServiceProvider). Per link + IP, and per IP
    | overall (TVs behind one router share an IP, so that one is generous).
    */
    'throttle_per_token' => (int) env('THROTTLE_PER_TOKEN', 60),
    'throttle_per_ip' => (int) env('THROTTLE_PER_IP', 300),
    'throttle_api_per_ip' => (int) env('THROTTLE_API_PER_IP', 600),
    // Parts of a proxied stream (HLS segments): many requests per viewing
    'throttle_stream_per_token' => (int) env('THROTTLE_STREAM_PER_TOKEN', 600),

    /*
    | Playing through the server instead of redirecting to the provider (StreamProxyService), for
    | sources that have "Hide provider address" on. off | php | nginx.
    */
    'stream_proxy' => env('STREAM_PROXY', 'off'),
    // The proxy refuses addresses on private / local networks (PublicAddress); true when a provider is on your LAN
    // Live streams passed on by PHP at once per device (php mode holds a PHP worker for each)
    'proxy_streams_per_device' => (int) env('PROXY_STREAMS_PER_DEVICE', 3),
    'proxy_allow_private' => (bool) env('PROXY_ALLOW_PRIVATE', false),

    /*
    | Database backups (iptv:backup, BackupService): where they go (any filesystem disk, e.g. an
    | s3 disk for off-site copies), how long they are kept, and the mysqldump program.
    */
    'backup' => [
        'disk' => env('BACKUP_DISK', 'local'),
        // A second disk every backup is also written to, normally off-site (s3, see docs/deployment.md#backups)
        'copy_disk' => env('BACKUP_COPY_DISK'),
        // Database connection to dump (null = the default one)
        'connection' => null,
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        // Extra mysqldump flags, comma-separated (e.g. --skip-ssl)
        'dump_options' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_DUMP_OPTIONS', ''))))),
    ],

    /*
    | First admin created by AdminSeeder (read here, not with env() in the seeder, so it also works with `config:cache`).
    | Without a password: "admin123" locally, otherwise a random one printed once.
    */
    'admin_username' => env('ADMIN_USERNAME') ?: 'admin',
    'admin_password' => env('ADMIN_PASSWORD'),

];
