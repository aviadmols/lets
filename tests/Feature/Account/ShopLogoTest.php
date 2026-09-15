<?php

namespace Tests\Feature\Account;

use App\Models\MerchantPortalAppearance;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\View\ComponentAttributeBag;
use Tests\TestCase;

/**
 * THE MERCHANT'S LOGO, on the pages their customers actually see.
 *
 * A shopper arriving from an email to type a card number is answering one
 * question before anything else: is this really my shop? Our mark answers the
 * wrong one — "let's" is a name they have never heard, on a page asking for card
 * details, which is exactly the shape of every phishing page they have been
 * warned about.
 *
 * A URL rather than an upload, and that is a deployment fact rather than a
 * preference: the app runs on containers whose filesystem does not survive a
 * deploy, so an uploaded file would vanish silently and the logo would disappear
 * from a payment page on some unrelated Tuesday.
 *
 * The value is re-validated ON EVERY READ, not merely on save. It is rendered
 * into an `src` on the one page that has to look trustworthy, so a row written
 * before there was a rule — or by any path that bypassed the form — must not
 * reach the page.
 */
final class ShopLogoTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Tenant::set(Shop::create([
            'woocommerce_domain' => 'logo.example.com',
            'name' => 'Logo Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]));
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_an_https_logo_is_used(): void
    {
        $this->assertSame(
            'https://shop.example.com/logo.png',
            $this->logo('https://shop.example.com/logo.png'),
        );
    }

    /** Not set is not an error — it falls back to ours. */
    public function test_no_logo_reads_as_none(): void
    {
        $this->assertNull($this->logo(null));
        $this->assertNull($this->logo(''));
        $this->assertNull($this->logo('   '));
    }

    /**
     * RELEASE BLOCKER. These are the three shapes that turn an image slot into
     * something else, and this string lands in an `src` on a card-entry page.
     */
    public function test_a_url_that_is_not_an_https_image_address_is_refused(): void
    {
        foreach ([
            'javascript:alert(1)',
            'data:text/html;base64,PHNjcmlwdD4=',
            'http://shop.example.com/logo.png', // blocked as mixed content anyway
            '//shop.example.com/logo.png',
            'https://',
            'shop.example.com/logo.png',
            'https:// shop.example.com/logo.png',
        ] as $bad) {
            $this->assertNull($this->logo($bad), "should be refused: {$bad}");
        }
    }

    /** Longer than the column can hold is not a URL anybody typed. */
    public function test_an_absurdly_long_url_is_refused(): void
    {
        $long = 'https://shop.example.com/'.str_repeat('a', MerchantPortalAppearance::MAX_LOGO_URL);

        $this->assertNull($this->logo($long));
    }

    /** The customer-facing card-update page carries it. */
    public function test_the_card_update_page_shows_the_merchants_logo(): void
    {
        $rendered = $this->renderLogoComponent('https://shop.example.com/logo.png');

        $this->assertStringContainsString('https://shop.example.com/logo.png', $rendered);
        $this->assertStringNotContainsString('rc-logo__word', $rendered, 'ours must not also render');
    }

    /** With none set, our mark still draws — the page is never logo-less. */
    public function test_our_logo_is_the_fallback(): void
    {
        $rendered = $this->renderLogoComponent(null);

        $this->assertStringContainsString('rc-logo', $rendered);
    }

    private function renderLogoComponent(?string $url): string
    {
        MerchantPortalAppearance::current()->forceFill(['logo_url' => $url])->save();

        return (string) view('components.rc.shop-logo', [
            'attributes' => new ComponentAttributeBag(['class' => 'rc-campaign-card__logo']),
            'logo' => null,
        ])->render();
    }

    private function logo(?string $url): ?string
    {
        $settings = MerchantPortalAppearance::current();
        $settings->forceFill(['logo_url' => $url])->save();

        return $settings->fresh()->logoUrl();
    }
}
