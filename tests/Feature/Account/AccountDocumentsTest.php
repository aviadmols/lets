<?php

namespace Tests\Feature\Account;

use App\Domain\Account\AccountPresenter;
use App\Domain\Account\AccountVisitor;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The customer's invoices and receipts, standing on their own.
 *
 * WHY THIS SHELF EXISTS. A shop that has switched "create an order for each
 * renewal" off has renewals with real money, a real ledger row and a real tax
 * document — and no order anywhere. WooCommerce's own Orders tab has nothing to
 * show for them, and the receipt link we already render lives inside a
 * subscription card, folded behind the payment history. So the paperwork existed
 * and the customer could not reach it. This is the list that fixes that, and these
 * are the walls around it: only ISSUED documents, only for subscriptions this
 * visitor can already see, and never somebody else's.
 */
final class AccountDocumentsTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'docs.example.com',
            'name' => 'Docs',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_it_lists_the_issued_documents_for_the_visitors_subscriptions(): void
    {
        $plan = $this->plan('dana@example.com', 'Club membership');

        $this->document($plan, number: '2026-0412', url: 'https://invoices.example/412', amount: 39.0);
        $this->document($plan, number: '2026-0381', url: 'https://invoices.example/381', amount: 39.0, daysAgo: 32);

        $documents = $this->present('dana@example.com')['documents'];

        $this->assertCount(2, $documents);
        // Newest first — a shopper opening this page is looking for the last one.
        $this->assertSame('2026-0412', $documents[0]['number']);
        $this->assertSame('https://invoices.example/412', $documents[0]['url']);
        $this->assertSame(39.0, $documents[0]['amount']);
        $this->assertSame('₪', $documents[0]['currency_symbol']);
        // WHAT IT WAS FOR — two receipts of the same amount are otherwise
        // indistinguishable to somebody with two subscriptions.
        $this->assertSame('Club membership', $documents[0]['title']);
    }

    /**
     * The case the shelf was built for: a renewal with no order behind it.
     *
     * The document carries no external_order_id at all, and it must still reach
     * the customer — that is the whole point.
     */
    public function test_a_document_with_no_order_behind_it_still_reaches_the_customer(): void
    {
        $plan = $this->plan('dana@example.com', 'Club membership');

        $doc = $this->document($plan, number: '2026-0500', url: 'https://invoices.example/500', amount: 39.0);
        $this->assertNull($doc->external_order_id, 'no order — the switch was off');

        $documents = $this->present('dana@example.com')['documents'];

        $this->assertCount(1, $documents);
        $this->assertSame('2026-0500', $documents[0]['number']);
    }

    /**
     * A document that is not ISSUED is the SaaS admin's work queue, never the
     * customer's. Showing a receipt that does not exist yet earns a support call
     * and produces no invoice.
     */
    public function test_failed_unresolved_and_url_less_documents_are_never_shown(): void
    {
        $plan = $this->plan('dana@example.com', 'Club membership');

        $this->document($plan, number: 'good', url: 'https://invoices.example/ok', amount: 39.0);
        $this->document($plan, number: 'bad', url: 'https://invoices.example/bad', amount: 39.0, status: IssuedDocument::STATUS_FAILED);
        $this->document($plan, number: 'waiting', url: 'https://invoices.example/w', amount: 39.0, status: IssuedDocument::STATUS_UNRESOLVED);
        $this->document($plan, number: 'no-url', url: null, amount: 39.0);

        $documents = $this->present('dana@example.com')['documents'];

        $this->assertSame(['good'], array_column($documents, 'number'));
    }

    /** RELEASE BLOCKER: another customer's paperwork is never on this page. */
    public function test_it_never_shows_another_customers_documents(): void
    {
        $mine = $this->plan('dana@example.com', 'Club membership');
        $theirs = $this->plan('yossi@example.com', 'Coffee box');

        $this->document($mine, number: 'mine', url: 'https://invoices.example/mine', amount: 39.0);
        $this->document($theirs, number: 'theirs', url: 'https://invoices.example/theirs', amount: 89.0);

        $documents = $this->present('dana@example.com')['documents'];

        $this->assertSame(['mine'], array_column($documents, 'number'));
    }

    /** RELEASE BLOCKER: another shop's paperwork is never on this page either. */
    public function test_it_never_shows_another_shops_documents(): void
    {
        $mine = $this->plan('dana@example.com', 'Club membership');
        $this->document($mine, number: 'mine', url: 'https://invoices.example/mine', amount: 39.0);

        $other = Shop::create([
            'woocommerce_domain' => 'other-docs.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::run($other, function (): void {
            // Same email, different shop — the shape a shared shopper produces.
            $plan = $this->plan('dana@example.com', 'Their product');
            $this->document($plan, number: 'theirs', url: 'https://invoices.example/theirs', amount: 10.0);
        });

        $documents = $this->present('dana@example.com')['documents'];

        $this->assertSame(['mine'], array_column($documents, 'number'));
    }

    /** A merchant who hid the section gets no query and no list. */
    public function test_hiding_the_section_hides_the_documents(): void
    {
        $plan = $this->plan('dana@example.com', 'Club membership');
        $this->document($plan, number: '2026-0412', url: 'https://invoices.example/412', amount: 39.0);

        MerchantPortalAppearance::current()->forceFill([
            'sections' => collect(MerchantPortalAppearance::SECTION_KEYS)
                ->map(fn (string $key): array => [
                    'key' => $key,
                    'enabled' => $key !== MerchantPortalAppearance::SECTION_DOCUMENTS,
                ])
                ->all(),
        ])->save();

        $this->assertSame([], $this->present('dana@example.com')['documents']);
    }

    /** A logged-out visitor is served the shell — with the key present and empty. */
    public function test_a_logged_out_visitor_gets_an_empty_list_rather_than_a_missing_key(): void
    {
        $payload = app(AccountPresenter::class)->present(AccountVisitor::make(
            shop: $this->shop,
            customerRef: null,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
        ));

        $this->assertFalse($payload['identified']);
        $this->assertSame([], $payload['documents']);
    }

    /** The merchant's preview shows the shelf, or they cannot tell it is on. */
    public function test_the_admin_preview_includes_sample_documents(): void
    {
        $documents = app(AccountPresenter::class)->sample()['documents'];

        $this->assertNotEmpty($documents);
        $this->assertArrayHasKey('number', $documents[0]);
        $this->assertArrayHasKey('url', $documents[0]);
        $this->assertArrayHasKey('title', $documents[0]);
    }

    // === Fixtures ===

    /** @return array<string, mixed> */
    private function present(string $email): array
    {
        return app(AccountPresenter::class)->present(AccountVisitor::make(
            shop: Tenant::current(),
            // The reference the platform vouched for. The email alone does not
            // identify a visitor — an unidentified one matches nothing at all.
            customerRef: $email,
            source: AccountVisitor::SOURCE_WOOCOMMERCE,
            email: $email,
        ));
    }

    private function plan(string $email, string $title): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => Tenant::id(),
            'public_id' => 'PLN-'.uniqid(),
            'customer_name' => 'Dana Levi',
            'customer_email' => $email,
            'external_customer_id' => $email,
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 39,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10),
            'meta' => ['item_title' => $title],
        ])->save();

        return $plan;
    }

    private function document(
        InstallmentPlan $plan,
        string $number,
        ?string $url,
        float $amount,
        int $daysAgo = 1,
        string $status = IssuedDocument::STATUS_ISSUED,
    ): IssuedDocument {
        $doc = new IssuedDocument;
        $doc->forceFill([
            'shop_id' => $plan->shop_id,
            'plan_id' => $plan->getKey(),
            'provider' => 'green_invoice',
            'context' => 'recurring',
            'idempotency_key' => 'doc:'.$number.':'.$plan->getKey(),
            'status' => $status,
            'document_number' => $number,
            'document_url' => $url,
            'amount' => $amount,
            'currency' => 'ILS',
            'issued_at' => now()->subDays($daysAgo),
        ])->save();

        return $doc;
    }
}
