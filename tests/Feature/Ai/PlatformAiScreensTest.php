<?php

namespace Tests\Feature\Ai;

use App\Domain\Ai\PromptRepository;
use App\Filament\Pages\ManageAiPrompts;
use App\Filament\Pages\ManagePlatformAi;
use App\Models\PlatformAiSettings;
use App\Models\Shop;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The two owner screens. The laws are the platform-secret ones: only a
 * platform admin (and never while entered into a shop), the key encrypted and
 * never shown back, blank keeps it.
 */
final class PlatformAiScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Config::set('ai.providers.anthropic.api_key', null);
    }

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    private function platformAdmin(): User
    {
        return User::factory()->create(['shop_id' => null, 'is_platform_admin' => true]);
    }

    public function test_only_a_platform_admin_reaches_both_screens(): void
    {
        $shop = Shop::create([
            'woocommerce_domain' => 'ai-screens.example.com',
            'name' => 'x',
            'status' => Shop::STATUS_ACTIVE,
            'platform' => Shop::PLATFORM_WOOCOMMERCE,
        ]);

        $this->actingAs(User::factory()->forShop($shop)->create());
        $this->assertFalse(ManagePlatformAi::canAccess());
        $this->assertFalse(ManageAiPrompts::canAccess());

        $this->actingAs($this->platformAdmin());
        $this->assertTrue(ManagePlatformAi::canAccess());
        $this->assertTrue(ManageAiPrompts::canAccess());

        Tenant::set($shop);
        $this->assertFalse(ManagePlatformAi::canAccess());
        $this->assertFalse(ManageAiPrompts::canAccess());
    }

    public function test_the_key_is_encrypted_never_shown_back_and_blank_keeps_it(): void
    {
        $this->actingAs($this->platformAdmin());

        Livewire::test(ManagePlatformAi::class)
            ->set('data.anthropic_api_key', 'sk-ant-secret')
            ->call('save')
            ->assertHasNoErrors()
            ->assertSet('data.anthropic_api_key', null);

        $this->assertSame('sk-ant-secret', PlatformAiSettings::current()->apiKey());

        $raw = (string) DB::table('platform_ai_settings')->value('anthropic_api_key');
        $this->assertStringNotContainsString('sk-ant-secret', $raw);

        // An owner adjusting the budget must not blank the credential.
        Livewire::test(ManagePlatformAi::class)
            ->set('data.daily_token_budget', 500_000)
            ->call('save');

        $settings = PlatformAiSettings::current()->fresh();
        $this->assertSame('sk-ant-secret', $settings->apiKey());
        $this->assertSame(500_000, $settings->dailyTokenBudget());
    }

    public function test_the_kill_switch_round_trips(): void
    {
        $this->actingAs($this->platformAdmin());

        Livewire::test(ManagePlatformAi::class)
            ->set('data.enabled', false)
            ->call('save');

        $this->assertFalse(PlatformAiSettings::current()->fresh()->isEnabled());
    }

    public function test_prompts_save_and_reset_by_clearing(): void
    {
        $this->actingAs($this->platformAdmin());

        Livewire::test(ManageAiPrompts::class)
            ->set('data.subject_writer_prompt', 'תמיד עם חיוך')
            ->call('save');

        $this->assertSame(
            'תמיד עם חיוך',
            (new PromptRepository)->promptFor('subject_writer')['system'],
        );

        Livewire::test(ManageAiPrompts::class)
            ->set('data.subject_writer_prompt', '')
            ->call('save');

        $this->assertSame(
            config('ai.stages.subject_writer.system'),
            (new PromptRepository)->promptFor('subject_writer')['system'],
        );
    }
}
