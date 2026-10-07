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

];
