<?php

namespace App\Domain\Loyalty\Http\Controllers;

use App\Domain\Loyalty\Rendering\LoyaltyPagePresenter;
use App\Models\MerchantLoyaltySettings;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * The members page itself — one standalone HTML page, served either through the
 * Shopify App Proxy (as https://{shop}/apps/payplus/loyalty) or on a temporary
 * signed URL inside a WooCommerce iframe.
 *
 * A logged-out visitor gets the FULL public shape (tiers, perks, ways to earn)
 * and a sign-in prompt — never a balance, never a name. That is not politeness:
 * the page is public HTML on the merchant's domain, and anything it prints for
 * an unidentified visitor is printed for everyone.
 */
final class LoyaltyPageController extends LoyaltyController
{
    public function __invoke(Request $request, LoyaltyPagePresenter $presenter): View|Response
    {
        $visitor = $this->visitor($request);

        if ($visitor === null || ! $this->programEnabled()) {
            return response('', SymfonyResponse::HTTP_NOT_FOUND);
        }

        $settings = MerchantLoyaltySettings::current();
        // The page speaks the merchant's chosen customer language, not the
        // admin's — these are two different audiences on two different screens.
        app()->setLocale($settings->pageLocale());

        return view('storefront.loyalty.page', [
            'model' => $presenter->present($visitor),
            'actions' => $this->actionUrls($request),
            'noindex' => true,
        ]);
    }

    /**
     * The URLs the page's buttons POST to. Built HERE (server-side) so the page
     * never has to guess which rail it is on — and so a signed rail's action
     * links carry their own fresh signature.
     *
     * @return array<string, string>
     */
    private function actionUrls(Request $request): array
    {
        $isProxy = $request->attributes->has(\App\Http\Middleware\VerifyShopifyAppProxy::ATTR_SHOP);

        if ($isProxy) {
            // Same-origin relative paths: the storefront calls them through the
            // proxy, so Shopify re-signs each request for us.
            $base = rtrim((string) $request->getPathInfo(), '/');

            return [
                'join' => $base.'/join',
                'birthday' => $base.'/birthday',
                'social' => $base.'/social',
                'redeem' => $base.'/redeem',
            ];
        }

        $params = [
            'shop' => (int) $request->route('shop'),
            self::SIGNED_REF_PARAM => (string) $request->query(self::SIGNED_REF_PARAM, ''),
            self::SIGNED_EMAIL_PARAM => (string) $request->query(self::SIGNED_EMAIL_PARAM, ''),
            self::SIGNED_NAME_PARAM => (string) $request->query(self::SIGNED_NAME_PARAM, ''),
        ];

        return [
            'join' => \Illuminate\Support\Facades\URL::signedRoute('loyalty.signed.join', $params),
            'birthday' => \Illuminate\Support\Facades\URL::signedRoute('loyalty.signed.birthday', $params),
            'social' => \Illuminate\Support\Facades\URL::signedRoute('loyalty.signed.social', $params),
            'redeem' => \Illuminate\Support\Facades\URL::signedRoute('loyalty.signed.redeem', $params),
        ];
    }
}
