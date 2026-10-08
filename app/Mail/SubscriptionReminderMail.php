<?php

namespace App\Mail;

use App\Models\StreamAlert;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Tells a subscriber their subscription ends soon / has ended, with the link to their own page
 * (where "Ask to renew" is). Sent by SubscriptionReminderService to users that have an email address.
 */
class SubscriptionReminderMail extends Mailable
{
    use Queueable;

    /** @param  string  $type  StreamAlert::TYPE_EXPIRING or TYPE_EXPIRED */
    public function __construct(public User $user, public string $type) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->type === StreamAlert::TYPE_EXPIRED
            ? __(':app: your subscription has ended', ['app' => config('app.name')])
            : __(':app: your subscription ends on :date', ['app' => config('app.name'), 'date' => $this->user->expiration_date->translatedFormat('j M Y')]));
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.subscription-reminder',
            text: 'emails.subscription-reminder-text',
            with: [
                'expired' => $this->type === StreamAlert::TYPE_EXPIRED,
                'date' => $this->user->expiration_date->translatedFormat('j M Y'),
                'pageUrl' => route('me', ['token' => $this->user->playlist_token, 'lang' => app()->getLocale()]),
                'contact' => config('iptv.renewal_contact'),
            ],
        );
    }
}
