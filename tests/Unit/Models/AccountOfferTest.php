<?php

namespace Tests\Unit\Models;

use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use Tests\TestCase;

/**
 * The offer and target models' GUARDS — the reads that stand between what a
 * merchant typed and what lands on a shopper's page.
 *
 * No database: every one of these is a pure function of the row's own columns,
 * which is the point. A guard that needed a query would be a guard somebody
 * would be tempted to skip.
 */
final class AccountOfferTest extends TestCase
{
    // === CONSTANTS ===
    private const VALID_HTML = '<p>Save more</p>{{button}}';

    /** One target, addressable three ways: slug, product id, position. */
    private const ONE_TARGET = [[
        'token_key' => 'upgrade',
        'external_product_id' => '2675',
        'position' => 1,
        'stable_key' => 'upgrade',
    ]];

    public function test_the_custom_html_rule_demands_at_least_one_button_token(): void
    {
        // No custom HTML at all is fine — the merchant gets the designed cards.
        $this->assertNull(AccountOffer::validateCustomHtml(null, self::ONE_TARGET));
        $this->assertNull(AccountOffer::validateCustomHtml('   ', self::ONE_TARGET));

        $this->assertNull(AccountOffer::validateCustomHtml(self::VALID_HTML, self::ONE_TARGET));

        // None: nobody could accept the offer.
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_REQUIRED,
            AccountOffer::validateCustomHtml('<p>Save more</p>', self::ONE_TARGET)['key'],
        );
    }

    /**
     * TWO buttons for one target used to be refused (a second control wired to
     * the same charge). It is now allowed, because an offer that sells several
     * things has several buttons — and repeating one target's button top and
     * bottom of a long block is a layout decision, not a double charge: the
     * acceptance is idempotent on the target either way.
     */
    public function test_several_button_tokens_are_allowed_now_that_an_offer_sells_several_things(): void
    {
        $targets = [
            ['token_key' => 'monthly', 'external_product_id' => '2675', 'position' => 1, 'stable_key' => 'monthly'],
            ['token_key' => 'mug', 'external_product_id' => '4242', 'position' => 2, 'stable_key' => 'mug'],
        ];

        $this->assertNull(AccountOffer::validateCustomHtml(
            '<p>Switch {{button_monthly}}</p><p>or add the mug {{button_mug}}</p>',
            $targets,
        ));

        // The same target twice is a layout, not an error.
        $this->assertNull(AccountOffer::validateCustomHtml('{{button}}<p>or</p>{{button_monthly}}', $targets));
    }

    public function test_a_token_that_names_nothing_is_refused_and_says_which(): void
    {
        $error = AccountOffer::validateCustomHtml('<p>{{button_upgade}}</p>', self::ONE_TARGET);

        $this->assertSame(AccountOffer::ERROR_BUTTON_UNKNOWN, $error['key']);
        // The merchant is told the exact token, not "something is wrong".
        $this->assertSame('{{button_upgade}}', $error['params']['token']);
    }

    public function test_a_token_resolves_by_slug_product_id_or_position(): void
    {
        foreach (['{{button}}', '{{button_upgrade}}', '{{button_2675}}', '{{button_1}}'] as $token) {
            $this->assertNull(
                AccountOffer::validateCustomHtml('<p>x</p>'.$token, self::ONE_TARGET),
                $token.' must resolve',
            );
        }
    }

    /** A bare {{button}} on an offer with no targets at all names nothing. */
    public function test_a_button_token_with_no_targets_at_all_is_refused(): void
    {
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_UNKNOWN,
            AccountOffer::validateCustomHtml(self::VALID_HTML, [])['key'],
        );
    }

    public function test_a_button_token_hidden_in_an_attribute_does_not_count(): void
    {
        // It would never render as a button, so it must not pass for one — which
        // is what counting AFTER strip_tags actually checks.
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_REQUIRED,
            AccountOffer::validateCustomHtml('<a href="/x" title="{{button}}">go</a>', self::ONE_TARGET)['key'],
        );
    }

    public function test_html_that_is_entirely_stripped_fails_rather_than_saving_blank(): void
    {
        $this->assertSame(
            AccountOffer::ERROR_BUTTON_REQUIRED,
            AccountOffer::validateCustomHtml('<script>alert(1)</script>', self::ONE_TARGET)['key'],
        );
    }

    /** The form wants a sentence, and the sentence names the token. */
    public function test_the_error_helper_returns_a_resolved_sentence(): void
    {
        $message = AccountOffer::customHtmlError('<p>{{button_nope}}</p>', self::ONE_TARGET);

        $this->assertNotNull($message);
        $this->assertStringContainsString('{{button_nope}}', $message);
        $this->assertStringNotContainsString('account_offers.', $message);
        $this->assertNull(AccountOffer::customHtmlError(self::VALID_HTML, self::ONE_TARGET));
    }

    public function test_an_unreadable_placement_falls_back_rather_than_throwing(): void
    {
        $this->assertSame(
            AccountOffer::PLACEMENT_PLAN,
            (new AccountOffer(['placement' => 'nowhere']))->placement(),
        );
    }

    public function test_an_unreadable_mode_or_timing_on_a_target_falls_back_rather_than_throwing(): void
    {
        $target = new AccountOfferTarget([
            'kind' => 'sideways',
            'mode' => 'sideways',
            'replace_timing' => 'whenever',
        ]);

        // Subscription is the safe kind (it is the one that reads a template
        // rather than trusting a product id), ADD is the safe mode — it ends
        // nothing — and immediate is the timing the disclosure matches.
        $this->assertSame(AccountOfferTarget::KIND_SUBSCRIPTION, $target->kind());
        $this->assertSame(AccountOfferTarget::MODE_ADD, $target->mode());
        $this->assertNull($target->timing(), 'an ADD ends no period');
        $this->assertTrue($target->chargesNow());
    }

    public function test_a_one_time_target_is_always_an_add_and_never_reports_a_timing(): void
    {
        $target = new AccountOfferTarget([
            'kind' => AccountOfferTarget::KIND_ONE_TIME,
            // A merchant (or a bad import) says replace. Buying a mug does not
            // end a subscription, whatever the column says.
            'mode' => AccountOfferTarget::MODE_REPLACE,
            'replace_timing' => AccountOfferTarget::TIMING_PERIOD_END,
            'fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER,
        ]);

        $this->assertSame(AccountOfferTarget::MODE_ADD, $target->mode());
        $this->assertNull($target->timing());
        $this->assertSame(AccountOfferTarget::FULFILMENT_NEXT_ORDER, $target->fulfilment());
        $this->assertFalse($target->chargesNow(), 'a next-order add-on takes no money today');
    }

    public function test_a_subscription_target_never_reports_a_fulfilment(): void
    {
        $target = new AccountOfferTarget([
            'kind' => AccountOfferTarget::KIND_SUBSCRIPTION,
            'fulfilment' => AccountOfferTarget::FULFILMENT_NEXT_ORDER,
        ]);

        $this->assertNull($target->fulfilment());
    }

    public function test_the_stable_key_prefers_the_slug_then_the_product_then_the_position(): void
    {
        $this->assertSame('upgrade', (new AccountOfferTarget([
            'token_key' => 'upgrade', 'external_product_id' => '2675', 'position' => 3,
        ]))->stableKey());

        $this->assertSame('2675', (new AccountOfferTarget([
            'external_product_id' => '2675', 'position' => 3,
        ]))->stableKey());

        $this->assertSame('3', (new AccountOfferTarget(['position' => 3]))->stableKey());

        // A slug the merchant typed in caps or with a space is not a slug.
        $this->assertSame('1', (new AccountOfferTarget(['token_key' => 'Up Grade']))->stableKey());

        // A DIGITS-ONLY slug is not a slug either: bare numbers belong to row
        // order, and honouring "2" as the first row's NAME would rewire
        // {{button_2}} away from the second row — crossed buttons on a live
        // page. The value dies at the guard and the position speaks instead.
        $this->assertNull((new AccountOfferTarget(['token_key' => '2']))->tokenKey());
        $this->assertSame('4', (new AccountOfferTarget(['token_key' => '2', 'position' => 4]))->stableKey());
    }

    public function test_a_quantity_is_clamped_and_only_a_one_time_target_has_one(): void
    {
        $subscription = new AccountOfferTarget(['quantity' => 4]);
        $this->assertSame(1, $subscription->quantity(), 'one subscription is one subscription');

        $oneTime = fn (int $q): int => (new AccountOfferTarget([
            'kind' => AccountOfferTarget::KIND_ONE_TIME, 'quantity' => $q,
        ]))->quantity();

        $this->assertSame(1, $oneTime(0));
        $this->assertSame(3, $oneTime(3));
        $this->assertSame(AccountOfferTarget::MAX_QUANTITY, $oneTime(9999));
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
