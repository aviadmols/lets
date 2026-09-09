<?php

namespace Tests\Feature\Campaigns\Email;

use App\Domain\Campaigns\Email\EmailCampaignSender;
use App\Domain\Campaigns\Email\Models\EmailCampaign;
use App\Mail\CampaignMail;
use App\Models\InstallmentPlan;
use App\Models\Shop;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * `{customer_address}` — where the person lives, in the mail they receive.
 *
 * The address is READ, never fetched: the send path must not make one API call
 * per recipient to fill a line of text. So the answer comes from the plan's own
 * contact block, and the two ways it can be reached are both pinned here —
 * directly through the row that enrolled the person, and by EMAIL when that row
 * (a club membership, a Shopify contract) carries no address of its own.
 *
 * The third case is the one that would embarrass a merchant: somebody we hold
 * no address for must render an EMPTY token, never a stale one and never
 * somebody else's street.
 */
final class CampaignAddressTokenTest extends TestCase
{
    use MakesEmailCampaigns;
    use RefreshDatabase;

    // === CONSTANTS ===
    private const BODY = '<p>{customer_address}</p><a href="{unsubscribe_url}">Out</a>';

    /** An imported member's address, in the import's own vocabulary. */
    private const ADDRESS = [
        'street' => 'אליהו הנביא',
        'building_number' => '18',
        'apartment_number' => '4',
        'city' => 'חיפה',
        'zip_code' => '3303130',
    ];

    protected function tearDown(): void
    {
        Tenant::clear();
        parent::tearDown();
    }

    public function test_a_plan_recipient_receives_their_own_address_as_one_line(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $plan = $this->makePlan($shop, 'dana@example.com');
            $this->giveAddress($plan);

            app(EmailCampaignSender::class)->send($shop, $this->makeCampaign($shop, body: self::BODY));

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                return $this->readsAsOneLine($mail);
            });
        });
    }

    /**
     * The row that ENROLLED somebody is often not the row that knows where they
     * live. A club member has no address at all — but the same person's
     * subscription does, even a cancelled one the audience filter never touched.
     */
    public function test_a_member_without_a_plan_row_still_gets_the_address_we_hold_for_that_email(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makeMember($shop, 'yael@example.com');
            $plan = $this->makePlan($shop, 'yael@example.com', status: PlanStatus::CANCELLED);
            $this->giveAddress($plan);

            $campaign = $this->makeCampaign(
                $shop,
                audience: ['sources' => [EmailCampaign::SOURCE_LOYALTY_MEMBERS]],
                body: self::BODY,
            );

            app(EmailCampaignSender::class)->send($shop, $campaign);

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                return $this->readsAsOneLine($mail);
            });
        });
    }

    /** No address on file → an empty token. Never a guess, never the last one read. */
    public function test_a_recipient_we_hold_no_address_for_renders_nothing(): void
    {
        Mail::fake();
        $shop = $this->makeShop();

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');

            app(EmailCampaignSender::class)->send($shop, $this->makeCampaign($shop, body: self::BODY));

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                $rendered = $mail->content()->with['renderedHtml'];

                return str_contains($rendered, '<p></p>')
                    && ! str_contains($rendered, '{customer_address}');
            });
        });
    }

    /** One shop's address can never reach another shop's campaign. */
    public function test_the_lookup_is_bound_to_the_tenant(): void
    {
        Mail::fake();
        $other = $this->makeShop('other.example.com');
        $shop = $this->makeShop();

        $this->inShop($other, function () use ($other): void {
            $this->giveAddress($this->makePlan($other, 'dana@example.com'));
        });

        $this->inShop($shop, function () use ($shop): void {
            $this->makePlan($shop, 'dana@example.com');

            app(EmailCampaignSender::class)->send($shop, $this->makeCampaign($shop, body: self::BODY));

            Mail::assertSent(CampaignMail::class, function (CampaignMail $mail): bool {
                return ! str_contains($mail->content()->with['renderedHtml'], 'אליהו הנביא');
            });
        });
    }

    /** The token is registered, so the chips offer it and the body normaliser rescues it. */
    public function test_the_token_is_one_the_engine_knows(): void
    {
        $this->assertContains('customer_address', EmailCampaign::PLACEHOLDERS);
    }

    /**
     * The line, without pinning the apartment word: it is a TRANSLATION, and
     * which language it comes out in is the merchant's mail-language setting.
     */
    private function readsAsOneLine(CampaignMail $mail): bool
    {
        $html = $mail->content()->with['renderedHtml'];

        return str_contains($html, 'אליהו הנביא 18, ') && str_contains($html, ', חיפה, 3303130');
    }

    private function giveAddress(InstallmentPlan $plan): void
    {
        $plan->forceFill([
            'meta' => array_merge((array) ($plan->meta ?? []), [
                InstallmentPlan::META_CONTACT_ADDRESS => self::ADDRESS,
            ]),
        ])->save();
    }
}
