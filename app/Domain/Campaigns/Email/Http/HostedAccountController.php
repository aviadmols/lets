<?php

namespace App\Domain\Campaigns\Email\Http;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\Concerns\ResolvesShopperLocale;
use App\Domain\Account\CustomerSubscriptionActions;
use App\Domain\Campaigns\Email\Http\Middleware\RequireHostedAccountSession;
use App\Models\ActivityEvent;
use App\Models\InstallmentPlan;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\BusinessName;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Symfony\Component\HttpFoundation\Response as HttpResponse;

/**
 * The SaaS-hosted personal area — the same lets-account.js the storefront
 * ships, the same AccountPresenter model, the same CustomerSubscriptionActions
 * verbs, on a session opened by an emailed login link.
 *
 * Identity comes from HostedAccountSession and nowhere else: the middleware has
 * already refused a missing or expired session and bound the tenant. The
 * visitor's reference AND email both originate from the token row the send job
 * wrote from our own data — which is what makes the email a safe second matcher
 * here, exactly as on the admin's view-as page, and never on a client-asserted
 * rail.
 *
 * The verb endpoint mirrors ShopifyAccountController::action() line for line:
 * the service owns the ownership wall, the merchant's self-service switches and
 * the no-price-from-the-client rule; the refreshed model rides back so the page
 * redraws from the truth.
 */
final class HostedAccountController
{
    use ResolvesShopperLocale;

    // === CONSTANTS ===
    public const VIEW = 'account.hosted';

    public const ROUTE_SHOW = 'campaigns.account.show';

    public const ROUTE_ACT = 'campaigns.account.act';

    public const ROUTE_LOGOUT = 'campaigns.account.logout';

    public function __construct(private readonly HostedAccountSession $hosted) {}

    /** GET /c/account — the page. */
    public function show(Request $request, AccountPresenter $presenter): Response
    {
        $shop = Tenant::current();
        $visitor = $shop instanceof Shop ? $this->hosted->visitor($shop) : null;

        if ($visitor === null) {
            return response()->view(RequireHostedAccountSession::VIEW_EXPIRED, ['dir' => CampaignLoginController::direction()], HttpResponse::HTTP_GONE);
        }

        $model = $this->inShopperLocale(static fn (): array => $presenter->present($visitor), $request);

        $locale = MerchantPortalAppearance::current()->pageLocale();

        return response()->view(self::VIEW, [
            'model' => $model,
            'shopName' => BusinessName::for($shop),
            'maskedEmail' => CampaignLoginController::mask((string) $visitor->email),
            // `{action}` is the renderer's own slot; it must survive URL building.
            'endpoint' => url('/c/account/act/').'/{action}',
            'logoutUrl' => route(self::ROUTE_LOGOUT),
            'locale' => $locale !== MerchantPortalAppearance::LOCALE_AUTO ? $locale : app()->getLocale(),
        ]);
    }

    /** POST /c/account/act/{action} — a shopper acting on their own subscription. */
    public function act(
        Request $request,
        string $action,
        CustomerSubscriptionActions $actions,
        AccountPresenter $presenter,
    ): JsonResponse {
        $shop = Tenant::current();
        $visitor = $shop instanceof Shop ? $this->hosted->visitor($shop) : null;

        if ($visitor === null) {
            return response()->json(['ok' => false, 'result' => CustomerSubscriptionActions::RESULT_INVALID], HttpResponse::HTTP_GONE);
        }

        $outcome = $actions->perform(
            visitor: $visitor,
            action: $action,
            publicId: (string) ($this->clean($request->input('subscription')) ?? ''),
            input: [
                'date' => $this->clean($request->input('date')),
                'line_items' => (array) $request->input('line_items', []),
                // accept_offer: the amount is a GUARD, not an input — the service
                // prices the target itself and refuses a mismatch.
                'offer' => $this->clean($request->input('offer')),
                'target' => $this->clean($request->input('target')),
                'amount' => is_numeric($request->input('amount'))
                    ? (float) $request->input('amount')
                    : null,
            ],
        );

        if ($outcome['result'] !== CustomerSubscriptionActions::RESULT_OK
            && $outcome['result'] !== CustomerSubscriptionActions::RESULT_CHARGE_FAILED) {
            $this->recordFailure($action, $outcome['result'], (string) $request->input('subscription'));
        }

        if ($outcome['result'] === CustomerSubscriptionActions::RESULT_INVALID) {
            // Not theirs or not real — the same 404 either way, deliberately.
            return response()->json(
                ['ok' => false, 'result' => CustomerSubscriptionActions::RESULT_INVALID],
                HttpResponse::HTTP_NOT_FOUND,
            );
        }

        $model = $this->inShopperLocale(static fn (): array => $presenter->present($visitor), $request);

        return response()->json([
            'ok' => $outcome['result'] === CustomerSubscriptionActions::RESULT_OK,
            'result' => $outcome['result'],
            'account' => $model,
            // update_card answers with the PayPlus page LINK; the renderer
            // navigates instead of redrawing. Absent for every other verb.
        ] + (isset($outcome['link']) ? ['link' => $outcome['link']] : []));
    }

    /** POST /c/account/logout — end the session. */
    public function logout(): RedirectResponse|Response
    {
        $this->hosted->end();

        return response()->view(RequireHostedAccountSession::VIEW_EXPIRED, [
            'dir' => CampaignLoginController::direction(),
            'signedOut' => true,
        ]);
    }

    // === Internals ===

    private function recordFailure(string $action, string $result, string $publicId): void
    {
        $publicId = trim($publicId);

        Timeline::record(
            kind: Timeline::KIND_ACCOUNT_ACTION_FAILED,
            details: array_filter([
                'action' => $action,
                'result' => $result,
                'subscription' => $publicId !== '' ? $publicId : null,
            ]),
            planId: $publicId !== ''
                ? InstallmentPlan::query()->where('public_id', $publicId)->value('id')
                : null,
            actor: ActivityEvent::ACTOR_CUSTOMER,
        );
    }

    private function clean(mixed $value): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, 255) : null;
    }
}
