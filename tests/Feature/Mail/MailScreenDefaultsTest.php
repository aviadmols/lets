<?php

namespace Tests\Feature\Mail;

use App\Filament\Pages\ManageMailSettings;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\DefaultEmailTemplates;
use App\Support\EmailPreviewRenderer;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The email screen now shows the real default copy in the editors instead of a
 * grey placeholder. That is only safe because of one rule, and these tests exist
 * to hold it: saving text that IS the default stores null.
 *
 * Without the rule, the first save on this screen would silently promote every
 * shop to "customised" — freezing today's wording forever, making "Restore
 * default" a no-op, and cutting them off from every improvement we ship later.
 */
final class MailScreenDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private const TEMPLATE = MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'shopify_domain' => 'mail-defaults.myshopify.com',
            'name' => 'Mail',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);

        $user = User::factory()->create();
        $user->shop_id = $this->shop->getKey();
        $user->save();
        $this->actingAs($user);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_editors_open_showing_the_real_default_copy(): void
    {
        $component = Livewire::test(ManageMailSettings::class);

        // The whole complaint about the old screen: a merchant who wanted to
        // change one sentence had to find the default HTML elsewhere and retype it.
        $body = $component->get('data.'.self::TEMPLATE.'_body');
        $this->assertNotEmpty($body);
        $this->assertStringContainsString('{amount}', $body);
    }

    public function test_saving_the_untouched_default_leaves_the_shop_on_the_default(): void
    {
        Livewire::test(ManageMailSettings::class)->call('save')->assertHasNoFormErrors();

        $settings = MerchantMailSettings::current()->refresh();

        foreach (MerchantMailSettings::TEMPLATES as $template) {
            $this->assertNull($settings->customBody($template), "[{$template}] was stored as a custom override.");
            $this->assertNull($settings->customSubject($template));
            $this->assertFalse($settings->hasCustomTemplate($template));
        }
    }

    public function test_whitespace_alone_does_not_make_a_shop_customised(): void
    {
        $default = $this->defaultBody(self::TEMPLATE, DefaultEmailTemplates::LOCALE_HE);

        Livewire::test(ManageMailSettings::class)
            // The code editor round-trips a trailing newline; that must not read
            // as an edit.
            ->set('data.'.self::TEMPLATE.'_body', $default."\n  ")
            ->call('save');

        $this->assertNull(MerchantMailSettings::current()->refresh()->customBody(self::TEMPLATE));
    }

    public function test_a_real_edit_is_stored(): void
    {
        Livewire::test(ManageMailSettings::class)
            ->set('data.'.self::TEMPLATE.'_body', '<p>Thanks, {customer_name}!</p>')
            ->call('save');

        $this->assertSame(
            '<p>Thanks, {customer_name}!</p>',
            MerchantMailSettings::current()->refresh()->customBody(self::TEMPLATE),
        );
    }

    public function test_restoring_the_default_puts_the_default_copy_back_in_the_editor(): void
    {
        $component = Livewire::test(ManageMailSettings::class)
            ->set('data.'.self::TEMPLATE.'_body', '<p>Mine</p>')
            ->call('save')
            ->call('restoreDefault', self::TEMPLATE);

        $this->assertNull(MerchantMailSettings::current()->refresh()->customBody(self::TEMPLATE));
        // An empty box would read as "we deleted it".
        $this->assertNotEmpty($component->get('data.'.self::TEMPLATE.'_body'));
    }

    // === Language ===

    public function test_switching_the_language_refills_untouched_defaults(): void
    {
        $component = Livewire::test(ManageMailSettings::class)
            ->set('data.email_locale', DefaultEmailTemplates::LOCALE_EN);

        $body = $component->get('data.'.self::TEMPLATE.'_body');

        $this->assertStringContainsString('Amount:', $body);
        $this->assertStringContainsString('dir="ltr"', $body);
    }

    public function test_switching_the_language_never_overwrites_the_merchants_own_words(): void
    {
        Livewire::test(ManageMailSettings::class)
            ->set('data.'.self::TEMPLATE.'_body', '<p>Mine</p>')
            ->call('save');

        $component = Livewire::test(ManageMailSettings::class)
            ->set('data.email_locale', DefaultEmailTemplates::LOCALE_EN);

        // They wrote it. Replacing it because a toggle moved would be the worst
        // thing this screen could do.
        $this->assertSame('<p>Mine</p>', $component->get('data.'.self::TEMPLATE.'_body'));
    }

    public function test_the_chosen_language_is_persisted_and_guarded(): void
    {
        Livewire::test(ManageMailSettings::class)
            ->set('data.email_locale', DefaultEmailTemplates::LOCALE_EN)
            ->call('save');

        $this->assertSame(DefaultEmailTemplates::LOCALE_EN, MerchantMailSettings::current()->refresh()->emailLocale());

        // A locale selects a translation file; an unknown one would ship raw
        // dotted keys to a customer.
        $settings = MerchantMailSettings::current();
        $settings->email_locale = 'klingon';
        $settings->save();

        $this->assertSame(DefaultEmailTemplates::LOCALE_HE, $settings->refresh()->emailLocale());
    }

    public function test_the_default_email_renders_in_the_shops_language_and_direction(): void
    {
        $settings = MerchantMailSettings::current();
        $settings->email_locale = DefaultEmailTemplates::LOCALE_EN;
        $settings->save();

        $preview = EmailPreviewRenderer::preview(self::TEMPLATE, $settings->refresh());

        $this->assertStringContainsString('dir="ltr"', $preview['html']);
        $this->assertStringContainsString('Amount:', $preview['html']);
        // The sample data follows too — a Hebrew name inside English copy is
        // exactly the mismatch the switch exists to reveal.
        $this->assertStringContainsString('Dana Cohen', $preview['html']);
    }

    public function test_hebrew_remains_the_default_for_an_untouched_shop(): void
    {
        $preview = EmailPreviewRenderer::preview(self::TEMPLATE, MerchantMailSettings::current());

        $this->assertStringContainsString('dir="rtl"', $preview['html']);
        $this->assertStringContainsString('סכום:', $preview['html']);
    }

    private function defaultBody(string $template, string $locale): string
    {
        $previous = app()->getLocale();
        app()->setLocale($locale);
        $body = DefaultEmailTemplates::body($template);
        app()->setLocale($previous);

        return $body;
    }
}
