<?php

namespace Tests\Feature\Mail;

use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Product;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\EmailPreviewRenderer;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * The email preview a merchant opens from a plan's Timeline.
 *
 * Two things were wrong with it. It rendered the email's HTML as visible source
 * text, because the markup was escaped twice before it reached the iframe. And it
 * was filled with SAMPLE data — a stranger's name and a made-up amount — on a
 * screen whose entire purpose is "what did THIS customer receive".
 */
final class TimelineEmailPreviewTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const TEMPLATE = MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'preview.example.com',
            'name' => 'חנות מאיר',
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

    public function test_the_iframe_receives_markup_the_browser_can_render(): void
    {
        $html = Blade::render(
            '@include("filament.pages.partials.mail-preview", ["subject" => $subject, "html" => $html, "isCustom" => false])',
            ['subject' => 'Receipt', 'html' => '<p>שלום דנה</p>'],
        );

        // Blade's own {{ }} escapes ONCE into the attribute; the browser decodes it
        // and the iframe renders a paragraph. A second e() would leave &amp;lt;p&amp;gt;
        // here, which is what showed the merchant their HTML as source text.
        $this->assertStringContainsString('srcdoc="&lt;p&gt;', $html);
        $this->assertStringNotContainsString('&amp;lt;p&amp;gt;', $html);

        // The isolation the srcdoc contract depends on is still in place.
        $this->assertStringContainsString('sandbox=""', $html);
    }

    public function test_a_plan_preview_shows_that_customer_not_a_sample_one(): void
    {
        $plan = $this->plan(name: 'אביעד מולשצקי', email: 'aviadmols@gmail.com');

        $preview = EmailPreviewRenderer::forPlan(self::TEMPLATE, $plan, $this->shop);

        $this->assertStringContainsString('אביעד מולשצקי', $preview['html']);
        // The sample bag's name and business must not appear anywhere near a real
        // customer's record.
        $this->assertStringNotContainsString('דנה כהן', $preview['html']);
        $this->assertStringNotContainsString('החנות שלי', $preview['html']);
        $this->assertStringContainsString('חנות מאיר', $preview['html']);
    }

    public function test_the_preview_names_the_product_the_plan_carries(): void
    {
        // The checkout rail writes item_title; reading only product_title is why
        // real emails said "your order".
        $plan = $this->plan(meta: ['item_title' => 'מנוי שיבולת']);

        $preview = EmailPreviewRenderer::forPlan(self::TEMPLATE, $plan, $this->shop);

        $this->assertStringContainsString('מנוי שיבולת', $preview['html']);
        $this->assertStringNotContainsString('מנוי חודשי', $preview['html']); // the sample
    }

    public function test_the_send_details_beat_the_plans_current_numbers(): void
    {
        $plan = $this->plan();

        $preview = EmailPreviewRenderer::forPlan(
            self::TEMPLATE,
            $plan,
            $this->shop,
            ['amount' => '73.00', 'sequence' => 3],
        );

        // What was charged that day, not what the plan would charge today.
        $this->assertStringContainsString('73.00', $preview['html']);
        $this->assertStringNotContainsString('149.00', $preview['html']); // the sample
    }

    public function test_an_unrecoverable_value_renders_blank_not_invented(): void
    {
        $plan = $this->plan();

        $vars = EmailPreviewRenderer::planVarsFor(
            MerchantMailSettings::TEMPLATE_CHARGE_FAILED,
            $plan,
            $this->shop,
        );

        // No event details, so nothing recorded the failure reason. A blank is a
        // gap the merchant can see; the sample text ("הכרטיס נדחה") would read as
        // something this customer was actually told.
        $this->assertSame('', $vars['failure_reason']);
        $this->assertArrayNotHasKey('customer_email', $vars, 'only this template’s placeholders are filled');
    }

    public function test_a_recorded_reason_is_used_when_there_is_one(): void
    {
        $plan = $this->plan();

        $vars = EmailPreviewRenderer::planVarsFor(
            MerchantMailSettings::TEMPLATE_CHARGE_FAILED,
            $plan,
            $this->shop,
            ['reason' => 'הכרטיס פג תוקף'],
        );

        $this->assertSame('הכרטיס פג תוקף', $vars['failure_reason']);
    }

    public function test_an_open_ended_subscription_is_never_told_it_finished(): void
    {
        $plan = $this->plan();

        $preview = EmailPreviewRenderer::forPlan(
            self::TEMPLATE,
            $plan,
            $this->shop,
            ['amount' => '1.00', 'sequence' => 3],
        );

        // A recurring plan has no total to count towards. Deriving one from
        // total/per made it "payment 3 of 1" — which reads as "your subscription
        // ended two cycles ago".
        $this->assertStringNotContainsString('מתוך', $preview['html']);
        $this->assertStringContainsString('התקבל בהצלחה.', $preview['html']);
    }

    public function test_an_installments_plan_still_counts_its_payments(): void
    {
        $plan = $this->plan(meta: ['item_title' => 'ספר', 'installment_count' => 6]);
        $plan->forceFill(['plan_kind' => PlanKind::INSTALLMENTS->value])->save();

        $preview = EmailPreviewRenderer::forPlan(
            self::TEMPLATE,
            $plan->fresh(),
            $this->shop,
            ['sequence' => 2],
        );

        // Where a total genuinely exists, the customer still gets their progress.
        $this->assertStringContainsString('תשלום 2 מתוך 6', $preview['html']);
    }

    public function test_the_settings_page_preview_still_uses_samples(): void
    {
        // Nobody has received that template yet, so there is no real customer to
        // show — this path must not change.
        $preview = EmailPreviewRenderer::preview(self::TEMPLATE, null);

        $this->assertStringContainsString('דנה כהן', $preview['html']);
    }

    public function test_the_product_title_falls_back_to_the_synced_catalog(): void
    {
        $product = new Product;
        $product->forceFill([
            'shop_id' => $this->shop->getKey(),
            'source' => Product::SOURCE_WOOCOMMERCE,
            'external_id' => '2666',
            'title' => 'מנוי שיבולת',
            'status' => Product::STATUS_ACTIVE,
        ])->save();

        // A plan whose meta predates the title still knows which product it renews.
        $plan = $this->plan(meta: [], externalProductId: '2666');

        $this->assertSame('מנוי שיבולת', $plan->productTitle());
    }

    // === Fixtures ===

    /** @param array<string, mixed> $meta */
    private function plan(
        string $name = 'אביעד מולשצקי',
        string $email = 'aviadmols@gmail.com',
        array $meta = ['item_title' => 'מנוי שיבולת'],
        ?string $externalProductId = null,
    ): InstallmentPlan {
        $plan = new InstallmentPlan;
        $plan->fill([
            'plan_kind' => PlanKind::RECURRING->value,
            'charge_context' => 'recurring',
            'total_amount' => 100,
            'installment_amount' => 1,
            'currency' => 'ILS',
            'public_id' => (string) Str::ulid(),
            'customer_name' => $name,
            'customer_email' => $email,
            'external_product_id' => $externalProductId,
            'meta' => $meta,
        ]);
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'status' => PlanStatus::ACTIVE->value,
        ])->save();

        return $plan->fresh();
    }
}
