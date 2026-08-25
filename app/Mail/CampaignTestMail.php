<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesBusinessName;
use App\Mail\Support\CampaignMailer;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * "Send me a test" — the merchant's own copy of a campaign, to their own inbox.
 *
 * A separate mailable rather than a hand-rolled Mail::html() so it travels the
 * same per-shop mailer as the real thing (and so a test can assert it was sent).
 * The subject and body arrive ALREADY RENDERED, by CampaignPreview, with SAMPLE
 * values — which is what keeps a test send from minting a live sign-in link.
 */
final class CampaignTestMail extends Mailable
{
    use Queueable;
    use ResolvesBusinessName;
    use SerializesModels;

    public function __construct(
        public readonly Shop $shop,
        public readonly string $renderedSubject,
        public readonly string $renderedHtml,
    ) {}

    public function envelope(): Envelope
    {
        $envelope = new Envelope(subject: $this->renderedSubject);

        $from = CampaignMailer::fromFor($this->shop);
        if ($from !== null) {
            $envelope = $envelope->from($from['address'], $from['name']);
        }

        return $envelope;
    }

    public function content(): Content
    {
        return new Content(
            view: 'emails.user-template-wrapper',
            with: [
                // Already substituted; the wrapper only echoes it.
                'renderedHtml' => $this->renderedHtml,
                'businessName' => $this->resolveBusinessName($this->shop),
            ],
        );
    }
}
