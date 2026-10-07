<?php

namespace App\Services;

use App\Models\StreamAlert;
use App\Models\User;

/**
 * Reminds admins about subscriptions: a few days before a user's expiry date
 * (iptv.expiry_reminder_days) and once it has passed. Each is sent once per expiry date,
 * so extending a subscription produces new reminders for the new date.
 * Alerts go to the panel and the email digest like stream alerts.
 */
class SubscriptionReminderService
{
    /**
     * @return int number of reminders created
     */
    public function run(): int
    {
        $created = 0;
        $days = (int) config('iptv.expiry_reminder_days');

        $subscribers = User::where('role', 'user')
            ->where('status', 'active')
            ->whereNotNull('expiration_date')
            ->where('expiration_date', '<=', now()->addDays($days))
            // Expired more than a week ago: no point reminding any more
            ->where('expiration_date', '>', now()->subWeek())
            ->get(['id', 'username', 'expiration_date']);

        foreach ($subscribers as $user) {
            $type = $user->expiration_date->isPast() ? StreamAlert::TYPE_EXPIRED : StreamAlert::TYPE_EXPIRING;
            $date = $user->expiration_date->toDateString();

            $alreadySent = StreamAlert::where('type', $type)->where('user_id', $user->id)->where('detail', $date)->exists();

            if (!$alreadySent) {
                StreamAlert::create(['type' => $type, 'user_id' => $user->id, 'name' => $user->username, 'detail' => $date]);
                $created++;
            }
        }

        return $created;
    }
}
