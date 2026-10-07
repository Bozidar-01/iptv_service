<?php

return [

    /*
    | Who gets alert emails (comma-separated). Empty = alerts only show in the panel.
    | Mail itself is set up with the MAIL_* variables (e.g. Brevo SMTP, see docs/features/alerts.md).
    */
    'alert_mail_to' => array_values(array_filter(array_map('trim', explode(',', (string) env('ALERT_MAIL_TO', ''))))),

];
