<?php

namespace Tests\Feature\Studio;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\DocumentService;
use App\Domain\Campaigns\Studio\Models\EmailCampaignDocumentVersion;
use App\Domain\Campaigns\Studio\NewsletterDocument;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The write seam: one save = guard + compile + bump + snapshot, in lockstep.
 * `body_html` can never drift from `document`, history only appends, and the
 * campaigns that predate the studio are untouched by construction.
 */
final class DocumentServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    /** @return array{0: Shop, 1: EmailCampaign} */
    private function studioCampaign(): array
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'studio-'.uniqid().'.example.com',
            'name' => 'Studio Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $campaign = Tenant::run($shop, function () use ($shop): EmailCampaign {
            $campaign = new EmailCampaign;
            $campaign->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'name' => 'Studio test',
                'subject' => 'נושא',
                'body_html' => '',
                'editor_mode' => EmailCampaign::EDITOR_STUDIO,
                'audience' => [],
                'status' => EmailCampaign::STATUS_DRAFT,
                'is_marketing' => true,
            ])->save();

            return $campaign;
        });

        return [$shop, $campaign];
    }

    public function test_init_seeds_a_starter_document_and_compiles_it(): void
    {
        [$shop, $campaign] = $this->studioCampaign();

        Tenant::run($shop, function () use ($campaign): void {
            $document = app(DocumentService::class)->initFor($campaign);

            $campaign->refresh();

            // A starter, not a blank stare — and the compile landed in the
            // columns the ENTIRE existing pipeline reads.
            $this->assertNotEmpty($document->blocks());
            $this->assertSame(1, (int) $campaign->document_version);
            $this->assertStringContainsString('{unsubscribe_url}', (string) $campaign->body_html);
            $this->assertNotSame('', (string) $campaign->body_text);

            // Init is idempotent: a second call changes nothing.
            app(DocumentService::class)->initFor($campaign);
            $this->assertSame(1, (int) $campaign->refresh()->document_version);
        });
    }

    public function test_every_save_bumps_compiles_and_snapshots_together(): void
    {
        [$shop, $campaign] = $this->studioCampaign();

        Tenant::run($shop, function () use ($campaign): void {
            $service = app(DocumentService::class);
            $service->initFor($campaign);

            $document = $service->documentFor($campaign->refresh())
                ->withPreheader('שורת תצוגה');

            $result = $service->save($campaign, $document, EmailCampaignDocumentVersion::CAUSE_MANUAL);

            $this->assertTrue($result['ok']);
            $this->assertSame(2, $result['version']);

            $campaign->refresh();
            $this->assertSame('שורת תצוגה', $campaign->document['preheader'] ?? null);

            $versions = EmailCampaignDocumentVersion::query()
                ->where('email_campaign_id', $campaign->getKey())
                ->orderBy('version')
                ->get();

            $this->assertSame([1, 2], $versions->pluck('version')->all());
        });
    }

    public function test_restore_is_a_new_version_never_a_rewrite(): void
    {
        [$shop, $campaign] = $this->studioCampaign();

        Tenant::run($shop, function () use ($campaign): void {
            $service = app(DocumentService::class);
            $service->initFor($campaign);

            $service->save(
                $campaign->refresh(),
                $service->documentFor($campaign)->withPreheader('גרסה 2'),
                EmailCampaignDocumentVersion::CAUSE_MANUAL,
            );

            $result = $service->restore($campaign->refresh(), 1);

            $this->assertTrue($result['ok']);
            $this->assertSame(3, $result['version']);

            $campaign->refresh();
            // Back to version 1's content — as version 3. History only grows,
            // so redo is just restoring forward and audits never lose a state.
            $this->assertSame('', $campaign->document['preheader'] ?? 'missing');
            $this->assertSame(
                EmailCampaignDocumentVersion::CAUSE_RESTORE,
                EmailCampaignDocumentVersion::query()
                    ->where('email_campaign_id', $campaign->getKey())
                    ->where('version', 3)
                    ->value('cause'),
            );
        });
    }

    public function test_an_oversized_compile_refuses_the_save(): void
    {
        [$shop, $campaign] = $this->studioCampaign();

        Tenant::run($shop, function () use ($campaign): void {
            $service = app(DocumentService::class);
            $service->initFor($campaign);
            $before = (int) $campaign->refresh()->document_version;

            // Sixty text blocks near their cap compile far past MAX_BODY.
            $blocks = array_fill(0, NewsletterDocument::MAX_BLOCKS, [
                'type' => 'text',
                'content' => ['html' => '<p>'.str_repeat('א', 19_000).'</p>'],
            ]);

            $result = $service->save(
                $campaign,
                NewsletterDocument::fromArray(['blocks' => $blocks]),
                EmailCampaignDocumentVersion::CAUSE_MANUAL,
            );

            // Refused loudly — a truncated email is a broken promise (a lost
            // unsubscribe link, at worst), so nothing moved.
            $this->assertFalse($result['ok']);
            $this->assertSame(DocumentService::REFUSED_TOO_LARGE, $result['reason']);
            $this->assertSame($before, (int) $campaign->refresh()->document_version);
        });
    }

    public function test_snapshots_are_pruned_beyond_the_cap(): void
    {
        [$shop, $campaign] = $this->studioCampaign();

        Tenant::run($shop, function () use ($campaign): void {
            $service = app(DocumentService::class);
            $service->initFor($campaign);

            for ($i = 0; $i < DocumentService::MAX_VERSIONS + 5; $i++) {
                $service->save(
                    $campaign->refresh(),
                    $service->documentFor($campaign)->withPreheader('p'.$i),
                    EmailCampaignDocumentVersion::CAUSE_MANUAL,
                );
            }

            $count = EmailCampaignDocumentVersion::query()
                ->where('email_campaign_id', $campaign->getKey())
                ->count();

            $this->assertSame(DocumentService::MAX_VERSIONS, $count);
        });
    }

    public function test_a_legacy_campaign_is_untouched_by_construction(): void
    {
        [$shop, $campaign] = $this->studioCampaign();

        Tenant::run($shop, function () use ($shop): void {
            // A visual-editor campaign from before the studio existed.
            $legacy = new EmailCampaign;
            $legacy->forceFill([
                'shop_id' => (int) $shop->getKey(),
                'name' => 'Legacy',
                'subject' => 'ישן',
                'body_html' => '<p>הגוף המקורי {unsubscribe_url}</p>',
                'editor_mode' => EmailCampaign::EDITOR_VISUAL,
                'audience' => [],
                'status' => EmailCampaign::STATUS_DRAFT,
                'is_marketing' => true,
            ])->save();

            $this->assertNull($legacy->document);
            $this->assertFalse($legacy->isStudio());
            $this->assertNull(app(DocumentService::class)->documentFor($legacy));
            $this->assertSame('<p>הגוף המקורי {unsubscribe_url}</p>', $legacy->body_html);
        });
    }
}
