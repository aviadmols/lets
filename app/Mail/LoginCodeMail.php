<?php

namespace App\Mail;

use App\Mail\Concerns\UsesCustomMailTemplate;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * The personal area's sign-in code.
 *
 * The only mail in the app that is NOT driven by a plan — it goes to whoever asked
 * to sign in, who may not have a subscription at all — so it extends Mailable
 * directly rather than PlanMail, while keeping the same merchant-override
 * behaviour through UsesCustomMailTemplate (custom copy wins, rendered by strtr,
 * never Blade).
 *
 * The var bag is three scalars and no URL. A sign-in mail that carries a link is
 * a phishing template; the shopper is already on the page that asked for the code.
 *
 * Tenant-safe: the Shop is carried EXPLICITLY, so a mail queued for shop A renders
 * shop A's copy and SMTP even on a worker that just handled shop B.
 */
final class LoginCodeMail extends Mailable
{
    use Queueable;
    use SerializesModels;
    use UsesCustomMailTemplate;

    public function __construct(
        public readonly Shop $shop,
        public readonly string $code,
        public readonly int $expiresMinutes,
    ) {}

    public function envelope(): Envelope
    {
        return $this->buildEnvelope(MerchantMailSettings::TEMPLATE_LOGIN_CODE, $this->shop, $this->vars());
    }

    public function content(): Content
    {
        return $this->buildContent(MerchantMailSettings::TEMPLATE_LOGIN_CODE, $this->shop, $this->vars());
    }

    /** @return array<string, scalar|null> */
    private function vars(): array
    {
        return [
            'code' => $this->code,
            'expires_minutes' => $this->expiresMinutes,
            'business_name' => $this->resolveBusinessName($this->shop),
        ];
    }
}
