<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\CampaignBodyNormalizer;
use App\Domain\Campaigns\Email\CampaignPreview;
use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Models\CustomerLoginToken;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Mail\CampaignMail;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The strtr wall, and the two ways a placeholder can be lost.
 *
 * A campaign body is HTML a merchant typed. It is substituted with strtr() and
 * NEVER compiled — so Blade syntax in it is text, and a value that happens to
 * contain a token is not re-expanded. That is the whole security story of the
 * merchant-authored email, and it is worth its own file.
 *
 * The other half is duller and just as damaging: a rich-text editor
 * percent-encodes the braces inside an href, and a `{account_login_url}` that
 * arrives as `%7Baccount_login_url%7D` is a passwordless link that silently
 * does nothing.
 */
final class CampaignTemplateSafetyTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    // === The wall ===

    public function test_blade_syntax_in_a_merchant_body_is_inert_text(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $hostile = '<p>{{ 7*7 }} @php echo 1; @endphp {!! $x !!} {{ $shop->payplus_credentials }}</p>'
            .'<a href="{unsubscribe_url}">Out</a>';

        $this->inShop($shop, function () use ($shop, $hostile): void {
            $this->makePlan($shop, 'dana@example.com');

            app(EmailCampaignSender::class)
                ->send($shop, $this->makeCampaign($shop, body: $hostile));

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                $rendered = $mail->content()->with['renderedHtml'];

                return str_contains($rendered, '{{ 7*7 }}')
                    && str_contains($rendered, '@php')
                    && ! str_contains($rendered, '49');
            });
        });
    }

    /** strtr is ONE pass: a name containing a token is not a template. */
    public function test_a_value_containing_a_token_is_not_expanded_again(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com', name: '{account_login_url}');

            app(EmailCampaignSender::class)
                ->send($shop, $this->makeCampaign($shop, body: '<p>{customer_name}</p><a href="{unsubscribe_url}">o</a>'));

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                $rendered = $mail->content()->with['renderedHtml'];

                // The literal token survives as the NAME; it never became a URL.
                return str_contains($rendered, '<p>{account_login_url}</p>')
                    && ! str_contains($rendered, '<p>'.url('/c/login/'));
            });
        });
    }

    // === The encoding trap ===

    public function test_a_percent_encoded_placeholder_in_an_href_is_restored(): void
    {
        $body = '<a href="%7Baccount_login_url%7D">Enter</a><a href="%7bunsubscribe_url%7d">Out</a>';

        $clean = CampaignBodyNormalizer::clean($body);

        $this->assertStringContainsString('href="{account_login_url}"', $clean);
        $this->assertStringContainsString('href="{unsubscribe_url}"', $clean);
    }

    public function test_the_normalizer_leaves_ordinary_markup_alone(): void
    {
        $body = '  <p>Hello <strong>you</strong> — 100% sure</p>  ';

        $this->assertSame('<p>Hello <strong>you</strong> — 100% sure</p>', CampaignBodyNormalizer::clean($body));
    }

    public function test_the_body_is_capped(): void
    {
        $clean = CampaignBodyNormalizer::clean(str_repeat('a', EmailCampaign::MAX_BODY + 500));

        $this->assertSame(EmailCampaign::MAX_BODY, mb_strlen($clean));
    }

    // === Previews mint nothing ===

    public function test_a_preview_shows_sample_links_and_creates_no_token(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $preview = app(CampaignPreview::class)->render(
                subject: 'Hi {customer_name}',
                body: '<a href="{account_login_url}">Enter</a>',
                shop: $shop,
            );

            $this->assertStringContainsString('/c/login/', $preview['html']);
            $this->assertStringNotContainsString('{account_login_url}', $preview['html']);
            $this->assertSame(0, CustomerLoginToken::query()->count(), 'a preview is never a credential');
        });
    }

    public function test_a_preview_renders_hostile_markup_as_text_too(): void
    {
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $preview = app(CampaignPreview::class)->render(
                subject: '{{ 2+2 }}',
                body: '<p>@php echo 9; @endphp {{ 3*3 }}</p>',
                shop: $shop,
            );

            // Both come back byte-for-byte: nothing was compiled, nothing ran.
            $this->assertSame('{{ 2+2 }}', $preview['subject']);
            $this->assertSame('<p>@php echo 9; @endphp {{ 3*3 }}</p>', $preview['html']);
        });
    }
}
