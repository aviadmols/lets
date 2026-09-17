<?php

namespace App\Domain\Installments\Http;

use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\PlanActivation;
use App\Domain\Installments\StoreActivationPage;
use App\Domain\ShopifySubscriptions\ContractActivation;
use App\Http\Middleware\VerifyShopifyAppProxy;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Models\SubscriptionContract;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\BusinessName;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * The "Subscription activation" theme block's server, through the App Proxy.
 *
 * GET answers what to draw; POST is the button. Both take the link's token, and both
 * answer `{show: false}` — the block then draws NOTHING — unless the token names a
 * subscription that is waiting or already started, carries its current nonce, and belongs
 * to the very shop Shopify signed the request for. A token from another store is as good as
 * no token.
 *
 * The checks are the LETS page's own (PlanActivation / ContractActivation::resolve); this
 * adds only the shop match and speaks JSON in the shop's customer language. It never says
 * who the customer is: the shop, the product and a date.
 */
final class StoreActivationController
{
    public function __construct(
        private readonly StoreActivationPage $page,
        private readonly PlanActivation $plans,
        private readonly ContractActivation $contracts,
    ) {}

    /** GET — what the block should show. No side effects (mail scanners open links too). */
    public function show(Request $request): JsonResponse
    {
        return $this->answer($request, $request->query('token'), activate: false);
    }

    /** POST — the button. Starts the subscription (idempotent) and answers what to show now. */
    public function activate(Request $request): JsonResponse
    {
        return $this->answer($request, $request->input('token'), activate: true);
    }

    private function answer(Request $request, mixed $token, bool $activate): JsonResponse
    {
        $shop = $request->attributes->get(VerifyShopifyAppProxy::ATTR_SHOP);
        $parts = $this->page->parse($token);

        if (! $shop instanceof Shop || $parts === null) {
            return $this->nothing();
        }

        $state = $parts['kind'] === StoreActivationPage::KIND_PLAN
            ? $this->plan($shop, $parts['id'], $parts['nonce'], $activate)
            : $this->contract($shop, $parts['id'], $parts['nonce'], $activate);

        return $state === null ? $this->nothing() : $this->card($shop, $state);
    }

    /** @return array{awaiting: bool, failed: bool, product: ?string, next: ?Carbon}|null */
    private function plan(Shop $shop, string $publicId, string $nonce, bool $activate): ?array
    {
        [$owner, $plan] = $this->plans->resolve($publicId, $nonce) ?? [null, null];

        if (! $owner instanceof Shop || (int) $owner->getKey() !== (int) $shop->getKey()) {
            return null;
        }

        if ($activate) {
            $plan = $this->plans->activate($plan, ActivityEvent::ACTOR_CUSTOMER);
        }

        if (! in_array($plan->status, [PlanStatus::AWAITING_ACTIVATION, PlanStatus::ACTIVE], true)) {
            return null;
        }

        return [
            'awaiting' => $plan->status === PlanStatus::AWAITING_ACTIVATION,
            'failed' => false,
            'product' => trim((string) ($plan->itemTitle() ?? '')) ?: null,
            'next' => $plan->next_charge_at,
        ];
    }

    /** @return array{awaiting: bool, failed: bool, product: ?string, next: ?Carbon}|null */
    private function contract(Shop $shop, string $id, string $nonce, bool $activate): ?array
    {
        [$owner, $contract] = ctype_digit($id) ? ($this->contracts->resolve((int) $id, $nonce) ?? [null, null]) : [null, null];

        if (! $owner instanceof Shop || (int) $owner->getKey() !== (int) $shop->getKey()) {
            return null;
        }

        $failed = false;
        if ($activate) {
            $result = $this->contracts->activate($shop, $contract, ActivityEvent::ACTOR_CUSTOMER);
            $contract = $result['contract'];
            $failed = ! $result['ok'];
        }

        $awaiting = $contract->awaitsActivation();
        if (! $awaiting && ($contract->activated_at === null || in_array((string) $contract->status, SubscriptionContract::TERMINAL_STATUSES, true))) {
            return null;
        }

        $first = (array) (((array) ($contract->lines ?? []))[0] ?? []);

        return [
            'awaiting' => $awaiting,
            'failed' => $failed,
            'product' => trim((string) ($first['title'] ?? '')) ?: null,
            'next' => $contract->next_billing_date,
        ];
    }

    /** @param array{awaiting: bool, failed: bool, product: ?string, next: ?Carbon} $state */
    private function card(Shop $shop, array $state): JsonResponse
    {
        $previous = app()->getLocale();

        try {
            app()->setLocale(CardUpdateService::shopperLocale());

            $replace = ['shop' => BusinessName::for($shop), 'product' => (string) $state['product']];
            $lead = $state['awaiting']
                ? ($state['product'] !== null ? 'activation.page.lead' : 'activation.page.lead_generic')
                : ($state['product'] !== null ? 'activation.page.active_lead' : 'activation.page.active_lead_generic');

            return $this->json([
                'show' => true,
                'awaiting' => $state['awaiting'],
                'dir' => app()->getLocale() === 'he' ? 'rtl' : 'ltr',
                'heading' => __($state['awaiting'] ? 'activation.page.heading' : 'activation.page.active_heading'),
                'body' => __($lead, $replace),
                'button' => $state['awaiting'] ? __('activation.page.button') : null,
                'note' => $state['awaiting']
                    ? __('activation.page.note')
                    : ($state['next'] !== null ? __('activation.page.next_charge', ['date' => $state['next']->format('d/m/Y')]) : null),
                'error' => $state['failed'] ? __('activation.page.failed') : null,
            ]);
        } finally {
            app()->setLocale($previous);
        }
    }

    private function nothing(): JsonResponse
    {
        return $this->json(['show' => false]);
    }

    /** @param array<string, mixed> $body */
    private function json(array $body): JsonResponse
    {
        // Per-subscription answers: never cached by the store, a CDN or the browser.
        return response()->json($body)->header('Cache-Control', 'no-store, private');
    }
}
