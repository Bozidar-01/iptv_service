<?php

namespace App\Console\Commands;

use App\Services\SubscriptionReminderService;
use Illuminate\Console\Command;

class ExpiryRemindersCommand extends Command
{
    protected $signature = 'iptv:expiry-reminders';

    protected $description = 'Alert about subscriptions that expire soon or have just expired';

    public function handle(SubscriptionReminderService $reminders): int
    {
        $this->info('Reminders created: '.$reminders->run().', emails sent to subscribers: '.$reminders->mailed);

        return self::SUCCESS;
    }
}
