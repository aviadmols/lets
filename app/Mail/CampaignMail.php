<?php

namespace App\Mail;

use App\Mail\Concerns\ResolvesBusinessName;
use App\Mail\Support\CampaignMailer;
use App\Mail\Support\TemplateRenderer;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;

/**
 * One campaign email to one person.
 *
 * Subject and body are MERCHANT-AUTHORED and substituted by TemplateRenderer —
 * strtr(), single pass, never Blade. The wrapper view only echoes the already-
 * rendered string; no compilation of merchant input happens anywhere on this
 * path (the same law every plan mail obeys).
 *
 * The shop is passed EXPLICITLY so a worker that just handled shop B renders
 * shop A's From and language; the locale is bound around both halves because
 * Laravel carries none across the queue.
 *
 * A MARKETING message is marked as the law requires: List-Unsubscribe (+ the
 * one-click POST variant) in the headers, and, in Hebrew, "פרסומת" at the head
 * of the subject (Communications Law §30A).
 */
final class CampaignMail extends Mailable
{
    use Queueable;
    use ResolvesBusinessName;
    use SerializesModels;

    // === CONSTANTS ===
    public const HEADER_LIST_UNSUBSCRIBE = 'List-Unsubscribe';

    public const HEADER_LIST_UNSUBSCRIBE_POST = 'List-Unsubscribe-Post';

    public const ONE_CLICK = 'List-Unsubscribe=One-Click';

    /** The locale whose law requires the subject tag. */
    private const TAGGED_LOCALE = 'he';

    /**
     * `$shopperLocale`, not `$locale`: Mailable already owns a `$locale` and
     * uses it for its own queue-time localisation. Ours is the language the
     * MERCHANT chose for their customers, bound around both halves of the
     * render, and the two must not collide.
     *
     * @param  array<string, scalar|null>  $vars  the substitution bag (already built)
     */
    public function __construct(
        public readonly Shop $shop,
        public readonly string $subjectTemplate,
        public readonly string $bodyTemplate,
        public readonly array $vars,
        public readonly string $unsubscribeUrl,
        public readonly string $shopperLocale,
        public readonly bool $isMarketing,
        public readonly string $textTemplate = '',
    ) {}

    public function envelope(): Envelope
    {
        return $this->inLocale(function (): Envelope {
            $subject = TemplateRenderer::render($this->subjectTemplate, $this->vars);

            if ($this->isMarketing && $this->shopperLocale === self::TAGGED_LOCALE) {
                $prefix = (string) __('campaigns.mail.ad_prefix');
                if ($prefix !== '' && ! str_starts_with($subject, $prefix)) {
                    $subject = $prefix.$subject;
                }
            }

            $envelope = new Envelope(subject: $subject);

            $from = CampaignMailer::fromFor($this->shop);
            if ($from !== null) {
                $envelope = $envelope->from($from['address'], $from['name']);
            }

            return $envelope;
        });
    }

    public function content(): Content
    {
        return $this->inLocale(fn (): Content => new Content(
            view: 'emails.user-template-wrapper',
            // A studio campaign compiles a plain-text twin; legacy campaigns
            // have none and stay HTML-only exactly as before.
            text: trim($this->textTemplate) !== '' ? 'emails.user-template-text' : null,
            with: [
                // strtr-substituted, then handed to a wrapper that ONLY echoes it.
                'renderedHtml' => TemplateRenderer::render($this->bodyTemplate, $this->vars),
                'renderedText' => TemplateRenderer::render($this->textTemplate, $this->vars),
                'businessName' => $this->resolveBusinessName($this->shop),
            ],
        ));
    }

    public function headers(): Headers
    {
        if (! $this->isMarketing) {
            return new Headers;
        }

        return new Headers(text: [
            self::HEADER_LIST_UNSUBSCRIBE => '<'.$this->unsubscribeUrl.'>',
            self::HEADER_LIST_UNSUBSCRIBE_POST => self::ONE_CLICK,
        ]);
    }

    /** The rendered subject, for tests and the send log. */
    public function renderedSubject(): string
    {
        return (string) $this->envelope()->subject;
    }

    private function inLocale(callable $callback): mixed
    {
        $previous = App::getLocale();

        try {
            App::setLocale($this->shopperLocale);

            return $callback();
        } finally {
            App::setLocale($previous);
        }
    }
}
