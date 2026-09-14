<?php

namespace Tests\Feature\Mail;

use App\Domain\Lifecycle\SubscriptionLifecycleService;
use App\Domain\Mail\MailPolicy;
use App\Filament\Pages\ManageMailSettings;
use App\Mail\PlanCancelledMail;
use App\Models\InstallmentPlan;
use App\Models\MerchantMailSettings;
use App\Models\Shop;
use App\Models\User;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * WHICH EMAILS GO OUT — a master tap and a per-email list.
 *
 * Two levels, and the tests are mostly about the boundaries of the master one,
 * because that is the switch a merchant reaches for in a hurry and the one whose
 * blast radius has to be exactly what the screen promised:
 *
 *   - it stops everything this app originates, campaigns included;
 *   - it does NOT stop the accounting document's own email, which the invoicing
 *     provider sends on its own setting — a tax receipt is not a notification and
 *     must not be switchable off by a screen about welcome emails;
 *   - subscriptions keep running and cards keep being charged. Only the messages
 *     stop.
 *
 * The convention test at the bottom is the one that matters in a year: it walks
 * every mail-sending file in the app and asserts each consults the policy, so the
 * NEXT email somebody adds cannot quietly ignore the switch.
 */
final class EmailSwitchesTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'switches.example.com',
            'name' => 'Switches',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        Tenant::set($this->shop);
        Mail::fake();
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The model: one authority per email ===

    /** A shop that never opened the screen sends exactly what it sent before. */
    public function test_the_default_is_to_send_everything(): void
    {
        $settings = MerchantMailSettings::current();

        $this->assertTrue($settings->emailsEnabled());

        foreach (MerchantMailSettings::TEMPLATES as $template) {
            $this->assertTrue($settings->sendsTemplate($template), $template.' should send by default');
        }
    }

    public function test_the_per_email_list_silences_only_what_was_unticked(): void
    {
        $settings = MerchantMailSettings::current();
        $settings->forceFill([
            'disabled_templates' => [MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED],
        ])->save();

        $this->assertFalse($settings->sendsTemplate(MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED));
        $this->assertTrue($settings->sendsTemplate(MerchantMailSettings::TEMPLATE_CHARGE_FAILED));
    }

    /** The master tap covers everything, including what has no row on the list. */
    public function test_the_master_tap_stops_every_email(): void
    {
        $settings = MerchantMailSettings::current();
        $settings->forceFill(['emails_enabled' => false])->save();

        foreach (MerchantMailSettings::TEMPLATES as $template) {
            $this->assertFalse($settings->sendsTemplate($template), $template.' should be stopped');
        }

        // And a campaign, which is not a template at all.
        $this->assertFalse($settings->sendsTemplate(MerchantMailSettings::CHANNEL_CAMPAIGN));
    }

    /**
     * The reminder answers to its OWN column, not the list.
     *
     * One email with two switches is how two screens come to disagree about it —
     * and the scheduler's cheap pre-filter reads `reminder_enabled` directly.
     */
    public function test_the_reminder_is_governed_by_its_own_column(): void
    {
        $settings = MerchantMailSettings::current();

        $this->assertNotContains(
            MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER,
            MerchantMailSettings::SWITCHABLE_TEMPLATES,
            'the reminder has a section of its own; it must not also be on the list',
        );

        $settings->forceFill(['reminder_enabled' => false])->save();
        $this->assertFalse($settings->sendsTemplate(MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER));

        $settings->forceFill(['reminder_enabled' => true])->save();
        $this->assertTrue($settings->sendsTemplate(MerchantMailSettings::TEMPLATE_RECURRING_PAYMENT_REMINDER));
    }

    /**
     * A template added next release arrives ON, not silently muted.
     *
     * The column stores what is DISABLED for exactly this reason: a shop that
     * saved this screen months ago must not have tomorrow's email switched off by
     * a list that predates it.
     */
    public function test_an_unknown_template_passes_the_list(): void
    {
        $settings = MerchantMailSettings::current();
        $settings->forceFill(['disabled_templates' => ['a_template_from_next_release']])->save();

        $this->assertTrue($settings->sendsTemplate('a_template_from_next_release'));
        // …and the stale key is not echoed back as if it were real.
        $this->assertSame([], $settings->disabledTemplates());
    }

    // === The policy ===

    public function test_the_policy_says_which_switch_refused(): void
    {
        $policy = app(MailPolicy::class);
        $settings = MerchantMailSettings::current();

        $settings->forceFill([
            'disabled_templates' => [MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED],
        ])->save();

        $this->assertSame(
            MailPolicy::REASON_TEMPLATE_OFF,
            $policy->reason($this->shop, MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED),
        );

        $settings->forceFill(['emails_enabled' => false])->save();

        $this->assertSame(
            MailPolicy::REASON_ALL_OFF,
            $policy->reason($this->shop, MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED),
            'the master tap is reported as the master tap, not as the row',
        );
    }

    /** Per shop. One merchant's silence cannot reach another's customers. */
    public function test_the_switch_is_per_shop(): void
    {
        MerchantMailSettings::current()->forceFill(['emails_enabled' => false])->save();

        $other = Shop::create([
            'woocommerce_domain' => 'other-switches.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $policy = app(MailPolicy::class);

        $this->assertFalse($policy->allows($this->shop, MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED));
        $this->assertTrue($policy->allows($other, MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED));
    }

    /**
     * The shop comes from the ARGUMENT, never the bound tenant.
     *
     * Most callers are queued jobs; a resolver reading global state would be one
     * refactor away from judging shop A's email by shop B's settings.
     */
    public function test_the_policy_reads_the_shop_it_was_given(): void
    {
        MerchantMailSettings::current()->forceFill(['emails_enabled' => false])->save();

        $other = Shop::create([
            'woocommerce_domain' => 'bound-elsewhere.example.com',
            'name' => 'Bound',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        // Bound to the OTHER shop while asking about ours.
        $allowed = Tenant::run($other, fn (): bool => app(MailPolicy::class)
            ->allows($this->shop, MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED));

        $this->assertFalse($allowed);
    }

    /** The platform's own relay test is not a merchant's mail. */
    public function test_a_null_shop_is_never_blocked(): void
    {
        $this->assertTrue(app(MailPolicy::class)->allows(null, MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED));
    }

    // === The send sites ===

    /** The cancellation still happens; only the notice is withheld. */
    public function test_a_cancellation_with_email_off_still_cancels(): void
    {
        MerchantMailSettings::current()->forceFill(['emails_enabled' => false])->save();

        $plan = $this->plan();
        app(SubscriptionLifecycleService::class)->cancel($plan, 'merchant asked');

        $this->assertSame(PlanStatus::CANCELLED, $plan->fresh()->status);
        Mail::assertNothingSent();
    }

    public function test_a_cancellation_notice_is_sent_when_the_switch_is_on(): void
    {
        $plan = $this->plan();
        app(SubscriptionLifecycleService::class)->cancel($plan, 'merchant asked');

        Mail::assertSent(PlanCancelledMail::class);
    }

    /** Its own row on the list, so it can be silenced without silencing the rest. */
    public function test_unticking_the_cancellation_row_silences_only_it(): void
    {
        MerchantMailSettings::current()->forceFill([
            'disabled_templates' => [MerchantMailSettings::TEMPLATE_PLAN_CANCELLED],
        ])->save();

        app(SubscriptionLifecycleService::class)->cancel($this->plan(), null);

        Mail::assertNothingSent();
    }

    /**
     * The sign-in code is covered by the MASTER tap — which is the one
     * consequence the screen warns about in words.
     */
    public function test_the_master_tap_stops_the_sign_in_code(): void
    {
        MerchantMailSettings::current()->forceFill(['emails_enabled' => false])->save();

        $this->assertFalse(
            app(MailPolicy::class)->allows($this->shop, MerchantMailSettings::TEMPLATE_LOGIN_CODE),
        );

        // And it has no row on the per-email list, because Settings → Customer
        // area already owns whether code sign-in exists at all.
        $this->assertNotContains(
            MerchantMailSettings::TEMPLATE_LOGIN_CODE,
            MerchantMailSettings::SWITCHABLE_TEMPLATES,
        );
    }

    // === The screen ===

    public function test_the_screen_saves_the_master_tap_and_stamps_when_it_closed(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        Livewire::test(ManageMailSettings::class)
            ->assertSet('data.emails_enabled', true)
            ->set('data.emails_enabled', false)
            ->call('save')
            ->assertHasNoErrors();

        $saved = MerchantMailSettings::current()->fresh();

        $this->assertFalse($saved->emailsEnabled());
        // "Since when has nobody been emailed?" has to be answerable.
        $this->assertNotNull($saved->emails_paused_at);
    }

    /**
     * The list is presented as what IS sent and stored as its inverse. Unticking
     * one row must leave every other row alone.
     */
    public function test_the_screen_stores_the_list_as_its_inverse(): void
    {
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        $keep = array_values(array_diff(
            MerchantMailSettings::SWITCHABLE_TEMPLATES,
            [MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED],
        ));

        Livewire::test(ManageMailSettings::class)
            ->assertSet('data.enabled_templates', MerchantMailSettings::SWITCHABLE_TEMPLATES)
            ->set('data.enabled_templates', $keep)
            ->call('save');

        $saved = MerchantMailSettings::current()->fresh();

        $this->assertSame([MerchantMailSettings::TEMPLATE_CHARGE_SUCCEEDED], $saved->disabledTemplates());
        $this->assertEqualsCanonicalizing($keep, $saved->enabledTemplates());
    }

    // === The convention ===

    /**
     * EVERY mail-sending file in the app consults the policy.
     *
     * The test that matters in a year. A switch is only as good as the number of
     * places that read it, and the next person adding an email will copy the file
     * next to theirs — so the wall is here, not in a code review. A new send site
     * either asks MailPolicy or appears in this test's failure message.
     *
     * The two deliberate exemptions are listed, each with its reason, so adding a
     * third is a decision somebody has to write down.
     */
    public function test_every_send_site_consults_the_policy(): void
    {
        /**
         * file => why it may send without asking.
         *
         * @var array<string, string>
         */
        $exempt = [
            // The PLATFORM's own relay diagnostic, sent by a platform admin to
            // themselves. Not a merchant's mail, and a merchant's switch must not
            // be able to break the operator's ability to test a relay.
            'app/Filament/Pages/ManagePlatformMail.php' => 'platform relay test',
            // The merchant emailing THEMSELVES a proof of their own campaign. It
            // goes to the address they just typed, and refusing it would leave
            // them unable to see their own draft. The campaign SEND is gated.
            'app/Filament/Resources/CampaignResource/Pages/EditCampaign.php' => 'merchant self-test (send is gated)',
        ];

        $senders = [];

        foreach ($this->phpFilesUnder(base_path('app')) as $path) {
            $source = (string) file_get_contents($path);
            $relative = str_replace('\\', '/', substr($path, strlen(base_path()) + 1));

            // A file that SENDS: it hands a mailable to a mailer.
            $sends = str_contains($source, '->send(new ')
                || str_contains($source, 'CampaignMailer::for(')
                || str_contains($source, 'Mail::to(');

            // The mailables themselves are the MESSAGE, not the decision to send.
            if (! $sends || str_starts_with($relative, 'app/Mail/')) {
                continue;
            }

            $senders[$relative] = $source;
        }

        $this->assertNotEmpty($senders, 'the scan found no send sites at all — it has stopped working');

        $ungated = [];

        foreach ($senders as $relative => $source) {
            if (isset($exempt[$relative])) {
                continue;
            }

            // Either it asks the policy, or it asks the settings row directly
            // (the reminder path, which reads sendsTemplate() for its own column).
            if (str_contains($source, 'MailPolicy') || str_contains($source, 'sendsTemplate(')) {
                continue;
            }

            $ungated[] = $relative;
        }

        $this->assertSame(
            [],
            $ungated,
            "these files send email without consulting MailPolicy:\n  ".implode("\n  ", $ungated)
                ."\nAdd the gate, or add an exemption with its reason to this test.",
        );
    }

    /**
     * The accounting document's email is NOT ours to switch off.
     *
     * It is sent by the invoicing provider as part of issuing the document, on the
     * merchant's separate invoicing setting. Pinned as a grep because the failure
     * mode is somebody "tidying up" by routing it through this app's mailer, at
     * which point a merchant silencing their welcome email silences their
     * customers' tax receipts too.
     */
    public function test_the_invoicing_provider_still_emails_the_document(): void
    {
        $issuer = (string) file_get_contents(base_path('app/Domain/Invoicing/DocumentIssuer.php'));
        $provider = (string) file_get_contents(base_path('app/Domain/Invoicing/GreenInvoice/GreenInvoiceProvider.php'));

        // The merchant's INVOICING setting decides it…
        $this->assertStringContainsString('sendEmail: $settings->sendsEmailToCustomer()', $issuer);
        // …and the provider is the one that sends it, by putting the address on
        // the document request. Nothing here touches our mailer.
        $this->assertStringContainsString("\$block['emails'] = [\$customer->email];", $provider);

        $this->assertStringNotContainsString('MailPolicy', $issuer, 'the document email is not ours to gate');
    }

    // === Fixtures ===

    /** @return list<string> */
    private function phpFilesUnder(string $directory): array
    {
        $files = [];

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS)
        );

        foreach ($iterator as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        return $files;
    }

    private function plan(): InstallmentPlan
    {
        $plan = new InstallmentPlan;
        $plan->forceFill([
            'shop_id' => $this->shop->getKey(),
            'public_id' => 'PLN-switches-'.uniqid(),
            'customer_name' => 'Dana Levi',
            'customer_email' => 'dana@example.com',
            'plan_kind' => PlanKind::RECURRING->value,
            'status' => PlanStatus::ACTIVE->value,
            'total_amount' => 0,
            'total_charged' => 0,
            'installment_amount' => 39,
            'currency' => 'ILS',
            'billing_frequency' => 'monthly',
            'interval_count' => 1,
            'next_charge_at' => now()->addDays(10),
        ])->save();

        return $plan;
    }
}
