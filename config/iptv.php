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
    // Parts of a proxied stream (HLS segments): many requests per viewing
    'throttle_stream_per_token' => (int) env('THROTTLE_STREAM_PER_TOKEN', 600),

    /*
    | Playing through the server instead of redirecting to the provider (StreamProxyService), for
    | sources that have "Hide provider address" on. off | php | nginx.
    */
    'stream_proxy' => env('STREAM_PROXY', 'off'),

    /*
    | Database backups (iptv:backup, BackupService): where they go (any filesystem disk, e.g. an
    | s3 disk for off-site copies), how long they are kept, and the mysqldump program.
    */
    'backup' => [
        'disk' => env('BACKUP_DISK', 'local'),
        // Database connection to dump (null = the default one)
        'connection' => null,
        'keep_days' => (int) env('BACKUP_KEEP_DAYS', 14),
        'mysqldump' => env('BACKUP_MYSQLDUMP', 'mysqldump'),
        // Extra mysqldump flags, comma-separated (e.g. --skip-ssl)
        'dump_options' => array_values(array_filter(array_map('trim', explode(',', (string) env('BACKUP_DUMP_OPTIONS', ''))))),
    ],

];
