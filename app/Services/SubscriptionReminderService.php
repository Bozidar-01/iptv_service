<?php

namespace App\Services;

use App\Mail\SubscriptionReminderMail;
use App\Models\StreamAlert;
use App\Models\User;
use Illuminate\Support\Facades\Mail;

/**
 * Reminds admins about subscriptions: a few days before a user's expiry date
 * (iptv.expiry_reminder_days) and once it has passed. Each is sent once per expiry date,
 * so extending a subscription produces new reminders for the new date.
 * Alerts go to the panel and the email digest like stream alerts. Users that have an email address
 * are emailed the same reminder too (REMINDER_EMAIL_USERS), retried on the next run if sending failed.
 */
class SubscriptionReminderService
{
    /** Emails sent to subscribers by the last run(). */
    public int $mailed = 0;

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
            ->get();

        foreach ($subscribers as $user) {
            $type = $user->expiration_date->isPast() ? StreamAlert::TYPE_EXPIRED : StreamAlert::TYPE_EXPIRING;
            $date = $user->expiration_date->toDateString();

            $alert = StreamAlert::where('type', $type)->where('user_id', $user->id)->where('detail', $date)->first();

            if (!$alert) {
                $alert = StreamAlert::create(['type' => $type, 'user_id' => $user->id, 'name' => $user->username, 'detail' => $date]);
                $created++;
            }

            $this->mailUser($user, $alert);
        }

        return $created;
    }

    /**
     * Email the subscriber, once per reminder. A failure is reported and tried again on the next run.
     */
    private function mailUser(User $user, StreamAlert $alert): void
    {
        if (!config('iptv.reminder_email_users') || blank($user->email) || $alert->user_mailed_at !== null) {
            return;
        }

        try {
            Mail::to($user->email)
                ->locale($user->locale ?: config('app.locale'))
                ->send(new SubscriptionReminderMail($user, $alert->type));
        } catch (\Throwable $e) {
            report($e);

            return;
        }

        $alert->forceFill(['user_mailed_at' => now()])->save();
        $this->mailed++;
    }
}
