<?php

namespace App\Domain\ShopifySubscriptions\Http;

use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\Http\PlanActivationController;
use App\Domain\ShopifySubscriptions\ContractActivation;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Support\BusinessName;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page behind a Shopify Payments contract's activation link — PlanActivationController's
 * twin, drawing the SAME page: the customer cannot tell, and should not care, which engine
 * bills their subscription.
 *
 * GET shows (mail scanners open links first); POST starts. One answer the PayPlus page does
 * not need: Shopify can refuse or time out, and then the page says "try again" with the
 * button still there — the contract stays held and unbillable until it succeeds.
 */
final class ContractActivationController
{
    public function __construct(private readonly ContractActivation $activation) {}

    /** GET — the page. No side effects. */
    public function show(Request $request, string $contract, string $nonce): Response
    {
        return $this->answer((int) $contract, $nonce, activate: false);
    }

    /** POST — the button. Starts the subscription (idempotent) and shows the outcome. */
    public function activate(Request $request, string $contract, string $nonce): Response
    {
        return $this->answer((int) $contract, $nonce, activate: true);
    }

    private function answer(int $contractId, string $nonce, bool $activate): Response
    {
        $resolved = $this->activation->resolve($contractId, $nonce);

        if ($resolved === null) {
            return $this->gone();
        }

        [$shop, $contract] = $resolved;

        return Tenant::run($shop, function () use ($shop, $contract, $activate): Response {
            $failed = false;

            if ($activate) {
                $result = $this->activation->activate($shop, $contract, ActivityEvent::ACTOR_CUSTOMER);
                $contract = $result['contract'];
                $failed = ! $result['ok'];
            }

            $awaiting = $contract->awaitsActivation();

            // Started and still running, or waiting. Anything else (cancelled, expired) is gone.
            if (! $awaiting && ($contract->activated_at === null || in_array((string) $contract->status, SubscriptionContract::TERMINAL_STATUSES, true))) {
                return $this->gone();
            }

            return $this->inShopLocale(fn (): Response => response()->view(PlanActivationController::VIEW, [
                'shopName' => BusinessName::for($shop),
                'productTitle' => $this->productTitle($contract),
                'awaiting' => $awaiting,
                'failed' => $failed,
                'activateUrl' => $this->activation->activateUrl($contract),
                'nextChargeDate' => $contract->next_billing_date?->format('d/m/Y'),
                'dir' => $this->direction(),
            ]));
        });
    }

    private function productTitle(SubscriptionContract $contract): ?string
    {
        $first = (array) (((array) ($contract->lines ?? []))[0] ?? []);

        return trim((string) ($first['title'] ?? '')) ?: null;
    }

    private function gone(): Response
    {
        return $this->inShopLocale(fn (): Response => response()->view(
            PlanActivationController::VIEW_GONE,
            ['dir' => $this->direction()],
            Response::HTTP_GONE,
        ));
    }

    /** The language this shop's customers read — the same ladder the PayPlus page climbs. */
    private function inShopLocale(callable $callback): Response
    {
        $previous = app()->getLocale();

        try {
            app()->setLocale(CardUpdateService::shopperLocale());

            return $callback();
        } finally {
            app()->setLocale($previous);
        }
    }

    private function direction(): string
    {
        return app()->getLocale() === 'he' ? 'rtl' : 'ltr';
    }
}
