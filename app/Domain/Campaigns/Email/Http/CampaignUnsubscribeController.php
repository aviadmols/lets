<?php

namespace App\Domain\Campaigns\Email\Http;

use App\Domain\Campaigns\Email\Models\CampaignUnsubscribe;
use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\ActivityEvent;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\BusinessName;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page behind {unsubscribe_url}, and the RFC 8058 one-click endpoint.
 *
 * The route is SIGNED — the signature is the whole auth — and keyed by the
 * recipient row, so the address is never in the URL. GET asks "unsubscribe
 * this address?" with one button; POST records the request. A mailbox provider
 * doing one-click sends the POST with `List-Unsubscribe=One-Click` in the body
 * and no CSRF token, which is why the POST is exempt from CSRF and the
 * signature carries the weight instead.
 *
 * The recipient row is resolved by the audited cross-tenant lookup (the
 * signature proves the id is ours; the row then names the shop), and the rest
 * runs under that shop.
 */
final class CampaignUnsubscribeController
{
    // === CONSTANTS ===
    public const VIEW_CONFIRM = 'campaigns.unsubscribe';

    public const VIEW_DONE = 'campaigns.unsubscribed';

    public const VIEW_EXPIRED = 'campaigns.login-expired';

    /** The body a one-click POST carries (RFC 8058 §3.2). */
    public const ONE_CLICK_BODY = 'List-Unsubscribe=One-Click';

    /** GET /c/unsubscribe/{recipient} — the confirmation page. */
    public function show(Request $request, int $recipient): Response
    {
        [$shop, $row] = $this->resolve($recipient);
        if ($shop === null) {
            return $this->gone();
        }

        return $this->inShopLocale($shop, fn (): Response => response()->view(self::VIEW_CONFIRM, [
            'shopName' => BusinessName::for($shop),
            'maskedEmail' => CampaignLoginController::mask((string) $row->email),
            'confirmUrl' => $request->fullUrlWithQuery([]),
            'dir' => CampaignLoginController::direction(),
        ]));
    }

    /** POST /c/unsubscribe/{recipient} — record it (also the one-click endpoint). */
    public function confirm(Request $request, int $recipient): Response
    {
        [$shop, $row] = $this->resolve($recipient);
        if ($shop === null) {
            return $this->gone();
        }

        $oneClick = $request->input('List-Unsubscribe') === 'One-Click'
            || str_contains((string) $request->getContent(), self::ONE_CLICK_BODY);

        Tenant::run($shop, function () use ($request, $row, $shop, $oneClick): void {
            CampaignUnsubscribe::record(
                email: (string) $row->email,
                campaignId: $row->email_campaign_id !== null ? (int) $row->email_campaign_id : null,
                source: $oneClick ? CampaignUnsubscribe::SOURCE_ONE_CLICK : CampaignUnsubscribe::SOURCE_LINK,
                ipHash: $request->ip() !== null ? hash('sha256', (string) $request->ip()) : null,
            );

            Timeline::record(
                kind: Timeline::KIND_CAMPAIGN_UNSUBSCRIBED,
                details: array_filter(['campaign_id' => $row->email_campaign_id]),
                planId: $row->source_type === EmailCampaignRecipient::SOURCE_PLAN ? (int) $row->source_id : null,
                actor: ActivityEvent::ACTOR_CUSTOMER,
                shopId: (int) $shop->getKey(),
            );
        });

        if ($oneClick) {
            return response()->noContent();
        }

        return $this->inShopLocale($shop, fn (): Response => response()->view(self::VIEW_DONE, [
            'shopName' => BusinessName::for($shop),
            'dir' => CampaignLoginController::direction(),
        ]));
    }

    // === Internals ===

    /**
     * The recipient row + its shop, or [null, null].
     *
     * @return array{0: ?Shop, 1: ?EmailCampaignRecipient}
     */
    private function resolve(int $recipientId): array
    {
        if ($recipientId <= 0) {
            return [null, null];
        }

        $row = EmailCampaignRecipient::acrossAllTenants()->whereKey($recipientId)->first();
        if ($row === null) {
            return [null, null];
        }

        $shop = Shop::query()->find((int) $row->shop_id);

        return $shop instanceof Shop ? [$shop, $row] : [null, null];
    }

    private function gone(): Response
    {
        return response()->view(self::VIEW_EXPIRED, ['dir' => CampaignLoginController::direction()], Response::HTTP_GONE);
    }

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
}
