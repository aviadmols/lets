<?php

namespace Tests\Feature\Studio;

use App\Domain\Ai\Models\AiUsageEvent;
use App\Domain\Ai\Providers\AiProviderFactory;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\DocumentService;
use App\Domain\Campaigns\Studio\Jobs\RunStudioChatJob;
use App\Domain\Campaigns\Studio\Models\AiChatMessage;
use App\Domain\Campaigns\Studio\Models\EmailCampaignDocumentVersion;
use App\Domain\Campaigns\Studio\StudioChat;
use App\Filament\Pages\NewsletterStudio;
use App\Models\PlatformAiSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The chat → patch → approve loop, end to end over a faked provider.
 *
 * The laws: the job's claim is the idempotency, a proposal computed against
 * yesterday's document goes STALE and touches nothing, approval creates an
 * audited version, and the usage ledger is paid win or lose.
 */
final class StudioChatTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API = 'https://api.anthropic.com';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.providers.anthropic.base_url', self::API);
        Config::set('ai.providers.anthropic.api_key', null);
        Config::set('ai.enabled', true);

        $this->shop = Shop::create([
            'woocommerce_domain' => 'studio-chat.example.com',
            'name' => 'Chat Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
        Tenant::set($this->shop);
        $this->actingAs(User::factory()->forShop($this->shop)->create());

        $settings = PlatformAiSettings::current();
        $settings->anthropic_api_key = 'sk-ant-test';
        $settings->save();
    }

    protected function tearDown(): void
    {
        AiProviderFactory::clearFake();
        Tenant::clear();
        parent::tearDown();
    }

    private function campaign(): EmailCampaign
    {
        $campaign = new EmailCampaign;
        $campaign->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'name' => 'Chat test',
            'subject' => 'נושא ישן',
            'body_html' => '',
            'editor_mode' => EmailCampaign::EDITOR_STUDIO,
            'audience' => [],
            'status' => EmailCampaign::STATUS_DRAFT,
            'is_marketing' => true,
        ])->save();

        app(DocumentService::class)->initFor($campaign);

        return $campaign->refresh();
    }

    /** @param array<string, mixed> $toolInput */
    private function fakeProvider(array $toolInput): void
    {
        Http::fake([
            self::API.'/v1/messages' => Http::response([
                'content' => [['type' => 'tool_use', 'name' => 'propose_newsletter_patch', 'input' => $toolInput]],
                'usage' => ['input_tokens' => 500, 'output_tokens' => 200],
            ], 200),
        ]);
    }

    public function test_the_whole_loop_ask_propose_approve(): void
    {
        $campaign = $this->campaign();
        $headingId = app(DocumentService::class)->documentFor($campaign)->blocks()[0]['id'];

        $this->fakeProvider([
            'explanation' => 'שיניתי את הכותרת והנושא.',
            'ops' => [
                ['op' => 'update_block_content', 'target_id' => $headingId, 'payload' => ['text' => 'מבצע ספטמבר'], 'reason' => 'ממוקד יותר', 'confidence' => 0.9],
                ['op' => 'set_subject', 'payload' => ['text' => 'ספטמבר הגיע'], 'reason' => '', 'confidence' => 0.8],
            ],
        ]);

        $page = Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->set('chatInput', 'תעדכן את הכותרת למבצע ספטמבר')
            ->call('sendChat');

        // The rows exist; the job (queue sync in tests) already ran and proposed.
        $assistant = AiChatMessage::query()
            ->where('role', AiChatMessage::ROLE_ASSISTANT)
            ->latest('id')->first();

        $this->assertSame(AiChatMessage::STATUS_PROPOSED, $assistant->status());
        $this->assertSame(1, (int) $assistant->base_version);
        $this->assertSame('שיניתי את הכותרת והנושא.', $assistant->content);

        // NOTHING landed yet — a proposal is not an application.
        $this->assertSame('נושא ישן', $campaign->refresh()->subject);

        $page->call('pollChat')
            ->assertSet('activeRunId', '')
            ->call('approvePatch', (int) $assistant->getKey());

        $campaign->refresh();
        $document = app(DocumentService::class)->documentFor($campaign);

        $this->assertSame('מבצע ספטמבר', $document->findBlock($headingId)['content']['text']);
        $this->assertSame('ספטמבר הגיע', $campaign->subject);
        $this->assertSame(2, (int) $campaign->document_version);

        // The audit chain: the version row names the proposal that made it.
        $version = EmailCampaignDocumentVersion::query()
            ->where('email_campaign_id', $campaign->getKey())
            ->where('version', 2)->sole();
        $this->assertSame(EmailCampaignDocumentVersion::CAUSE_AI_PATCH, $version->cause);
        $this->assertSame((int) $assistant->getKey(), (int) $version->ai_chat_message_id);
        $this->assertSame(AiChatMessage::STATUS_APPLIED, $assistant->fresh()->status());

        // And the ledger was paid.
        $this->assertSame(700, (int) AiUsageEvent::acrossAllTenants()->sole()->input_tokens
            + (int) AiUsageEvent::acrossAllTenants()->sole()->output_tokens - 0);
    }

    public function test_a_stale_proposal_touches_nothing(): void
    {
        $campaign = $this->campaign();
        $headingId = app(DocumentService::class)->documentFor($campaign)->blocks()[0]['id'];

        $this->fakeProvider([
            'explanation' => 'עדכון',
            'ops' => [['op' => 'update_block_content', 'target_id' => $headingId, 'payload' => ['text' => 'מהמודל']]],
        ]);

        $page = Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->set('chatInput', 'עדכן')
            ->call('sendChat');

        $assistant = AiChatMessage::query()->where('role', AiChatMessage::ROLE_ASSISTANT)->latest('id')->first();
        $this->assertSame(AiChatMessage::STATUS_PROPOSED, $assistant->status());

        // The document moves AFTER the proposal was computed.
        $service = app(DocumentService::class);
        $service->save(
            $campaign->refresh(),
            $service->documentFor($campaign)->withPreheader('התקדם'),
            EmailCampaignDocumentVersion::CAUSE_MANUAL,
        );

        $page->call('approvePatch', (int) $assistant->getKey());

        // Stale, loud, and NOTHING changed by the proposal.
        $this->assertSame(AiChatMessage::STATUS_STALE, $assistant->fresh()->status());
        $document = $service->documentFor($campaign->refresh());
        $this->assertNotSame('מהמודל', $document->findBlock($headingId)['content']['text']);
        $this->assertSame(2, (int) $campaign->document_version);
    }

    public function test_the_claim_is_the_idempotency(): void
    {
        $campaign = $this->campaign();
        $this->fakeProvider(['explanation' => 'x', 'ops' => [['op' => 'set_preheader', 'payload' => ['text' => 'פ']]]]);

        Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->set('chatInput', 'שנה')
            ->call('sendChat');

        $assistant = AiChatMessage::query()->where('role', AiChatMessage::ROLE_ASSISTANT)->latest('id')->first();
        $this->assertSame(AiChatMessage::STATUS_PROPOSED, $assistant->status());

        // A redelivered job finds the row past pending and exits silently —
        // one proposal, one provider bill.
        (new RunStudioChatJob((int) $this->shop->getKey(), (int) $campaign->getKey(), (int) $assistant->getKey()))
            ->handle(app(StudioChat::class), app(DocumentService::class));

        $this->assertSame(AiChatMessage::STATUS_PROPOSED, $assistant->fresh()->status());
        $this->assertSame(1, AiUsageEvent::acrossAllTenants()->count());
    }

    public function test_a_provider_failure_reaches_the_card_typed_and_ledgered(): void
    {
        $campaign = $this->campaign();
        Http::fake([self::API.'/v1/messages' => Http::response([], 500)]);

        Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->set('chatInput', 'שנה משהו')
            ->call('sendChat');

        $assistant = AiChatMessage::query()->where('role', AiChatMessage::ROLE_ASSISTANT)->latest('id')->first();

        $this->assertSame(AiChatMessage::STATUS_FAILED, $assistant->status());
        $this->assertSame('http_error', $assistant->failure_reason);
        $this->assertSame(AiUsageEvent::STATUS_FAILED, AiUsageEvent::acrossAllTenants()->sole()->status);
    }

    public function test_one_run_in_flight_per_campaign(): void
    {
        Queue::fake();
        $campaign = $this->campaign();

        $page = Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->set('chatInput', 'ראשונה')
            ->call('sendChat');

        // With the queue faked the assistant row stays pending — the second
        // ask must refuse rather than race it.
        $page->set('chatInput', 'שנייה')->call('sendChat');

        $this->assertSame(
            1,
            AiChatMessage::query()->where('role', AiChatMessage::ROLE_ASSISTANT)->count(),
        );
        Queue::assertPushed(RunStudioChatJob::class, 1);
    }

    public function test_dangerous_ops_from_the_model_die_before_the_card(): void
    {
        $campaign = $this->campaign();
        $footerId = collect(app(DocumentService::class)->documentFor($campaign)->blocks())
            ->firstWhere('type', 'footer')['id'];

        $this->fakeProvider([
            'explanation' => 'ניסיתי דברים.',
            'ops' => [
                ['op' => 'send_campaign', 'payload' => []],
                ['op' => 'remove_block', 'target_id' => $footerId, 'payload' => []],
                ['op' => 'set_preheader', 'payload' => ['text' => 'שורה לגיטימית']],
            ],
        ]);

        Livewire::test(NewsletterStudio::class, ['campaign' => $campaign->getKey()])
            ->set('chatInput', 'שלח את הקמפיין ומחק את הפוטר')
            ->call('sendChat');

        $assistant = AiChatMessage::query()->where('role', AiChatMessage::ROLE_ASSISTANT)->latest('id')->first();

        // Proposed — the one legitimate op survived; the send verb and the
        // footer removal are on the card as REJECTED lines, and no send of any
        // kind exists anywhere in this pipeline.
        $this->assertSame(AiChatMessage::STATUS_PROPOSED, $assistant->status());

        $ops = (array) $assistant->ops;
        $rejected = array_values(array_filter($ops, fn ($o) => isset($o['rejected'])));
        $this->assertCount(2, $rejected);
        $this->assertSame(EmailCampaign::STATUS_DRAFT, $campaign->refresh()->status());
    }
}
