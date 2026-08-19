<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Domain\Customers\CustomerPlans;
use App\Models\InstallmentPlan;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use App\Support\Ui\PanelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * The personal area, rendered inside the admin — in two modes.
 *
 * Both load the SAME public/account/lets-account.{css,js} the storefront runs, so
 * what the merchant sees here cannot drift from what a shopper gets; if it looks
 * wrong here, it is wrong there.
 *
 *   NO ?customer  — the appearance PREVIEW. AccountPresenter::sample() invents a
 *                   subscriber; only the settings are real, because those are
 *                   what is being tuned. Nothing reads a shopper's data.
 *
 *   ?customer=REF — "view as this customer": their REAL area, exactly as they
 *                   see it. For the support call where a merchant needs to know
 *                   what the person on the phone is looking at.
 *
 * THE VIEW IS READ-ONLY. The host document renders with `preview: true`, which
 * makes every control inert, and no plugin endpoint is handed to it — so a
 * merchant cannot pause or cancel from inside somebody's page by accident. The
 * admin's own screens are where a subscription is acted on, with confirmation
 * copy and a Timeline entry naming who did it.
 *
 * It is also NOT a login. No session is minted for the shopper anywhere; this
 * renders their data in the merchant's own authenticated session.
 *
 * Registered on the Filament panel's own ->routes() so it inherits the session,
 * BindTenantFromUser and SetAdminLocale — the same wiring the loyalty preview uses.
 */
final class AdminAccountPreviewController
{
    public function __invoke(Request $request, AccountPresenter $presenter): View
    {
        if (! PanelAccess::canSeeShopScoped() || ! Tenant::check()) {
            throw new NotFoundHttpException;
        }

        $settings = MerchantPortalAppearance::current();

        // Preview the LANGUAGE too, not only the colours — it is the setting that
        // changes every word on the page. "Follow the store" has no store here, so
        // it stays in the admin's own language, which is the honest stand-in.
        if ($settings->pageLocale() !== MerchantPortalAppearance::LOCALE_AUTO) {
            app()->setLocale($settings->pageLocale());
        }

        $customer = trim((string) $request->query('customer', ''));

        return view('account.preview', [
            'model' => $customer !== ''
                ? $this->forCustomer($presenter, $settings, $customer)
                : $presenter->sample($settings),
        ]);
    }

    /**
     * One customer's real area.
     *
     * The reference is resolved against THIS shop's plans (CustomerPlans is
     * tenant-scoped through BelongsToShop), so a reference belonging to another
     * shop's customer resolves to nothing and 404s rather than rendering a
     * stranger's subscriptions. A reference with no plans at all does the same:
     * there is no page to show, and an empty one would look like data loss.
     *
     * @return array<string, mixed>
     */
    private function forCustomer(AccountPresenter $presenter, MerchantPortalAppearance $settings, string $customer): array
    {
        $plans = CustomerPlans::query($customer)->get();

        if ($plans->isEmpty()) {
            throw new NotFoundHttpException;
        }

        $shop = Tenant::current();
        if (! $shop instanceof Shop) {
            throw new NotFoundHttpException;
        }

        // PERSONAL-DATA ACCESS LOG (docs/security/security-policies.md §5): looking
        // at a shopper's own page is a read of their data, and it goes on the
        // record with a name against it — the same rule CustomerContactReader
        // follows for contact details.
        Log::info('privacy.personal_data_accessed', [
            'shop_id' => $shop->getKey(),
            'customer_ref' => $customer,
            'surface' => 'account_view_as',
            'user_id' => auth()->id(),
        ]);

        $named = $plans->first(static fn (InstallmentPlan $p): bool => trim((string) $p->customer_name) !== '');

        return $presenter->present(AccountVisitor::make(
            shop: $shop,
            customerRef: $customer,
            source: AccountVisitor::SOURCE_PREVIEW,
            email: $plans->first(static fn (InstallmentPlan $p): bool => trim((string) $p->customer_email) !== '')?->customer_email,
            name: $named?->customer_name,
            phone: $plans->first(static fn (InstallmentPlan $p): bool => trim((string) $p->customer_phone) !== '')?->customer_phone,
        ));
    }
}
