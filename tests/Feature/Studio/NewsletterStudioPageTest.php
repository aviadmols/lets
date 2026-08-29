<?php

namespace Tests\Feature\Studio;

use App\Domain\Billing\BillingPlan;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\DocumentService;
use App\Domain\Campaigns\Studio\Models\EmailCampaignDocumentVersion;
use App\Filament\Pages\NewsletterStudio;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The studio screen. The laws: another shop's campaign never loads, a sent
 * campaign is never editable, every mutation goes through the one write seam
 * and bumps the version, and a screen looking at yesterday's version is
 * refused loudly — never silently overwritten.
 */
final class NewsletterStudioPageTest extends TestCase
{
    use RefreshDatabase;

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        $this->shop = Shop::create([
            'woocommerce_domain' => 'studio-page.example.com',
            'name' => 'Studio Page Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function campaign(string $status = EmailCampaign::STATUS_DRAFT, ?Shop $shop = null): EmailCampaign
    {
        $shop ??= $this->shop;

        $campaign = new EmailCampaign;
        $campaign->forceFill([
            'shop_id' => (int) $shop->getKey(),
            'name' => 'Studio page test',
            'subject' => 'נושא',
            'body_html' => '',
            'editor_mode' => EmailCampaign::EDITOR_STUDIO,
            'audience' => [],
            'status' => $status,
            'is_marketing' => true,
        ])->save();

        app(DocumentService::class)->initFor($campaign);

        return $campaign->refresh();
    }

    public function test_the_page_mounts_with_the_document_and_its_version(): void
    {
        $campaign = $this->campaign();

        Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->assertOk()
            ->assertSet('knownVersion', 1)
            ->assertSee(__('studio.panel.blocks'));
    }

    public function test_another_shops_campaign_never_loads_here(): void
    {
        $other = Shop::create([
            'woocommerce_domain' => 'studio-other.example.com',
            'name' => 'Other',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        $foreign = Tenant::run($other, fn (): EmailCampaign => $this->campaign(shop: $other));

        // The global scope resolves the foreign id to null — the page bounces,
        // it does not load.
        Livewire::test(NewsletterStudio::class, ['campaign' => $foreign->getKey()])
            ->assertRedirect();
    }

    public function test_a_sent_campaign_is_read_only_and_bounces(): void
    {
        $sent = $this->campaign(EmailCampaign::STATUS_SENT);

        Livewire::test(NewsletterStudio::class, ['campaign' => $sent->getKey()])
            ->assertRedirect();
    }

    public function test_a_freeform_campaign_does_not_open_in_the_studio(): void
    {
        $legacy = new EmailCampaign;
        $legacy->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'name' => 'Legacy',
            'subject' => 'x',
            'body_html' => '<p>{unsubscribe_url}</p>',
            'editor_mode' => EmailCampaign::EDITOR_VISUAL,
            'audience' => [],
            'status' => EmailCampaign::STATUS_DRAFT,
            'is_marketing' => true,
        ])->save();

        Livewire::test(NewsletterStudio::class, ['campaign' => $legacy->getKey()])
            ->assertRedirect();
    }

    public function test_block_mutations_move_the_document_through_the_seam(): void
    {
        $campaign = $this->campaign();

        $page = Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->call('addBlock', 'coupon')
            ->assertSet('knownVersion', 2);

        $document = app(DocumentService::class)->documentFor($campaign->refresh());
        $this->assertSame(1, $document->countBlocksOf('coupon'));

        // The new block was auto-selected; its removal deselects and bumps.
        $couponId = collect($document->blocks())->firstWhere('type', 'coupon')['id'];

        $page->call('duplicateBlock', $couponId)
            ->assertSet('knownVersion', 3)
            ->call('removeBlock', $couponId)
            ->assertSet('knownVersion', 4);

        $document = app(DocumentService::class)->documentFor($campaign->refresh());
        $this->assertSame(1, $document->countBlocksOf('coupon'), 'duplicate survived, original removed');
    }

    public function test_move_clamps_and_reorders(): void
    {
        $campaign = $this->campaign();
        $document = app(DocumentService::class)->documentFor($campaign);
        $firstId = $document->blocks()[0]['id'];

        Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->call('moveBlock', $firstId, 999); // clamped to the end, no error

        $document = app(DocumentService::class)->documentFor($campaign->refresh());
        $blocks = $document->blocks();
        $this->assertSame($firstId, $blocks[count($blocks) - 1]['id']);
    }

    public function test_saving_a_block_re_guards_its_content(): void
    {
        $campaign = $this->campaign();
        $document = app(DocumentService::class)->documentFor($campaign);
        $textId = collect($document->blocks())->firstWhere('type', 'text')['id'];

        Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->call('selectBlock', $textId)
            ->set('blockContent.html', '<p onclick="x()">נקי<script>alert(1)</script></p>')
            ->call('saveBlock')
            // The panel re-fills with what was ACTUALLY stored — cleaned.
            ->assertSet('blockContent.html', '<p>נקי</p>');

        $stored = app(DocumentService::class)->documentFor($campaign->refresh())->findBlock($textId);
        $this->assertSame('<p>נקי</p>', $stored['content']['html']);
    }

    public function test_a_stale_screen_is_refused_loudly_not_overwritten(): void
    {
        $campaign = $this->campaign();

        $page = Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()]);

        // Another tab moves the document underneath this screen.
        $service = app(DocumentService::class);
        $service->save(
            $campaign->refresh(),
            $service->documentFor($campaign)->withPreheader('מהטאב השני'),
            EmailCampaignDocumentVersion::CAUSE_MANUAL,
        );

        $page->call('addBlock', 'coupon');

        // The stale mutation did NOT land; the screen refreshed to the truth.
        $document = $service->documentFor($campaign->refresh());
        $this->assertSame(0, $document->countBlocksOf('coupon'));
        $this->assertSame('מהטאב השני', $document->preheader());
        $page->assertSet('knownVersion', 2);
    }

    public function test_settings_save_subject_on_the_column_and_the_rest_on_the_document(): void
    {
        $campaign = $this->campaign();

        Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->set('subject', 'נושא חדש לגמרי')
            ->set('preheader', 'שורת תצוגה')
            ->set('globals.direction', 'ltr')
            ->call('saveSettings');

        $campaign->refresh();
        $this->assertSame('נושא חדש לגמרי', $campaign->subject);

        $document = app(DocumentService::class)->documentFor($campaign);
        $this->assertSame('שורת תצוגה', $document->preheader());
        $this->assertSame('ltr', $document->globals()['direction']);
    }

    public function test_restore_from_the_versions_drawer(): void
    {
        $campaign = $this->campaign();

        $page = Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->call('addBlock', 'coupon')
            ->call('restoreVersion', 1)
            ->assertSet('knownVersion', 3);

        $document = app(DocumentService::class)->documentFor($campaign->refresh());
        $this->assertSame(0, $document->countBlocksOf('coupon'));
    }

    public function test_the_preview_substitutes_sample_vars_never_credentials(): void
    {
        $campaign = $this->campaign();

        $html = Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->instance()
            ->previewHtml();

        // The starter's {account_login_url} resolved to the SAMPLE url; no raw
        // token left behind, no live credential minted.
        $this->assertStringNotContainsString('{account_login_url}', $html);
        $this->assertStringContainsString('sample', $html);
    }

    public function test_the_feature_gate_key_exists_on_the_plan(): void
    {
        $this->assertContains(BillingPlan::FEATURE_AI_NEWSLETTER, BillingPlan::FEATURE_KEYS);
        $this->assertTrue(BillingPlan::FREE->limits()[BillingPlan::FEATURE_AI_NEWSLETTER]);
    }
}
