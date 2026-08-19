<?php

namespace Tests\Unit\Models;

use App\Models\AccountOffer;
use Tests\TestCase;

/**
 * The offer model's GUARDS — the reads that stand between what a merchant typed
 * and what lands on a shopper's page.
 *
 * No database: every one of these is a pure function of the row's own columns,
 * which is the point. A guard that needed a query would be a guard somebody
 * would be tempted to skip.
 */
final class AccountOfferTest extends TestCase
{
    // === CONSTANTS ===
    private const VALID_HTML = '<p>Save more</p>{{button}}';

    public function test_the_custom_html_rule_demands_exactly_one_button_token(): void
    {
        // No custom HTML at all is fine — the merchant gets the designed card.
        $this->assertNull(AccountOffer::validateCustomHtml(null));
        $this->assertNull(AccountOffer::validateCustomHtml('   '));

        $this->assertNull(AccountOffer::validateCustomHtml(self::VALID_HTML));

        // None: nobody could accept the offer.
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_REQUIRED,
            AccountOffer::validateCustomHtml('<p>Save more</p>'),
        );

        // Two: a second control wired to the same charge.
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_REQUIRED,
            AccountOffer::validateCustomHtml('{{button}}<p>or</p>{{button}}'),
        );
    }

    public function test_a_button_token_hidden_in_an_attribute_does_not_count(): void
    {
        // It would never render as a button, so it must not pass for one — which
        // is what counting AFTER strip_tags actually checks.
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_REQUIRED,
            AccountOffer::validateCustomHtml('<a href="/x" title="{{button}}">go</a>'),
        );
    }

    public function test_html_that_is_entirely_stripped_fails_rather_than_saving_blank(): void
    {
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_REQUIRED,
            AccountOffer::validateCustomHtml('<script>alert(1)</script>'),
        );
    }

    public function test_an_unreadable_mode_timing_or_placement_falls_back_rather_than_throwing(): void
    {
        $offer = new AccountOffer([
            'mode' => 'sideways',
            'replace_timing' => 'whenever',
            'placement' => 'nowhere',
        ]);

        // Replace is the safe default: it is the mode the one-subscription rule
        // never conflicts with. Immediate is the default the disclosure matches.
        $this->assertSame(AccountOffer::MODE_REPLACE, $offer->mode());
        $this->assertSame(AccountOffer::TIMING_IMMEDIATE, $offer->timing());
        $this->assertSame(AccountOffer::PLACEMENT_PLAN, $offer->placement());
    }

    public function test_an_add_offer_reports_no_timing(): void
    {
        $offer = new AccountOffer(['mode' => AccountOffer::MODE_ADD, 'replace_timing' => 'period_end']);

        // It ends no period, so it must not claim one — but it still takes money
        // today, and isImmediate() is the question the money asks.
        $this->assertNull($offer->timing());
        $this->assertTrue($offer->isImmediate());
    }

    public function test_the_audience_drops_values_no_enum_recognises_and_never_narrows_to_nothing(): void
    {
        $offer = new AccountOffer([
            'audience' => [
                'plan_kinds' => ['recurring', 'telepathy'],
                'frequencies' => ['yearly', 'yearly', 'fortnightly'],
                'product_ids' => ['2666', 2666, ' 2675 ', ''],
                'statuses' => ['nonsense'],
            ],
        ]);

        $audience = $offer->audience();

        $this->assertSame(['recurring'], $audience['plan_kinds']);
        $this->assertSame(['yearly'], $audience['frequencies']);
        // STRINGS, deduped by value: an array key of "2666" becomes the integer
        // 2666, and the comparison downstream is strict.
        $this->assertSame(['2666', '2675'], $audience['product_ids']);
        // A filter that lost every value stops narrowing rather than hiding the
        // offer from everybody — except statuses, which fall back to the default.
        $this->assertSame(AccountOffer::DEFAULT_AUDIENCE_STATUSES, $audience['statuses']);
    }

    public function test_only_https_images_reach_a_customer_page(): void
    {
        $this->assertNull((new AccountOffer(['image_url' => 'http://cdn.test/a.png']))->imageUrl());
        $this->assertNull((new AccountOffer(['image_url' => 'javascript:alert(1)']))->imageUrl());
        $this->assertSame(
            'https://cdn.test/a.png',
            (new AccountOffer(['image_url' => 'https://cdn.test/a.png']))->imageUrl(),
        );
    }

    public function test_the_date_window_is_open_when_the_merchant_named_no_dates(): void
    {
        $this->assertTrue((new AccountOffer)->isOpenAt(now()));

        $this->assertFalse((new AccountOffer(['starts_at' => now()->addDay()]))->isOpenAt(now()));
        $this->assertFalse((new AccountOffer(['ends_at' => now()->subDay()]))->isOpenAt(now()));
        $this->assertTrue(
            (new AccountOffer(['starts_at' => now()->subDay(), 'ends_at' => now()->addDay()]))->isOpenAt(now()),
        );
    }
}
