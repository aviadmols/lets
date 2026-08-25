<?php

namespace App\Http\Controllers\Admin;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Models\InstallmentPlan;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Support\Timeline;
use App\Support\Tenant;
use App\Support\Ui\PanelAccess;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * "View as customer", read-only — the personal area rendered with ONE REAL
 * shopper's data, inside the merchant's admin.
 *
 * This is the support tool a Shopify shop cannot have as a session (Shopify
 * mints no customer storefront sessions), and a safer first reach on
 * WooCommerce too: the admin sees exactly the page the shopper is describing
 * on the phone, without becoming them. Same host blade + the same
 * lets-account.js as the appearance preview, with `preview: true` making every
 * control inert — a view that cannot cancel, pause or buy anything.
 *
 * Visitor identity comes from the CUSTOMER RECORD (ref + the newest plan's
 * email), never from anything the browser sent — the admin session and
 * PanelAccess are the authorization; the ref only picks WHICH customer.
 *
 * Every open is a personal-data access: logged with the admin's id against it
 * and pinned to the customer's own Timeline, as a KIND of its own — "looked at
 * their account" must never blur with "became them in the store".
 */
final class AdminCustomerAccountViewController
{
    // === CONSTANTS ===
    /** The personal-data access-log surface name (security-policies.md §5). */
    public const SURFACE = 'account_view_as';

    public function __invoke(Request $request, string $customer, AccountPresenter $presenter): View
    {
        $shop = Tenant::current();
        if (! PanelAccess::canSeeShopScoped() || ! $shop instanceof Shop) {
            throw new NotFoundHttpException;
        }

        $customer = trim($customer);
        if ($customer === '') {
            throw new NotFoundHttpException;
        }

        // The store's own record of this person: their newest plan carrying an
        // email/name. Server-side data — this is what makes the email a safe
        // second matcher here when it never is on a client-asserted rail.
        $newest = InstallmentPlan::query()
            ->where(function ($q) use ($customer): void {
                $q->where('external_customer_id', $customer)
                    ->orWhere('shopify_customer_id', $customer);
                if (ctype_digit($customer)) {
                    $q->orWhere('customer_id', $customer);
                }
            })
            ->orderByDesc('id')
            ->get()
            ->first(fn (InstallmentPlan $p): bool => trim((string) $p->customer_email) !== '');

        $visitor = AccountVisitor::make(
            shop: $shop,
            customerRef: $customer,
            source: AccountVisitor::SOURCE_PREVIEW,
            email: $newest?->customer_email,
            name: $newest?->customer_name,
        );

        // PERSONAL-DATA ACCESS LOG (docs/security/security-policies.md §5) —
        // the same shape the login-as action and CustomerContactReader write.
        Log::info('privacy.personal_data_accessed', [
            'shop_id' => (int) $shop->getKey(),
            'customer_ref' => $customer,
            'surface' => self::SURFACE,
            'user_id' => auth()->id(),
        ]);

        Timeline::record(
            kind: Timeline::KIND_CUSTOMER_VIEWED_AS,
            details: ['customer' => $customer],
            planId: $newest?->getKey(),
            shopId: (int) $shop->getKey(),
        );

        $settings = MerchantPortalAppearance::current();
        if ($settings->pageLocale() !== MerchantPortalAppearance::LOCALE_AUTO) {
            app()->setLocale($settings->pageLocale());
        }

        return view('account.admin-view', [
            'model' => $presenter->present($visitor),
        ]);
    }
}
