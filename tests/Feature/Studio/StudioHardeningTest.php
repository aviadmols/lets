<?php

namespace Tests\Feature\Studio;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Domain\Campaigns\Studio\DocumentService;
use App\Domain\Campaigns\Studio\Models\AiChatMessage;
use App\Filament\Pages\NewsletterStudio;
use App\Mail\CampaignMail;
use App\Models\PlatformAiSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The hardening pass: the subject quick action routes to its OWN stage (the
 * button is the intent — never a phrasing guess), and a studio campaign's
 * compiled plain-text twin actually rides the mail while legacy campaigns
 * stay HTML-only exactly as before.
 */
final class StudioHardeningTest extends TestCase
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
            'woocommerce_domain' => 'hardening.example.com',
            'name' => 'Hardening Co',
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
        Tenant::clear();
        parent::tearDown();
    }

    private function campaign(): EmailCampaign
    {
        $campaign = new EmailCampaign;
        $campaign->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'name' => 'Hardening test',
            'subject' => 'נושא',
            'body_html' => '',
            'editor_mode' => EmailCampaign::EDITOR_STUDIO,
            'audience' => [],
            'status' => EmailCampaign::STATUS_DRAFT,
            'is_marketing' => true,
        ])->save();

        app(DocumentService::class)->initFor($campaign);

        return $campaign->refresh();
    }

    public function test_the_subject_quick_action_routes_to_the_subject_writer_stage(): void
    {
        Http::fake([
            self::API.'/v1/messages' => Http::response([
                'content' => [['type' => 'tool_use', 'name' => 'propose_newsletter_patch', 'input' => [
                    'explanation' => 'הצעה',
                    'ops' => [['op' => 'set_subject', 'payload' => ['text' => 'נושא חדש'], 'reason' => '', 'confidence' => 1]],
                ]]],
                'usage' => ['input_tokens' => 100, 'output_tokens' => 30],
            ], 200),
        ]);

        Livewire::test(NewsletterStudio::class, ['campaign' => $this->campaign()->getKey()])
            ->call('suggestSubject');

        // The stamp is on the row…
        $assistant = AiChatMessage::query()
            ->where('role', AiChatMessage::ROLE_ASSISTANT)
            ->latest('id')->first();
        $this->assertSame('subject_writer', $assistant->stage_hint);
        $this->assertSame(AiChatMessage::STATUS_PROPOSED, $assistant->status());

        // …and the call really ran under that stage's OWN system prompt.
        Http::assertSent(function ($request): bool {
            $data = (array) json_decode($request->body(), true);

            return ($data['system'] ?? null) === (string) config('ai.stages.subject_writer.system');
        });
    }

    public function test_a_studio_campaign_mails_its_plain_text_twin(): void
    {
        $mail = new CampaignMail(
            shop: $this->shop,
            subjectTemplate: 'שלום {first_name}',
            bodyTemplate: '<p>שלום {first_name}</p>',
            vars: ['first_name' => 'דנה'],
            unsubscribeUrl: 'https://x.example.com/u',
            shopperLocale: 'he',
            isMarketing: true,
            textTemplate: "שלום {first_name}\nהסרה: {unsubscribe_url}",
        );

        $content = $mail->content();

        $this->assertSame('emails.user-template-text', $content->text);
        $this->assertSame("שלום דנה\nהסרה: {unsubscribe_url}", $content->with['renderedText']);
    }

    public function test_a_legacy_campaign_stays_html_only(): void
    {
        $mail = new CampaignMail(
            shop: $this->shop,
            subjectTemplate: 'נושא',
            bodyTemplate: '<p>גוף</p>',
            vars: [],
            unsubscribeUrl: 'https://x.example.com/u',
            shopperLocale: 'he',
            isMarketing: false,
        );

        $this->assertNull($mail->content()->text);
    }
}
