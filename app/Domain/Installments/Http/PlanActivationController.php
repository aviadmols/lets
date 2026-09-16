<?php

namespace App\Domain\Installments\Http;

use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\PlanActivation;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\BusinessName;
use App\Support\Tenant;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The page behind an activation link.
 *
 * The route checks the signature; this checks the nonce, binds the plan's shop, and draws
 * one of three answers in the shop's language:
 *
 *   - waiting: the product, a button, and nothing happens until it is pressed (GET has no
 *     side effect — mail scanners open links before people do);
 *   - active: already started, and when the next charge is — a second click, or a
 *     customer returning to the email, gets the truth rather than an error;
 *   - gone: revoked, cancelled, or a shop no longer live. The same page for all of them.
 *
 * It shows the shop's name, the product and the next charge date. Not the customer's
 * name, email or card: a link that reaches the wrong inbox is not a profile.
 */
final class PlanActivationController
{
    // === CONSTANTS ===
    public const VIEW = 'installments.plan-activation';

    public const VIEW_GONE = 'installments.card-update-gone';

    public function __construct(private readonly PlanActivation $activation) {}

    /** GET — the page. No side effects. */
    public function show(Request $request, string $plan, string $nonce): Response
    {
        return $this->answer($plan, $nonce, activate: false);
    }

    /** POST — the button. Starts the subscription (idempotent) and shows it started. */
    public function activate(Request $request, string $plan, string $nonce): Response
    {
        return $this->answer($plan, $nonce, activate: true);
    }

    private function answer(string $publicId, string $nonce, bool $activate): Response
    {
        $resolved = $this->activation->resolve($publicId, $nonce);

        if ($resolved === null) {
            return $this->gone();
        }

        [$shop, $plan] = $resolved;

        return Tenant::run($shop, function () use ($shop, $plan, $activate): Response {
            if ($activate) {
                $plan = $this->activation->activate($plan, ActivityEvent::ACTOR_CUSTOMER);
            }

            $status = $plan->status;

            if (! in_array($status, [PlanStatus::AWAITING_ACTIVATION, PlanStatus::ACTIVE], true)) {
                return $this->gone();
            }

            return $this->inShopLocale(fn (): Response => response()->view(self::VIEW, [
                'shopName' => BusinessName::for($shop),
                'productTitle' => $this->productTitle($plan),
                'awaiting' => $status === PlanStatus::AWAITING_ACTIVATION,
                'activateUrl' => $this->activation->activateUrl($plan),
                'nextChargeDate' => $plan->next_charge_at?->format('d/m/Y'),
                'dir' => $this->direction(),
            ]));
        });
    }

    private function productTitle(InstallmentPlan $plan): ?string
    {
        return trim((string) ($plan->itemTitle() ?? '')) ?: null;
    }

    private function gone(): Response
    {
        return $this->inShopLocale(fn (): Response => response()->view(
            self::VIEW_GONE,
            ['dir' => $this->direction()],
            Response::HTTP_GONE,
        ));
    }

    /** The language this shop's customers read — the same ladder the card-update page climbs. */
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
