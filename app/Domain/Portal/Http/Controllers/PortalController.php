<?php

namespace App\Domain\Portal\Http\Controllers;

use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Domain\Portal\PortalSignedUrlService;
use App\Http\Controllers\Controller;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The customer-facing portal. A SIGNED magic link (no admin session) lets a
 * customer view THEIR plans on THEIR shop and self-serve pause / resume / cancel.
 *
 * SECURITY MODEL — the signature is the only auth:
 *   - Laravel's `signed` middleware (+ a defence-in-depth hasValidSignature check)
 *     rejects any forged / expired / tampered link with 403.
 *   - The signed query binds {shop, plan, customer}. We resolve the entry plan by
 *     its public_id via the ONE audited tenant-scope bypass (acrossAllTenants) — a
 *     trusted lookup because the URL is HMAC-signed — then bind Tenant to that shop
 *     and prove the signed customer ref actually matches that plan. From that point
 *     EVERY further query is BelongsToShop-scoped to the bound shop AND filtered to
 *     the signed customer identity, so a link can never surface another customer's
 *     or another shop's data.
 *   - Pause / resume / cancel re-resolve the TARGET plan the same scoped+filtered
 *     way, so a customer can never act on a plan that is not their own on this shop.
 *
 * All actions clear the tenant in finally (Tenant::run does this) — request context
 * must never leak. NOTHING here charges or refunds; lifecycle is delegated VERBATIM
 * to SubscriptionLifecycleService (guarded state machine + Timeline + cancel email).
 */
final class PortalController extends Controller
{
    // === CONSTANTS ===
    /** Plan states from which a customer may PAUSE (must reach active first). */
    private const PAUSABLE = [PlanStatus::ACTIVE];

    /** Plan states from which a customer may RESUME. */
    private const RESUMABLE = [PlanStatus::PAUSED];

    /** Plan states from which a customer may CANCEL (anything non-terminal). */
    private const CANCELLABLE = [
        PlanStatus::ACTIVE,
        PlanStatus::PAUSED,
        PlanStatus::AWAITING_FIRST_PAYMENT,
        PlanStatus::FAILED,
    ];

    public function __construct(
        private readonly SubscriptionLifecycleService $lifecycle,
    ) {}

    /** GET /portal — render every plan belonging to the signed customer on the signed shop. */
    public function show(Request $request): View
    {
        abort_unless($request->hasValidSignature(), 403);

        [$shop, $customerRef] = $this->resolveSignedContext($request);

        return Tenant::run($shop, function () use ($shop, $customerRef): View {
            $plans = $this->customerPlans($customerRef)
                ->with(['payments' => fn ($q) => $q->orderBy('sequence')])
                ->orderByDesc('id')
                ->get();

            $urls = app(PortalSignedUrlService::class);

            // Per-plan SIGNED action URLs (same shop+customer binding as this page).
            // The form POSTs to these signed endpoints; the controller re-verifies
            // ownership of the body's target plan against the signed customer set.
            $actionUrls = $plans->mapWithKeys(fn (InstallmentPlan $plan): array => [
                $plan->public_id => [
                    'pause' => $urls->pauseUrl($plan),
                    'resume' => $urls->resumeUrl($plan),
                    'cancel' => $urls->cancelUrl($plan),
                ],
            ]);

            return view('portal.show', [
                'shop' => $shop,
                'plans' => $plans,
                'customerRef' => $customerRef,
                'actionUrls' => $actionUrls,
                'allowPause' => $this->allowPause(),
                'allowCancel' => $this->allowCancel(),
                'pausable' => self::PAUSABLE,
                'resumable' => self::RESUMABLE,
                'cancellable' => self::CANCELLABLE,
            ]);
        });
    }

    /** POST /portal/pause — active → paused for a plan the signed customer owns. */
    public function pause(Request $request): RedirectResponse
    {
        return $this->actOnPlan($request, function (InstallmentPlan $plan): void {
            if (! $this->allowPause()) {
                abort(403);
            }
            // Idempotent: already paused is a no-op success (re-submit / double-click).
            if ($plan->status === PlanStatus::PAUSED) {
                return;
            }
            if (! in_array($plan->status, self::PAUSABLE, true)) {
                abort(422);
            }
            $this->lifecycle->pause($plan, 'customer_portal');
        });
    }

    /** POST /portal/resume — paused → active for a plan the signed customer owns. */
    public function resume(Request $request): RedirectResponse
    {
        return $this->actOnPlan($request, function (InstallmentPlan $plan): void {
            if (! $this->allowPause()) { // resume is gated by the same pause permission
                abort(403);
            }
            if ($plan->status === PlanStatus::ACTIVE) {
                return;
            }
            if (! in_array($plan->status, self::RESUMABLE, true)) {
                abort(422);
            }
            $this->lifecycle->resume($plan, 'customer_portal');
        });
    }

    /** POST /portal/cancel — non-terminal → cancelled for a plan the signed customer owns. */
    public function cancel(Request $request): RedirectResponse
    {
        return $this->actOnPlan($request, function (InstallmentPlan $plan): void {
            if (! $this->allowCancel()) {
                abort(403);
            }
            if ($plan->status === PlanStatus::CANCELLED) {
                return;
            }
            if (! in_array($plan->status, self::CANCELLABLE, true)) {
                abort(422);
            }
            $this->lifecycle->cancel($plan, 'customer_portal');
        });
    }

    /**
     * Shared action spine for pause/resume/cancel: verify the signature, bind the
     * tenant from the SIGNED shop, re-resolve the TARGET plan strictly within the
     * signed customer's scoped set (so cross-customer / cross-shop is impossible),
     * run the guarded lifecycle transition, then redirect back to a fresh signed
     * portal link. Tenant is cleared by Tenant::run.
     */
    private function actOnPlan(Request $request, callable $action): RedirectResponse
    {
        abort_unless($request->hasValidSignature(), 403);

        [$shop, $customerRef] = $this->resolveSignedContext($request);

        return Tenant::run($shop, function () use ($request, $customerRef, $action): RedirectResponse {
            // The action TARGET is the form body's plan; fall back to the signed
            // query plan (the entry plan) when no body field is posted. Either way
            // it is re-verified below against the signed customer's scoped set, so
            // the body can never be used to reach another customer's/shop's plan.
            $targetPublicId = (string) ($request->post('plan') ?? $request->query('plan', ''));

            // Tenant-scoped (BelongsToShop) AND customer-filtered: a customer can
            // only ever act on their OWN plan on THIS shop. Anything else 404s.
            $plan = $this->customerPlans($customerRef)
                ->where('public_id', $targetPublicId)
                ->first();

            if ($plan === null) {
                throw new NotFoundHttpException('Plan not found.');
            }

            $action($plan);

            return redirect()->to(app(PortalSignedUrlService::class)->showUrl($plan->refresh()));
        });
    }

    /**
     * Resolve + validate the signed {shop, plan, customer} triple.
     *
     * THE ONE AUDITED SCOPE BYPASS: we look up the entry plan by public_id WITHOUT
     * the tenant scope (acrossAllTenants) because no tenant is bound yet and the URL
     * is HMAC-signed (trusted). We then (a) confirm the plan's shop equals the signed
     * shop, and (b) confirm the signed customer ref actually matches that plan — so a
     * signature whose params were re-mixed across a valid signing key cannot point at
     * a plan that isn't the signed customer's. After this method, Tenant is bound and
     * every query is scoped again; this is the only place acrossAllTenants is touched.
     *
     * @return array{0: Shop, 1: string} [shop, signed customer ref]
     */
    private function resolveSignedContext(Request $request): array
    {
        $signedShopId = (int) $request->query('shop');
        $signedPublicId = (string) $request->query('plan', '');
        $signedCustomerRef = (string) $request->query('customer', '');

        if ($signedShopId <= 0 || $signedPublicId === '' || $signedCustomerRef === '') {
            throw new NotFoundHttpException('Malformed portal link.');
        }

        // AUDITED BYPASS — trusted because the URL signature already verified.
        $plan = InstallmentPlan::acrossAllTenants()
            ->where('public_id', $signedPublicId)
            ->first();

        if ($plan === null) {
            throw new NotFoundHttpException('Plan not found.');
        }

        // The signed shop MUST own the entry plan, and the signed customer ref MUST
        // be that plan's customer — otherwise the link is internally inconsistent.
        if ((int) $plan->shop_id !== $signedShopId
            || PortalSignedUrlService::customerRef($plan) !== $signedCustomerRef) {
            throw new NotFoundHttpException('Plan not found.');
        }

        $shop = Shop::query()->find($signedShopId);
        if ($shop === null) {
            throw new NotFoundHttpException('Unknown shop.');
        }

        return [$shop, $signedCustomerRef];
    }

    /**
     * Every plan for the signed customer on the BOUND shop. Tenant-scoped by
     * BelongsToShop (shop isolation) AND filtered to the signed customer identity
     * by the SAME precedence PortalSignedUrlService::customerRef used to sign it, so
     * the result set is exactly the link's own customer's plans — never anyone else's.
     *
     * The customer ref is "type:value"; we filter on the matching column only, so an
     * id can never collide with an email that happens to share the string.
     */
    private function customerPlans(string $customerRef): Builder
    {
        [$type, $value] = array_pad(explode(':', $customerRef, 2), 2, '');

        $query = InstallmentPlan::query();

        return match ($type) {
            'cid' => $query->where('customer_id', $value),
            'ext' => $query->where('external_customer_id', $value),
            'shopify' => $query->where('shopify_customer_id', $value),
            'email' => $query->whereRaw('LOWER(customer_email) = ?', [$value]),
            // Sentinel / unknown ref → match nothing (fail closed).
            default => $query->whereRaw('1 = 0'),
        };
    }

    private function allowPause(): bool
    {
        return (bool) config('portal.allow_customer_pause', true);
    }

    private function allowCancel(): bool
    {
        return (bool) config('portal.allow_customer_cancel', true);
    }
}
