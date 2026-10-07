<?php

namespace App\Console\Commands;

use App\Mail\StreamAlertsMail;
use App\Models\StreamAlert;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

class SendAlertsCommand extends Command
{
    protected $signature = 'iptv:send-alerts {--test : Send a test email to check the mail settings}';

    protected $description = 'Email new stream alerts to ALERT_MAIL_TO as one message';

    public function handle(): int
    {
        $to = config('iptv.alert_mail_to');

        if ($to === []) {
            $this->warn('ALERT_MAIL_TO is empty: alerts only show in the panel.');

            return $this->option('test') ? self::FAILURE : self::SUCCESS;
        }

        if ($this->option('test')) {
            Mail::to($to)->send(new StreamAlertsMail(collect()));
            $this->info('Test email sent to '.implode(', ', $to).'.');

            return self::SUCCESS;
        }

        // Older unsent alerts (e.g. from before mail was set up) are marked sent, not emailed
        $alerts = StreamAlert::with('source:id,name')->whereNull('mailed_at')->where('created_at', '>=', now()->subDay())->orderBy('id')->limit(200)->get();

        if ($alerts->isNotEmpty()) {
            Mail::to($to)->send(new StreamAlertsMail($alerts));
            $this->info("Emailed {$alerts->count()} alerts.");
        }

        StreamAlert::whereNull('mailed_at')->where('id', '<=', $alerts->max('id') ?? PHP_INT_MAX)->update(['mailed_at' => now()]);

        return self::SUCCESS;
    }
}
