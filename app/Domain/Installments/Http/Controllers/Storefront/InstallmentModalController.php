<?php

namespace App\Domain\Installments\Http\Controllers\Storefront;

use App\Domain\Installments\InstallmentQuote;
use App\Domain\Installments\ProductPriceResolver;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as SymfonyResponse;

/**
 * GET /proxy/installments/modal/{productGid}/{variantGid}
 *
 * Renders the deposit-calculator page that the storefront button loads inside an
 * <iframe>. The page itself fetches the live schedule preview + submits the start —
 * BOTH back through the App Proxy (relative /apps/{subpath}/...), so every
 * follow-up call is Shopify-signed too. The shop is the proxy-verified shop only.
 *
 * The product price is read SERVER-SIDE from our synced catalog cache (the variant
 * GID maps to a cached variant); the client never sends a price. An unknown variant
 * fails closed with a friendly message (no quote, no plan).
 */
final class InstallmentModalController extends ProxyInstallmentController
{
    public function __construct(private readonly ProductPriceResolver $prices) {}

    public function __invoke(Request $request, string $productGid, string $variantGid): View|Response
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response(__('storefront.installments.error_generic'), SymfonyResponse::HTTP_UNAUTHORIZED);
        }

        // Render the page (and all __() strings) in the storefront's locale.
        app()->setLocale($this->localeFor($request));

        $productGid = $this->normalizeVariantOrProductGid($productGid, 'Product');
        $variantGid = $this->normalizeVariantOrProductGid($variantGid, 'ProductVariant');

        $resolved = $this->prices->resolve($productGid, $variantGid);
        if ($resolved === null) {
            // Not in our cache → we cannot trust a price. Render an unavailable state.
            return response()
                ->view('storefront.installments.unavailable', [
                    'locale' => $this->localeFor($request),
                    'dir' => $this->dirFor($request),
                ], SymfonyResponse::HTTP_OK);
        }

        $price = round((float) $resolved['variant']->price, 2);
        $currency = $this->currencyFor($request);

        // Seed the UI with a default quote (server-computed). The page recomputes via
        // the quote endpoint whenever the shopper changes a knob.
        $quote = InstallmentQuote::build(
            totalAmount: $price,
            depositPercent: InstallmentQuote::DEFAULT_DEPOSIT_PERCENT,
            installments: InstallmentQuote::DEFAULT_INSTALLMENTS,
            frequency: InstallmentQuote::DEFAULT_FREQUENCY,
            paymentDay: InstallmentQuote::DEFAULT_PAYMENT_DAY,
            currency: $currency,
        );

        return response()
            ->view('storefront.installments.modal', [
                'shopDomain' => (string) $shop->shopify_domain,
                'proxyBase' => $this->proxyBase(),
                'productGid' => $productGid,
                'variantGid' => $variantGid,
                'itemTitle' => $resolved['title'],
                'unitPrice' => $price,
                'currency' => $currency,
                'quote' => $quote->toArray(),
                'bounds' => $this->bounds(),
                'locale' => $this->localeFor($request),
                'dir' => $this->dirFor($request),
            ])
            // The page is framed by the merchant's storefront button, which lives on
            // the shop's PRIMARY/custom domain OR *.myshopify.com — we can't
            // enumerate every custom domain, so we constrain to any HTTPS parent
            // (blocks http: + non-web embedders). The REAL auth boundary is the App
            // Proxy signature that got us here, not the frame ancestor. We
            // deliberately DON'T send X-Frame-Options (ALLOW-FROM is dead in modern
            // browsers; a stray DENY/SAMEORIGIN would block the legitimate iframe).
            ->header('Content-Security-Policy', 'frame-ancestors https:');
    }

    /**
     * The storefront-relative App-Proxy base the blade's fetch() calls hit, e.g.
     * "/apps/payplus/installments". Shopify proxies it (signed) to /proxy/... so the
     * follow-up quote/start requests are verified exactly like this one.
     */
    private function proxyBase(): string
    {
        $prefix = trim((string) config('shopify.app_proxy_prefix', 'apps'), '/');
        $subpath = trim((string) config('shopify.app_proxy_subpath', 'payplus'), '/');

        return "/{$prefix}/{$subpath}/installments";
    }

    /** The clamp bounds the UI enforces client-side (the server re-clamps anyway). */
    private function bounds(): array
    {
        return [
            'min_deposit_percent' => InstallmentQuote::MIN_DEPOSIT_PERCENT,
            'max_deposit_percent' => InstallmentQuote::MAX_DEPOSIT_PERCENT,
            'min_installments' => InstallmentQuote::MIN_INSTALLMENTS,
            'max_installments' => InstallmentQuote::MAX_INSTALLMENTS,
            'min_payment_day' => InstallmentQuote::MIN_PAYMENT_DAY,
            'max_payment_day' => InstallmentQuote::MAX_PAYMENT_DAY,
            'frequencies' => array_map(
                static fn ($f): string => $f->value,
                InstallmentQuote::ALLOWED_FREQUENCIES,
            ),
        ];
    }

    /**
     * The button sends BARE NUMERIC ids in the path (route-constrained to [0-9]+),
     * so we rebuild the canonical gid://shopify/{resource}/{id} here. We still accept
     * an already-formed GID defensively (manual links / future callers).
     */
    private function normalizeVariantOrProductGid(string $value, string $resource): string
    {
        $value = urldecode(trim($value));

        // Already a full GID of the right resource → keep it.
        if (str_starts_with($value, 'gid://shopify/'.$resource.'/')) {
            return $value;
        }

        // A bare numeric id → rebuild the canonical GID.
        if (ctype_digit($value)) {
            return 'gid://shopify/'.$resource.'/'.$value;
        }

        return $value; // anything else fails the cache lookup → unavailable state
    }

    private function currencyFor(Request $request): string
    {
        $currency = strtoupper((string) $request->query('currency', ''));

        return preg_match('/^[A-Z]{3}$/', $currency) === 1
            ? $currency
            : (string) config('payplus.currency', 'ILS');
    }

    private function localeFor(Request $request): string
    {
        $locale = strtolower((string) $request->query('locale', ''));

        return in_array($locale, ['he', 'en'], true) ? $locale : (str_starts_with($locale, 'he') ? 'he' : 'en');
    }

    private function dirFor(Request $request): string
    {
        return $this->localeFor($request) === 'he' ? 'rtl' : 'ltr';
    }
}
