<?php

namespace App\Domain\Upsell\Http\Controllers;

use App\Domain\Upsell\PurchaseContext;
use App\Domain\Upsell\UpsellResolver;
use App\Domain\Upsell\UpsellSignedUrlService;
use App\Http\Controllers\Controller;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The thank-you-page upsell widget. Served by the storefront return/thank-you
 * route after a purchase. Resolves the offer to show for the source purchase
 * (records an impression) and renders the one-click widget with signed accept /
 * decline action links. Renders nothing (an empty widget) when no flow matches.
 *
 * The purchase context comes through a SIGNED link (the storefront has no admin
 * session); the tenant is bound from the signed shop id and cleared after.
 */
final class ThankYouUpsellController extends Controller
{
    public function __construct(
        private readonly UpsellResolver $resolver,
        private readonly UpsellSignedUrlService $urls,
    ) {}

    public function __invoke(Request $request): View
    {
        abort_unless($request->hasValidSignature(), 403);

        $shop = Shop::query()->find((int) $request->query('shop'));
        if ($shop === null) {
            throw new NotFoundHttpException('Unknown shop.');
        }

        return Tenant::run($shop, function () use ($request, $shop): View {
            $context = new PurchaseContext(
                shopId: (int) $shop->getKey(),
                parentOrderId: (string) $request->query('parent_order', ''),
                customerRef: (string) $request->query('customer', ''),
                orderSubtotal: (float) $request->query('subtotal', 0),
                purchasedProductGids: $this->csv($request->query('products')),
                purchasedCollectionGids: $this->csv($request->query('collections')),
                purchasedTags: $this->csv($request->query('tags')),
                customerEmail: $request->query('email') !== null ? (string) $request->query('email') : null,
            );

            $resolution = $this->resolver->resolve($context);

            if ($resolution === null) {
                return view('upsell::storefront.empty', ['shop' => $shop]);
            }

            return view('upsell::storefront.widget', [
                'shop' => $shop,
                'flow' => $resolution->flow,
                'offer' => $resolution->offer,
                'price' => $resolution->discountedPrice(),
                'basePrice' => round((float) $resolution->offer->base_price, 2),
                'currency' => (string) config('payplus.currency', 'ILS'),
                'acceptUrl' => $this->urls->acceptUrl($resolution->flow, $resolution->offer, $context->parentOrderId, $context->customerRef),
                'declineUrl' => $this->urls->declineUrl($resolution->flow, $resolution->offer, $context->parentOrderId, $context->customerRef),
            ]);
        });
    }

    /** @return list<string> */
    private function csv(mixed $value): array
    {
        if (! is_string($value) || $value === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $value))));
    }
}
