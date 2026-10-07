<?php

namespace App\Mail;

use App\Models\StreamAlert;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Collection;

class StreamAlertsMail extends Mailable
{
    use Queueable;

    /**
     * @param  Collection<int, \App\Models\StreamAlert>  $alerts  empty for a test email
     */
    public function __construct(public Collection $alerts)
    {
    }

    public function envelope(): Envelope
    {
        $problems = $this->alerts->filter->isProblem()->count();

        return new Envelope(subject: match (true) {
            $this->alerts->isEmpty() => __(':app: test email', ['app' => config('app.name')]),
            $problems > 0 => trans_choice(':app: :count channel or source is not working|:app: :count channels or sources are not working', $problems, ['app' => config('app.name'), 'count' => $problems]),
            default => __(':app: channels are working again', ['app' => config('app.name')]),
        });
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.stream-alerts',
            text: 'emails.stream-alerts-text',
            with: ['groups' => $this->groups()],
        );
    }

    /**
     * Alerts grouped for the email: background job problems, subscriptions, failed imports, channels down, recovered.
     *
     * @return array<string, Collection<int, StreamAlert>>
     */
    public function groups(): array
    {
        return collect([
            'system' => $this->alerts->filter->isSystem()->values(),
            'subscriptions' => $this->alerts->whereIn('type', StreamAlert::SUBSCRIPTION_TYPES)->values(),
            StreamAlert::TYPE_SYNC_FAILED => $this->alerts->where('type', StreamAlert::TYPE_SYNC_FAILED)->values(),
            StreamAlert::TYPE_DOWN => $this->alerts->where('type', StreamAlert::TYPE_DOWN)->values(),
            StreamAlert::TYPE_UP => $this->alerts->whereIn('type', [StreamAlert::TYPE_UP, StreamAlert::TYPE_RECEIVER_UP])->values(),
        ])->filter->isNotEmpty()->all();
    }
}
