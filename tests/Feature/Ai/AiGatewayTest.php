<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\AiGateway;
use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;
use App\Domain\Ai\Models\AiUsageEvent;
use App\Domain\Ai\PromptRepository;
use App\Models\PlatformAiSettings;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The one door to a model. The laws: walls before the provider (kill switch,
 * key, TODAY'S budget — an over-budget platform spends zero more), and the
 * ledger records every call WIN OR LOSE — an invisible failure is how outages
 * hide and budgets leak.
 */
final class AiGatewayTest extends TestCase
{
    use RefreshDatabase;

    // === CONSTANTS ===
    private const API = 'https://api.anthropic.com';

    private Shop $shop;

    protected function setUp(): void
    {
        parent::setUp();

        Config::set('ai.providers.anthropic.api_key', null);
        Config::set('ai.providers.anthropic.base_url', self::API);
        Config::set('ai.enabled', true);

        $this->shop = Shop::create([
            'woocommerce_domain' => 'ai-gw.example.com',
            'name' => 'AI Co',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function request(): AiRequest
    {
        return new AiRequest(
            stage: 'draft_generator',
            shopId: (int) $this->shop->getKey(),
            messages: [['role' => 'user', 'content' => 'כתוב ניוזלטר']],
            toolName: 'propose_newsletter_patch',
            toolSchema: ['type' => 'object', 'properties' => ['ops' => ['type' => 'array']]],
        );
    }

    private function connect(): void
    {
        $settings = PlatformAiSettings::current();
        $settings->anthropic_api_key = 'sk-ant-test';
        $settings->save();
    }

    /** @param array<string, mixed> $toolInput */
    private function fakeAnthropicSuccess(array $toolInput, int $in = 100, int $out = 50): void
    {
        Http::fake([
            self::API.'/v1/messages' => Http::response([
                'content' => [
                    ['type' => 'tool_use', 'name' => 'propose_newsletter_patch', 'input' => $toolInput],
                ],
                'usage' => ['input_tokens' => $in, 'output_tokens' => $out],
            ], 200),
        ]);
    }

    public function test_a_successful_call_returns_the_tool_input_and_pays_the_ledger(): void
    {
        $this->connect();
        $this->fakeAnthropicSuccess(['explanation' => 'הנה', 'ops' => []]);

        $result = (new AiGateway)->complete($this->request());

        $this->assertTrue($result->ok);
        $this->assertSame('הנה', $result->toolInput['explanation']);
        $this->assertSame(100, $result->inputTokens);

        $event = AiUsageEvent::acrossAllTenants()->sole();
        $this->assertSame(AiUsageEvent::STATUS_OK, $event->status);
        $this->assertSame(150, $event->input_tokens + $event->output_tokens);
        $this->assertSame((int) $this->shop->getKey(), (int) $event->shop_id);
    }

    public function test_no_key_fails_typed_without_touching_the_provider(): void
    {
        Http::fake();

        $result = (new AiGateway)->complete($this->request());

        $this->assertFalse($result->ok);
        $this->assertSame(AiResult::FAIL_NO_KEY, $result->failureReason);
        Http::assertNothingSent();

        // The refusal is ON the ledger — invisible failures hide outages.
        $this->assertSame(1, AiUsageEvent::acrossAllTenants()->count());
    }

    public function test_the_kill_switch_darkens_everything(): void
    {
        $this->connect();
        Http::fake();

        $settings = PlatformAiSettings::current();
        $settings->enabled = false;
        $settings->save();

        $result = (new AiGateway)->complete($this->request());

        $this->assertSame(AiResult::FAIL_DISABLED, $result->failureReason);
        Http::assertNothingSent();
    }

    public function test_over_budget_spends_zero_more(): void
    {
        $this->connect();
        Http::fake();

        $settings = PlatformAiSettings::current();
        $settings->daily_token_budget = 100;
        $settings->save();

        // Yesterday's spend does not count; today's does.
        $event = new AiUsageEvent;
        $event->forceFill([
            'shop_id' => (int) $this->shop->getKey(),
            'stage' => 'draft_generator', 'provider' => 'anthropic', 'model' => 'm',
            'input_tokens' => 80, 'output_tokens' => 40,
            'status' => AiUsageEvent::STATUS_OK,
        ])->save();

        $result = (new AiGateway)->complete($this->request());

        $this->assertSame(AiResult::FAIL_OVER_BUDGET, $result->failureReason);
        Http::assertNothingSent();

        $this->assertSame(
            AiUsageEvent::STATUS_OVER_BUDGET,
            AiUsageEvent::acrossAllTenants()->latest('id')->first()->status,
        );
    }

    public function test_a_provider_failure_is_typed_and_still_on_the_ledger(): void
    {
        $this->connect();
        Http::fake([self::API.'/v1/messages' => Http::response(['error' => 'boom'], 500)]);

        $result = (new AiGateway)->complete($this->request());

        $this->assertFalse($result->ok);
        $this->assertSame(AiResult::FAIL_HTTP, $result->failureReason);
        $this->assertSame(
            AiUsageEvent::STATUS_FAILED,
            AiUsageEvent::acrossAllTenants()->sole()->status,
        );
    }

    public function test_prose_without_a_tool_call_is_bad_output_not_data(): void
    {
        $this->connect();
        Http::fake([
            self::API.'/v1/messages' => Http::response([
                'content' => [['type' => 'text', 'text' => 'הנה ה-HTML: <html>...</html>']],
                'usage' => ['input_tokens' => 10, 'output_tokens' => 20],
            ], 200),
        ]);

        $result = (new AiGateway)->complete($this->request());

        // Free text is refused BY SHAPE — the model answers through the schema
        // or not at all. Raw HTML can never arrive as data.
        $this->assertSame(AiResult::FAIL_BAD_TOOL_OUTPUT, $result->failureReason);
        $this->assertNull($result->toolInput);
    }

    public function test_the_prompt_ladder_db_wins_config_falls_back(): void
    {
        $repository = new PromptRepository;

        $default = $repository->promptFor('draft_generator');
        $this->assertSame(config('ai.stages.draft_generator.system'), $default['system']);

        $repository->saveFor('draft_generator', 'הנוסח שלי', 'claude-custom', null);

        $overridden = $repository->promptFor('draft_generator');
        $this->assertSame('הנוסח שלי', $overridden['system']);
        $this->assertSame('claude-custom', $overridden['model']);

        // Clearing the words is the reset.
        $repository->saveFor('draft_generator', '', '', null);
        $this->assertSame(config('ai.stages.draft_generator.system'), $repository->promptFor('draft_generator')['system']);
    }

    public function test_a_saved_key_wins_over_the_environment(): void
    {
        Config::set('ai.providers.anthropic.api_key', 'sk-env');
        $this->assertSame('sk-env', PlatformAiSettings::current()->apiKey());

        $this->connect(); // saves sk-ant-test
        $this->assertSame('sk-ant-test', PlatformAiSettings::current()->fresh()->apiKey());
    }
}
