<?php

namespace App\Domain\Installments\Http;

use App\Domain\Installments\CardUpdateLinks;
use App\Domain\Installments\CardUpdateService;
use App\Domain\Installments\Models\CardUpdateLink;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\BusinessName;
use App\Support\Tenant;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * The page behind a card-update link.
 *
 * GET SHOWS AN INTERSTITIAL AND MINTS NOTHING. Two reasons, and both have burnt
 * this kind of link before: mail scanners follow every URL in an email before
 * the person sees it, and a PayPlus page minted then would be expired by the
 * time the customer clicks. The page carries one CSRF-protected POST button;
 * that click mints the PayPlus page at that instant and redirects to it.
 *
 * ONE ANSWER FOR EVERY REFUSAL. Missing, malformed, expired, revoked, already
 * used — all render the same 410. The difference between "never existed" and
 * "already used" is an oracle about somebody's subscription, and this page does
 * not offer it.
 *
 * WHAT IT REVEALS: the shop's name and the last four digits of the card being
 * replaced. Not the customer's name, not their email, not the amount — a link
 * that reaches the wrong inbox must not be a profile of the right person.
 *
 * The shop comes from the LINK ROW (the audited cross-tenant lookup in
 * CardUpdateLinks::find) and is bound with Tenant::run; everything after that is
 * tenant-scoped.
 */
final class CardUpdateLandingController
{
    // === CONSTANTS ===
    public const VIEW_LANDING = 'installments.card-update-landing';

    public const VIEW_GONE = 'installments.card-update-gone';

    public function __construct(private readonly CardUpdateLinks $links) {}

    /** GET /c/card/{token} — the interstitial. No side effects but the click stamp. */
    public function show(Request $request, string $token): Response
    {
        [$link, $shop, $plan] = $this->resolve($token);

        if ($link === null || $shop === null || $plan === null) {
            return $this->gone();
        }

        return Tenant::run($shop, function () use ($link, $shop, $plan, $token): Response {
            $link->markClicked();

            return $this->inShopLocale(fn (): Response => response()->view(self::VIEW_LANDING, [
                'shopName' => BusinessName::for($shop),
                'cardLastFour' => $this->cardLastFour($plan),
                'continueUrl' => route(CardUpdateLinks::ROUTE_START, ['token' => $token]),
                'expiresAt' => $link->expires_at?->format('d/m/Y'),
                'dir' => $this->direction(),
            ]));
        });
    }

    /** POST /c/card/{token} — mint the PayPlus page NOW and go there. */
    public function start(Request $request, string $token): Response|RedirectResponse
    {
        [$link, $shop, $plan] = $this->resolve($token);

        if ($link === null || $shop === null || $plan === null) {
            return $this->gone();
        }

        return Tenant::run($shop, function () use ($link, $shop, $plan): Response|RedirectResponse {
            $url = app(CardUpdateService::class)->mintPage($shop, $plan, $link);

            if ($url === null) {
                // The gateway refused or the shop is misconfigured. Same page as
                // every other refusal: the customer can do nothing about either,
                // and the merchant sees it on the plan's timeline.
                return $this->gone();
            }

            // The click itself, on the record. The gap between this and
            // KIND_CARD_UPDATED is the dunning signal: "they tried and gave up".
            Timeline::record(
                kind: Timeline::KIND_CARD_UPDATE_STARTED,
                details: ['link_id' => (int) $link->getKey(), 'channel' => (string) $link->channel],
                planId: (int) $plan->getKey(),
                actor: ActivityEvent::ACTOR_CUSTOMER,
                shopId: (int) $shop->getKey(),
            );

            return redirect()->away($url);
        });
    }

    // === Internals ===

    /**
     * The link, its shop and its plan — or nulls, which the caller turns into
     * the one refusal page.
     *
     * @return array{0: ?CardUpdateLink, 1: ?Shop, 2: ?InstallmentPlan}
     */
    private function resolve(string $token): array
    {
        $link = $this->links->find($token);

        if ($link === null || ! $link->isUsable()) {
            return [null, null, null];
        }

        $shop = Shop::query()->find((int) $link->shop_id);

        if (! $shop instanceof Shop || ! $shop->isLive()) {
            return [null, null, null];
        }

        $plan = Tenant::run($shop, static fn (): ?InstallmentPlan => InstallmentPlan::query()
            ->find((int) $link->plan_id));

        if ($plan === null || ! CardUpdateService::availableFor($shop, $plan)) {
            return [null, null, null];
        }

        return [$link, $shop, $plan];
    }

    /** The card being replaced, as four digits — or null when none is vaulted. */
    private function cardLastFour(InstallmentPlan $plan): ?string
    {
        $digits = trim((string) ($plan->paymentMethod?->card_last_four ?? ''));

        return $digits !== '' ? $digits : null;
    }

    private function gone(): Response
    {
        return $this->inShopLocale(fn (): Response => response()->view(
            self::VIEW_GONE,
            ['dir' => $this->direction()],
            Response::HTTP_GONE,
        ));
    }

    /** The language this shop's customers read, bound around the render. */
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
