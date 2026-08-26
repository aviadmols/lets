<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Filament\Resources\CampaignResource;
use App\Filament\Resources\CampaignResource\Pages\EditCampaign;
use App\Models\User;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The composer screen — the WHO → WHAT → WHEN route, and the live preview.
 *
 * The assertions here are the ones that fail SILENTLY in a browser and in no
 * other way: a preview entangled to the wrong property path renders a blank
 * white frame and every test that merely mounts the page still passes, and a
 * preview that leaked a real login token would look identical to one that did
 * not.
 */
final class CampaignComposerTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_the_preview_is_entangled_to_the_real_form_state(): void
    {
        $shop = $this->makeShop();
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $campaign = $this->inShop($shop, fn (): EmailCampaign => $this->makeCampaign($shop));

        // The FULL property paths, not the bare field names. Entangling
        // `body_html` binds to a property that does not exist on the Livewire
        // component, and the merchant gets a permanently blank preview with no
        // error anywhere to explain it.
        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->assertOk()
            ->assertSee("\$wire.\$entangle('data.body_html')", escape: false)
            ->assertSee("\$wire.\$entangle('data.subject')", escape: false);
    }

    public function test_the_preview_never_carries_a_real_credential(): void
    {
        $shop = $this->makeShop();
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $campaign = $this->inShop($shop, fn (): EmailCampaign => $this->makeCampaign($shop));

        $html = Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->assertOk()
            ->html();

        // The sample bag, and only the sample bag: a live single-use sign-in
        // link rendered onto an admin screen is a link somebody could spend.
        $this->assertStringContainsString('sample', $html);
        $this->assertSame(
            0,
            $campaign->loginTokens()->count(),
            'Rendering the composer must not mint a login token.',
        );
    }

    public function test_the_screen_asks_who_before_what(): void
    {
        $shop = $this->makeShop();
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $campaign = $this->inShop($shop, fn (): EmailCampaign => $this->makeCampaign($shop));

        $html = Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->assertOk()
            ->html();

        // e(), because a label carrying an ampersand reaches the page escaped.
        $audience = mb_strpos($html, e(__(CampaignResource::LANG.'.step.audience')));
        $design = mb_strpos($html, e(__(CampaignResource::LANG.'.step.design')));
        $send = mb_strpos($html, e(__(CampaignResource::LANG.'.step.send')));

        $this->assertNotFalse($audience);
        $this->assertNotFalse($design);
        $this->assertNotFalse($send);
        // WHO, then WHAT they read, then WHEN it goes — the order the decision
        // is actually made in.
        $this->assertLessThan($design, $audience);
        $this->assertLessThan($send, $design);
    }

    public function test_the_named_list_survives_a_save_with_the_rules_beside_it(): void
    {
        $shop = $this->makeShop();
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $campaign = $this->inShop($shop, fn (): EmailCampaign => $this->makeCampaign($shop, [
            'sources' => [EmailCampaign::SOURCE_SUBSCRIBERS],
        ]));

        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->set('data.audience.emails', ['Named@example.com'])
            ->call('save')
            ->assertHasNoErrors();

        $saved = $campaign->fresh()->audience();

        // The list is stored clean; the rules the merchant described are kept
        // exactly as they were, so clearing the list restores them.
        $this->assertSame(['named@example.com'], $saved['emails']);
        $this->assertSame([EmailCampaign::SOURCE_SUBSCRIBERS], $saved['sources']);
    }

    public function test_a_typed_address_that_is_not_one_is_refused_at_the_field(): void
    {
        $shop = $this->makeShop();
        Tenant::set($shop);
        $this->actingAs(User::factory()->forShop($shop)->create());

        $campaign = $this->inShop($shop, fn (): EmailCampaign => $this->makeCampaign($shop));

        // The model's guard would drop it silently on read; the FIELD says so
        // out loud, because a merchant who mistyped an address meant to reach
        // somebody and should be told they have not.
        Livewire::test(EditCampaign::class, ['record' => $campaign->getKey()])
            ->set('data.audience.emails', ['fine@example.com', 'not-an-address'])
            ->call('save')
            ->assertHasErrors('data.audience.emails.1');

        $this->assertSame([], $campaign->fresh()->audience()['emails']);
    }
}
