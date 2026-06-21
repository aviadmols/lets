<?php

namespace Tests\Feature\Mail;

use App\Mail\Support\TemplateRenderer;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\DefaultEmailTemplates;
use App\Support\EmailPreviewRenderer;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Part A: the per-shop email engine — settings isolation, the strtr-NOT-Blade
 * RCE safeguard, and custom-vs-default template selection.
 */
final class MailEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_merchant_mail_settings_are_isolated_per_shop(): void
    {
        $shopA = $this->makeShop('a-mail.myshopify.com');
        $shopB = $this->makeShop('b-mail.myshopify.com');

        // Shop A sets a custom welcome subject; shop B never touches it.
        Tenant::run($shopA, function (): void {
            $settings = MerchantMailSettings::current();
            $settings->first_payment_welcome_subject = 'A custom subject';
            $settings->save();
        });

        $bSettings = Tenant::run($shopB, fn () => MerchantMailSettings::current());

        // B's row is a different row with no custom subject — A's value never leaks.
        $this->assertNull($bSettings->customSubject(MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME));
        $this->assertNotSame(
            Tenant::run($shopA, fn () => MerchantMailSettings::current())->getKey(),
            $bSettings->getKey(),
        );

        // The global scope hides A's row from B entirely.
        $visibleToB = Tenant::run($shopB, fn () => MerchantMailSettings::query()->get());
        $this->assertCount(1, $visibleToB);
        $this->assertSame($shopB->id, (int) $visibleToB->first()->shop_id);
    }

    public function test_current_returns_one_row_per_shop_and_never_recreates(): void
    {
        $shop = $this->makeShop('single.myshopify.com');

        [$first, $second] = Tenant::run($shop, fn () => [
            MerchantMailSettings::current()->getKey(),
            MerchantMailSettings::current()->getKey(),
        ]);

        $this->assertSame($first, $second);
        // Count INSIDE the tenant context (the BelongsToShop global scope filters by
        // the bound shop; querying unbound would scope to a different/no tenant).
        $count = Tenant::run($shop, fn () => MerchantMailSettings::query()->where('shop_id', $shop->id)->count());
        $this->assertSame(1, $count);
    }

    public function test_template_renderer_substitutes_via_strtr_only(): void
    {
        $html = TemplateRenderer::render(
            'Hello {customer_name}, you owe {amount} {currency}.',
            ['customer_name' => 'Dana', 'amount' => '149.00', 'currency' => 'ILS'],
        );

        $this->assertSame('Hello Dana, you owe 149.00 ILS.', $html);
    }

    public function test_template_renderer_does_not_evaluate_blade_or_php_in_merchant_input(): void
    {
        // Merchant body laced with Blade/PHP injection attempts. strtr must treat
        // every one as inert literal text — nothing is compiled or evaluated.
        $malicious = 'X {{ 7*7 }} Y @php echo "pwned"; @endphp Z {!! system("id") !!} '
            .'{{ $shop->payplus_credentials }} W {customer_name}';

        $out = TemplateRenderer::render($malicious, ['customer_name' => 'Dana']);

        // The only replacement that happens is the real {customer_name} token.
        $this->assertStringContainsString('Dana', $out);
        // Blade/PHP markup survives VERBATIM (proves no compilation occurred).
        $this->assertStringContainsString('{{ 7*7 }}', $out);
        $this->assertStringContainsString('@php echo "pwned"; @endphp', $out);
        $this->assertStringContainsString('{!! system("id") !!}', $out);
        $this->assertStringContainsString('{{ $shop->payplus_credentials }}', $out);
        // 7*7 must NOT have been evaluated to 49 (no PHP/Blade execution at all).
        $this->assertStringNotContainsString('49', $out);
        // Strongest proof: the output equals the input with ONLY the real
        // {customer_name} token replaced. (The literal text "pwned" survives verbatim
        // precisely BECAUSE strtr did not execute the @php block — the safe outcome.)
        $this->assertSame(
            'X {{ 7*7 }} Y @php echo "pwned"; @endphp Z {!! system("id") !!} '
            .'{{ $shop->payplus_credentials }} W Dana',
            $out,
        );
    }

    public function test_a_value_containing_a_token_is_not_re_expanded(): void
    {
        // Single-pass guarantee: a replacement value that itself looks like a token
        // must not trigger another substitution.
        $out = TemplateRenderer::render('Hi {customer_name}', [
            'customer_name' => '{amount}',
            'amount' => 'SHOULD_NOT_APPEAR',
        ]);

        $this->assertSame('Hi {amount}', $out);
        $this->assertStringNotContainsString('SHOULD_NOT_APPEAR', $out);
    }

    public function test_custom_body_is_used_when_set_and_default_when_blank(): void
    {
        $shop = $this->makeShop('choice.myshopify.com');

        Tenant::run($shop, function () use ($shop): void {
            $settings = MerchantMailSettings::current();

            // Blank => default. Confirm the preview falls back to the default body.
            $defaultPreview = EmailPreviewRenderer::preview(
                MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME,
                $settings,
            );
            $this->assertFalse($defaultPreview['is_custom']);
            $this->assertStringContainsString(
                'התשלום הראשון',
                $defaultPreview['html'],
                'Default Hebrew welcome copy should render.',
            );

            // Set a custom body => it is used.
            $settings->first_payment_welcome_body = '<p>Custom for {customer_name}</p>';
            $settings->save();

            $customPreview = EmailPreviewRenderer::preview(
                MerchantMailSettings::TEMPLATE_FIRST_PAYMENT_WELCOME,
                $settings->fresh(),
            );
            $this->assertTrue($customPreview['is_custom']);
            $this->assertStringContainsString('Custom for', $customPreview['html']);
            // The sample customer_name is substituted via strtr.
            $this->assertStringNotContainsString('{customer_name}', $customPreview['html']);
        });
    }

    public function test_default_templates_expose_subject_body_and_placeholders(): void
    {
        foreach (MerchantMailSettings::TEMPLATES as $template) {
            $this->assertNotSame('', DefaultEmailTemplates::subject($template));
            $this->assertNotSame('', DefaultEmailTemplates::body($template));
            $this->assertNotEmpty(DefaultEmailTemplates::placeholders($template));
        }
    }

    public function test_plan_var_bag_is_built_from_the_plan(): void
    {
        $shop = $this->makeShop('vars.myshopify.com');

        Tenant::run($shop, function () use ($shop): void {
            $plan = InstallmentPlan::create([
                'plan_kind' => PlanKind::INSTALLMENTS->value,
                'total_amount' => 600,
                'installment_amount' => 100,
                'currency' => 'ILS',
                'customer_name' => 'Dana Cohen',
                'customer_email' => 'dana@example.com',
                'meta' => ['product_title' => 'Cool Widget', 'installment_count' => 6],
            ]);
            $plan->forceFill(['status' => PlanStatus::ACTIVE->value])->save();

            $vars = TemplateRenderer::planVars($plan, businessName: $shop->name);

            $this->assertSame('Dana Cohen', $vars['customer_name']);
            $this->assertSame('Cool Widget', $vars['product_title']);
            $this->assertSame('6', $vars['installment_count']);
            $this->assertSame('100.00', $vars['amount']);
            $this->assertSame('ILS', $vars['currency']);
            $this->assertSame($shop->name, $vars['business_name']);
        });
    }

    private function makeShop(string $domain): Shop
    {
        $shop = Shop::create([
            'shopify_domain' => $domain,
            'name' => 'Store '.$domain,
            'status' => Shop::STATUS_INSTALLED,
        ]);
        $shop->payplus_credentials = ['api_key' => 'k', 'secret_key' => 's', 'terminal_uid' => 't'];
        $shop->save();

        return $shop;
    }
}
