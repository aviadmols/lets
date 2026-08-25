<?php

namespace App\Domain\Campaigns\Email\Http;

use App\Domain\Campaigns\Email\CampaignLoginLinks;
use App\Domain\Campaigns\Email\CampaignLoginRedirector;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\ActivityEvent;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\BusinessName;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;

/**
 * The landing page behind {account_login_url}.
 *
 * GET shows an interstitial and SPENDS NOTHING — mail-security scanners follow
 * every link in an email before the person ever sees it, and a token burnt by a
 * scanner is a customer told "this link was already used". The page carries one
 * CSRF-protected POST button; that is the click that consumes the token, once,
 * atomically, and sends the browser on (CampaignLoginRedirector).
 *
 * ONE ANSWER FOR EVERY REFUSAL. Missing, malformed, expired, spent, revoked —
 * all render the same 410 page. The difference between "never existed" and
 * "already used" is an oracle, and the page does not offer it.
 *
 * The shop is taken from the TOKEN ROW (the audited cross-tenant lookup in
 * CampaignLoginLinks::find) and bound with Tenant::run; everything after that
 * is tenant-scoped. The page shows the shop's name and a MASKED address and
 * nothing else about the person.
 */
final class CampaignLoginController
{
    // === CONSTANTS ===
    public const VIEW_LANDING = 'campaigns.login';

    public const VIEW_EXPIRED = 'campaigns.login-expired';

    /** The personal-data access-log surface name (security-policies.md §5). */
    public const SURFACE = 'campaign_login';

    public function __construct(
        private readonly CampaignLoginLinks $links,
        private readonly CampaignLoginRedirector $redirector,
    ) {}

    /** GET /c/login/{token} — the interstitial. No side effects. */
    public function show(Request $request, string $token): Response
    {
        $row = $this->links->find($token);

        if ($row === null || ! $row->isUsable(now())) {
            return $this->gone();
        }

        $shop = Shop::query()->find((int) $row->shop_id);
        if (! $shop instanceof Shop || ! $shop->isLive()) {
            return $this->gone();
        }

        return $this->inShopLocale($shop, fn (): Response => response()->view(self::VIEW_LANDING, [
            'shopName' => BusinessName::for($shop),
            'maskedEmail' => self::mask((string) $row->email),
            'platform' => $row->platform,
            'continueUrl' => route(CampaignLoginLinks::ROUTE_CONSUME, ['token' => $token]),
            'dir' => self::direction(),
        ]));
    }

    /** POST /c/login/{token} — spend the token and go. */
    public function consume(Request $request, string $token): Response|RedirectResponse
    {
        $row = $this->links->find($token);
        if ($row === null) {
            return $this->gone();
        }

        $shop = Shop::query()->find((int) $row->shop_id);
        if (! $shop instanceof Shop || ! $shop->isLive()) {
            return $this->gone();
        }

        return Tenant::run($shop, fn (): Response|RedirectResponse => $this->inShopLocale($shop, function () use ($request, $row, $shop): Response|RedirectResponse {
            $consumed = $row->consume(
                ipHash: $request->ip() !== null ? hash('sha256', (string) $request->ip()) : null,
                userAgent: $request->userAgent(),
            );

            if (! $consumed) {
                return $this->gone();
            }

            $this->record($shop, $row);

            return redirect()->away($this->redirector->destination($shop, $row));
        }));
    }

    // === Internals ===

    /** The access is on the record on the customer's own feed and in the privacy log. */
    private function record(Shop $shop, CustomerLoginToken $row): void
    {
        Log::info('privacy.personal_data_accessed', [
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => $row->customer_ref,
            'surface' => self::SURFACE,
            'campaign_id' => $row->email_campaign_id,
        ]);

        $recipient = $row->recipient_id !== null
            ? EmailCampaignRecipient::query()->find($row->recipient_id)
            : null;

        Timeline::record(
            kind: Timeline::KIND_CAMPAIGN_LOGIN_USED,
            details: array_filter([
                'campaign_id' => $row->email_campaign_id,
                'platform' => $row->platform,
            ]),
            planId: $recipient?->source_type === EmailCampaignRecipient::SOURCE_PLAN
                ? (int) $recipient->source_id
                : null,
            actor: ActivityEvent::ACTOR_CUSTOMER,
            shopId: (int) $shop->getKey(),
        );
    }

    private function gone(): Response
    {
        return response()->view(self::VIEW_EXPIRED, ['dir' => self::direction()], Response::HTTP_GONE);
    }

    /**
     * Render in the language THIS SHOP'S customers read — the language the
     * email itself was written in — restoring whatever was in force after.
     */
    private function inShopLocale(Shop $shop, callable $callback): mixed
    {
        $settings = MerchantMailSettings::acrossAllTenants()->where('shop_id', $shop->getKey())->first();
        $previous = app()->getLocale();

        try {
            app()->setLocale($settings?->emailLocale() ?? $previous);

            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    public static function direction(): string
    {
        return app()->getLocale() === 'he' ? 'rtl' : 'ltr';
    }

    /** "dana@example.com" → "d***@example.com" — enough to recognise, not to harvest. */
    public static function mask(string $email): string
    {
        $at = strrpos($email, '@');
        if ($at === false || $at === 0) {
            return '***';
        }

        return mb_substr($email, 0, 1).'***'.substr($email, $at);
    }
}
