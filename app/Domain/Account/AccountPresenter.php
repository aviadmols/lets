<?php

namespace App\Domain\Account;

use App\Domain\Account\Offers\AccountOfferEligibility;
use App\Domain\Account\Offers\AccountOfferPresenter;
use App\Domain\Account\Offers\AccountOfferQuote;
use App\Domain\Billing\CycleAmountResolver;
use App\Domain\Loyalty\Http\LoyaltyVisitor;
use App\Domain\Loyalty\Rendering\LoyaltyPagePresenter;
use App\Models\AccountOffer;
use App\Models\InstallmentPaymentMethod;
use App\Models\InstallmentPlan;
use App\Models\IssuedDocument;
use App\Models\MerchantBillingSettings;
use App\Models\MerchantLoyaltySettings;
use App\Models\MerchantPortalAppearance;
use App\Models\PaymentLedger;
use App\Modules\PayPlusShopifyInstallments\Enums\PaymentStatus;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\Tenant;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * The whole personal area as ONE JSON view-model.
 *
 * `lets-account.js` renders this and nothing else, so this class is the only
 * place that decides what a shopper can see. Two callers keep it honest, the
 * discipline LoyaltyPagePresenter already proves: `present()` for the live page
 * and `sample()` for the merchant's admin preview. A field the preview cannot
 * fabricate is a field the live page should not be inventing either.
 *
 * Money is never taken from the client and never recomputed here — the per-cycle
 * figures come from CycleAmountResolver, the single source the charge itself uses,
 * so the number a shopper reads is the number that will move.
 *
 * Strings arrive already translated. The WordPress plugin ships no .mo catalogs,
 * and asking it to would mean maintaining the same copy twice; resolving here
 * against the shopper's locale keeps one source of truth.
 */
final class AccountPresenter
{
    // === CONSTANTS ===
    /** Status → the badge tone the stylesheet knows how to draw. */
    public const TONE_ACTIVE = 'active';

    public const TONE_PAUSED = 'paused';

    public const TONE_ENDED = 'ended';

    public const TONE_ATTENTION = 'attention';

    private const STATUS_TONES = [
        'active' => self::TONE_ACTIVE,
        'paused' => self::TONE_PAUSED,
        'awaiting_first_payment' => self::TONE_ATTENTION,
        'failed' => self::TONE_ATTENTION,
        'draft' => self::TONE_ATTENTION,
        'completed' => self::TONE_ENDED,
        'cancelled' => self::TONE_ENDED,
    ];

    /**
     * Reading order for the subscription list — lower sorts higher on the page.
     * A live plan outranks one that never started; an ended one sinks.
     */
    private const STATUS_ORDER = [
        'active' => 0,
        'paused' => 1,
        'failed' => 2,
        'awaiting_first_payment' => 3,
        'draft' => 4,
        'completed' => 5,
        'cancelled' => 6,
    ];

    /** An unknown status sorts with the live ones rather than vanishing to the end. */
    private const STATUS_ORDER_DEFAULT = 2;

    /**
     * What makes a reader a SUBSCRIBER for banner targeting. A paused plan still
     * is one — the shopper pays again the moment they resume, and telling them to
     * "start subscribing" while they hold a live plan reads as an insult. An
     * abandoned checkout (awaiting_first_payment) never paid, so it is not one.
     */
    private const SUBSCRIBER_STATUSES = [PlanStatus::ACTIVE, PlanStatus::PAUSED];

    /**
     * What a shopper should SEE instead of a three-letter currency code. "ILS 1"
     * is a code beside a number; "1 ₪" is a price. Anything not listed keeps its
     * code, which is the correct fallback for a currency we have no symbol for.
     */
    private const CURRENCY_SYMBOLS = [
        'ILS' => '₪',
        'USD' => '$',
        'EUR' => '€',
        'GBP' => '£',
    ];

    /** Payment rows shown per plan. Enough to recognise, few enough to scan. */
    private const MAX_PAYMENTS = 12;

    /** The sample subscription's public id — the preview's only "plan". */
    private const SAMPLE_PLAN_ID = 'SAMPLE-1';

    /** Days until the sample subscription renews (also its period-end offer date). */
    private const SAMPLE_NEXT_DAYS = 9;

    /**
     * Chrome the offer cards need, on top of the shared `ui.*` keys.
     *
     * The last three arrived with one-time targets: a card that sells a mug says
     * "Buy now", not "Choose this plan", and one that adds it to the next box has
     * to say WHEN — a shopper who thinks they have just been charged will check
     * their statement and then call.
     */
    private const OFFER_COPY_KEYS = [
        'offer_accept', 'offer_from', 'offer_replaces', 'offer_price_label', 'offer_unavailable',
        'offer_buy_now', 'offer_one_time', 'offer_add_to_next',
    ];

    public function __construct(
        private readonly CycleAmountResolver $cycles = new CycleAmountResolver,
        private readonly UpcomingBenefits $benefits = new UpcomingBenefits,
        private readonly AccountOfferPresenter $offers = new AccountOfferPresenter,
        private readonly AccountOfferEligibility $offerEligibility = new AccountOfferEligibility,
    ) {}

    /**
     * The live page for one identified (or logged-out) shopper.
     *
     * @return array<string, mixed>
     */
    public function present(AccountVisitor $visitor): array
    {
        $settings = MerchantPortalAppearance::current();
        $loyaltySettings = MerchantLoyaltySettings::current();

        if (! $visitor->isIdentified()) {
            // A logged-out visitor gets the shell and a sign-in prompt — never a
            // fail-closed blank, and never somebody else's subscriptions. They are
            // an UNKNOWN rather than a non-subscriber, so they see only the
            // banners aimed at everyone.
            return $this->shell($settings, $loyaltySettings, $this->banners($settings, null)) + [
                'identified' => false,
                'greeting' => null,
                'subscriptions' => [],
                'upcoming' => [],
                'benefits' => [],
                'loyalty' => null,
                'payment_methods' => [],
                // An offer is taken FROM a subscription, on a saved card. We know
                // neither for somebody we have not identified — so both slots are
                // present and empty rather than absent, and the renderer never has
                // to ask whether a key exists.
                'offers' => [],
                'rail_offers' => [],
            ];
        }

        $plans = $this->inReadingOrder(
            $visitor->plans()
                ->with(['payments' => fn ($q) => $q->orderByDesc('sequence')->limit(self::MAX_PAYMENTS)])
                ->orderByDesc('id')
                ->get()
        );

        $account = $visitor->loyaltyAccount();
        $offers = $this->offersFor($plans);

        return $this->shell($settings, $loyaltySettings, $this->banners($settings, $this->isSubscriber($plans))) + [
            'identified' => true,
            'greeting' => $visitor->displayName(),
            'subscriptions' => $plans
                ->map(fn (InstallmentPlan $p): array => $this->plan($p, $offers['by_plan'][(string) $p->public_id] ?? []))
                ->all(),
            'upcoming' => $this->benefits->for($plans, $account, $loyaltySettings),
            'benefits' => $this->activeBenefits($plans, $account),
            'loyalty' => $this->loyalty($visitor, $loyaltySettings),
            'payment_methods' => $this->paymentMethods($plans),
            'offers' => $offers[AccountOffer::PLACEMENT_TOP],
            'rail_offers' => $offers[AccountOffer::PLACEMENT_RAIL],
        ];
    }

    // === Account offers ===

    /**
     * The merchant's offers, aimed at THIS shopper and split by placement.
     *
     * Placement decides how many cards an offer produces. `plan` draws one under
     * every subscription it could be taken from — the shopper is being asked
     * about that specific plan, and the answer differs per plan. `top` and `rail`
     * draw ONE card for the offer as a whole, taken from the newest subscription
     * that qualifies, because a full-width strip repeated four times is not a
     * promotion, it is a wall.
     *
     * An offer is drawn when at least ONE of its targets is offerable. The rest
     * are dropped from the card silently: a promotion that offers three things
     * and can honestly sell two should sell two, not vanish.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return array{top: list<array<string, mixed>>, rail: list<array<string, mixed>>, by_plan: array<string, list<array<string, mixed>>>}
     */
    private function offersFor(Collection $plans): array
    {
        $out = [
            AccountOffer::PLACEMENT_TOP => [],
            AccountOffer::PLACEMENT_RAIL => [],
            'by_plan' => [],
        ];

        if ($plans->isEmpty()) {
            return $out;
        }

        $settings = MerchantBillingSettings::current();
        $shop = $plans->first()->shop;
        $now = now();

        foreach ($this->liveOffers() as $offer) {
            if (! $this->offerEligibility->isOpen($offer, $now, $settings)) {
                continue;
            }

            $sources = $this->offerEligibility->sourcesFor($offer, $plans);
            if ($sources === []) {
                continue;
            }

            // Price each target ONCE. The only thing a source contributes is the
            // currency, which withSource() re-applies per card.
            $quotes = [];
            foreach ($offer->orderedTargets() as $target) {
                $quote = AccountOfferQuote::forTarget($target, $plans->first(), $shop);
                if ($quote !== null) {
                    $quotes[] = [$target, $quote];
                }
            }

            if ($quotes === []) {
                continue;
            }

            $placement = $offer->placement();

            // `top`/`rail` name exactly one source; `plan` names each of them.
            foreach ($placement === AccountOffer::PLACEMENT_PLAN ? $sources : [$sources[0]] as $source) {
                $pairs = [];
                foreach ($quotes as [$target, $quote]) {
                    $quote = $quote->withSource($source);
                    if ($this->offerEligibility->targetIsOfferable($target, $quote, $plans, $settings, $source)) {
                        $pairs[] = [$target, $quote];
                    }
                }

                if ($pairs === []) {
                    continue;
                }

                $card = $this->offers->present($offer, $pairs, (string) $source->public_id, $source);

                if ($placement === AccountOffer::PLACEMENT_PLAN) {
                    $out['by_plan'][(string) $source->public_id][] = $card;
                } else {
                    $out[$placement][] = $card;
                }
            }
        }

        return $out;
    }

    /**
     * The shop's switched-on offers, best first. Tenant-scoped by BelongsToShop;
     * the targets and their templates ride along because every card has to price
     * itself.
     *
     * @return Collection<int, AccountOffer>
     */
    private function liveOffers(): Collection
    {
        return AccountOffer::query()
            ->open()
            ->with(['targets.template.product', 'targets.template.variant'])
            ->orderBy('priority')
            ->orderBy('id')
            ->get();
    }

    /**
     * What the shopper has RIGHT NOW, as opposed to what is coming.
     *
     * The split matters: "you are paying ₪89 instead of ₪119" is a reason to stay
     * subscribed, while "that ends on the 4th" is a reason to check the date. Two
     * sections, so neither reads as the other.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return list<array{label: string, note: ?string}>
     */
    private function activeBenefits($plans, $account): array
    {
        $out = [];

        foreach ($plans as $plan) {
            if ($plan->status !== PlanStatus::ACTIVE) {
                continue;
            }

            $window = $this->cycles->introWindowStatus($plan);
            $regular = $plan->regular_amount !== null ? round((float) $plan->regular_amount, 2) : null;
            $now = $this->cycles->amountForCharge($plan, $this->cycles->chargeNumberForNext($plan));

            if ($window !== null && $regular !== null && $now < $regular) {
                $out[] = [
                    'label' => __('account.active.intro_price', [
                        'now' => $this->money($now, $plan->currency),
                        'was' => $this->money($regular, $plan->currency),
                    ]),
                    'note' => __('account.active.intro_left', [
                        'count' => max(0, $window['total'] - $window['used']),
                    ]),
                ];
            }
        }

        // The tier's own perks are free text the merchant wrote; they are the
        // whole reason a shopper cares about a tier, so they belong here verbatim.
        $tier = $account?->tier;
        if ($tier !== null) {
            foreach ($tier->perkLines() as $line) {
                $out[] = ['label' => (string) $line, 'note' => (string) $tier->name];
            }
        }

        return $out;
    }

    private function money(float $amount, mixed $currency): string
    {
        $formatted = rtrim(rtrim(number_format($amount, 2, '.', ','), '0'), '.');

        return trim($formatted.' '.(string) ($currency ?: ''));
    }

    /**
     * A believable page with no database behind it, for the admin preview.
     *
     * Everything is invented but the SETTINGS: the merchant is previewing their
     * own sections, colours and banners, so those are real while the shopper is
     * not. Nothing here touches the tenant's data, and nothing is persisted.
     *
     * The banners are split by placement but NOT filtered by audience: the sample
     * shopper is nobody in particular, and a merchant who cannot see the banner
     * they just wrote assumes it is broken.
     *
     * @return array<string, mixed>
     */
    public function sample(?MerchantPortalAppearance $settings = null): array
    {
        $settings ??= MerchantPortalAppearance::current();
        $loyaltySettings = MerchantLoyaltySettings::current();

        $nextDate = now()->addDays(self::SAMPLE_NEXT_DAYS)->toDateString();
        $offers = $this->everyOffer($nextDate);

        return $this->shell($settings, $loyaltySettings, $this->everyBanner($settings)) + [
            'identified' => true,
            'preview' => true,
            'greeting' => __('account.sample.name'),
            'subscriptions' => [$this->sampleSubscription($nextDate, $offers[AccountOffer::PLACEMENT_PLAN])],
            'upcoming' => [
                [
                    'kind' => UpcomingBenefits::KIND_NEXT_DELIVERY,
                    'tone' => UpcomingBenefits::TONE_INFO,
                    'at' => $nextDate,
                    'plan' => 'SAMPLE-1',
                    'amount' => 89.0,
                    'points' => null,
                    'remaining' => null,
                    'label' => __('account.sample.product'),
                ],
                [
                    'kind' => UpcomingBenefits::KIND_INTRO_ENDING,
                    'tone' => UpcomingBenefits::TONE_WARN,
                    'at' => now()->addDays(39)->toDateString(),
                    'plan' => 'SAMPLE-1',
                    'amount' => 119.0,
                    'points' => null,
                    'remaining' => null,
                    'label' => '',
                ],
                [
                    'kind' => UpcomingBenefits::KIND_BIRTHDAY_POINTS,
                    'tone' => UpcomingBenefits::TONE_GOOD,
                    'at' => now()->addDays(64)->toDateString(),
                    'plan' => null,
                    'amount' => null,
                    'points' => 250,
                    'remaining' => null,
                    'label' => '',
                ],
            ],
            'benefits' => [
                [
                    'label' => __('account.active.intro_price', ['now' => '89 ILS', 'was' => '119 ILS']),
                    'note' => __('account.active.intro_left', ['count' => 1]),
                ],
            ],
            'loyalty' => $loyaltySettings->enabled
                ? $this->compactLoyalty(app(LoyaltyPagePresenter::class)->sample($loyaltySettings))
                : null,
            'payment_methods' => [[
                'brand' => 'visa',
                'last_four' => '4242',
                'expires' => '09/29',
                'active' => true,
            ]],
            'offers' => $offers[AccountOffer::PLACEMENT_TOP],
            'rail_offers' => $offers[AccountOffer::PLACEMENT_RAIL],
        ];
    }

    /**
     * The merchant's REAL offers, drawn against the sample subscription.
     *
     * Split by placement but NOT filtered by audience, window or the shop's
     * charging switch — the same choice everyBanner() makes, and for the same
     * reason: the sample shopper is nobody in particular, so a merchant who
     * cannot see the offer they just wrote concludes it is broken and writes it
     * again. Nothing here can be clicked; the renderer draws the preview inert.
     *
     * Offers whose template cannot be priced are still skipped. That is not
     * targeting, it is the offer genuinely having nothing to sell, and hiding it
     * from the preview is exactly the feedback the merchant needs.
     *
     * @return array{top: list<array<string, mixed>>, rail: list<array<string, mixed>>, plan: list<array<string, mixed>>}
     */
    private function everyOffer(string $nextDate): array
    {
        $out = [
            AccountOffer::PLACEMENT_TOP => [],
            AccountOffer::PLACEMENT_RAIL => [],
            AccountOffer::PLACEMENT_PLAN => [],
        ];

        $shop = Tenant::current();
        if ($shop === null) {
            return $out;
        }

        $renewal = Carbon::parse($nextDate);

        foreach ($this->liveOffers() as $offer) {
            $pairs = [];
            foreach ($offer->orderedTargets() as $target) {
                $quote = AccountOfferQuote::forTarget($target, null, $shop);
                if ($quote !== null) {
                    $pairs[] = [$target, $quote];
                }
            }

            if ($pairs === []) {
                continue;
            }

            // No source plan: the sample shopper is nobody in particular, so the
            // dates a target promises are measured against the sample card's own
            // renewal rather than against a subscription that does not exist.
            $out[$offer->placement()][] = $this->offers->present(
                $offer,
                $pairs,
                self::SAMPLE_PLAN_ID,
                null,
                $renewal,
            );
        }

        return $out;
    }

    // === Shell (identical for every visitor but the banners) ===

    /**
     * @param  array{banners: list<array<string, mixed>>, top_banners: list<array<string, mixed>>}  $banners
     * @return array<string, mixed>
     */
    private function shell(MerchantPortalAppearance $settings, MerchantLoyaltySettings $loyalty, array $banners): array
    {
        $sections = $settings->visibleSections();

        // A loyalty section on a shop with no club is an empty promise; drop it
        // here rather than teaching the renderer about program state.
        if (! $loyalty->enabled) {
            $sections = array_values(array_filter(
                $sections,
                static fn (string $key): bool => $key !== MerchantPortalAppearance::SECTION_LOYALTY,
            ));
        }

        return [
            'sections' => $sections,
            'appearance' => [
                'accent' => $settings->accentColor(),
                'accent_text' => $settings->accentTextColor(),
                'theme' => $settings->themeMode(),
                'radius' => $settings->cornerRadiusPx(),
                'density' => $settings->density(),
                'card' => $settings->cardStyle(),
                'font' => $settings->fontFamily(),
                // The language this payload was RESOLVED in, not the merchant's
                // setting: the plugin has chrome of its own (the nav label, the
                // sign-in panel) and it has to speak whatever the copy below
                // ended up speaking, including when the choice was "follow the
                // store" and the store answered.
                'locale' => app()->getLocale(),
                'dir' => in_array(app()->getLocale(), ['he', 'ar'], true) ? 'rtl' : 'ltr',
            ],
            // `banners` keeps its name and its shape: it is the SIDE RAIL, which
            // is what every plugin build already deployed renders from it. The
            // full-width strip arrives beside it under a new key, so an old
            // renderer simply draws nothing new instead of breaking.
            'banners' => $banners['banners'],
            'top_banners' => $banners['top_banners'],
            // Read by the plugin's login form, which needs to know whether to draw
            // the code panel at all — including for a logged-OUT caller, which is
            // why it rides the shell rather than the identified half of the payload.
            'login' => [
                'enabled' => $settings->loginCodeEnabled(),
                'channel' => $settings->loginCodeChannel(),
                // A client id is public by design (it ships inside Google's own
                // button markup on every site that uses it); the plugin still
                // verifies every credential's `aud` against it server-side.
                'google_client_id' => $settings->loginGoogleClientId(),
            ],
            'support' => [
                'email' => $settings->supportEmail() ?? MerchantBillingSettings::current()->supportEmail(),
                'url' => $settings->supportUrl(),
            ],
            // Purchase rules the STOREFRONT enforces, not the account page. They
            // ride the shell because the shell is what the plugin already caches:
            // the per-customer question ("does this one have a subscription?")
            // costs a round trip, and asking it on every add-to-cart in a shop
            // that never turned the rule on would be a round trip for nothing.
            'purchase' => [
                'single_subscription' => MerchantBillingSettings::current()->allowsOneSubscriptionOnly(),
            ],
            'copy' => $this->copy($settings),
        ];
    }

    // === Banners ===

    /**
     * The two banner slots, targeted at this reader. Both are always present, so
     * the renderer never has to ask whether a key exists.
     *
     * @return array{banners: list<array<string, mixed>>, top_banners: list<array<string, mixed>>}
     */
    private function banners(MerchantPortalAppearance $settings, ?bool $isSubscriber): array
    {
        return [
            'banners' => $settings->bannersFor(MerchantPortalAppearance::BANNER_RAIL, $isSubscriber),
            'top_banners' => $settings->bannersFor(MerchantPortalAppearance::BANNER_TOP, $isSubscriber),
        ];
    }

    /**
     * Split by placement, shown to everyone — the admin preview only.
     *
     * @return array{banners: list<array<string, mixed>>, top_banners: list<array<string, mixed>>}
     */
    private function everyBanner(MerchantPortalAppearance $settings): array
    {
        return [
            'banners' => $settings->bannersAt(MerchantPortalAppearance::BANNER_RAIL),
            'top_banners' => $settings->bannersAt(MerchantPortalAppearance::BANNER_TOP),
        ];
    }

    /**
     * Does this reader hold a live subscription? A plan that is merely queued or
     * long cancelled does not make one.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     */
    private function isSubscriber(Collection $plans): bool
    {
        foreach ($plans as $plan) {
            $status = $plan->status instanceof PlanStatus
                ? $plan->status
                : PlanStatus::tryFrom((string) $plan->status);

            if ($status !== null && in_array($status, self::SUBSCRIBER_STATUSES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every user-facing string the renderer needs, resolved once.
     *
     * The merchant's own heading wins where they set one; everything else comes
     * from lang/{en,he}/account.php.
     *
     * @return array<string, string>
     */
    private function copy(MerchantPortalAppearance $settings): array
    {
        $keys = [
            'welcome_heading', 'welcome_subtext', 'subscriptions_heading', 'upcoming_heading',
            'benefits_heading', 'loyalty_heading', 'orders_heading', 'documents_heading',
            'profile_heading', 'addresses_heading', 'support_heading', 'empty_subscriptions',
            'empty_upcoming', 'next_charge', 'every', 'status', 'payment_method', 'no_card',
            'action_pause', 'action_resume', 'action_cancel', 'action_skip',
            'action_reschedule', 'action_items', 'action_update_card', 'confirm_cancel',
            'points_balance', 'points_worth', 'tier', 'sign_in_prompt', 'sign_in_cta',
            'saved', 'failed', 'loading', 'paid_of', 'remaining', 'payments_heading',
            'receipt_label',
            ...self::OFFER_COPY_KEYS,
        ];

        $copy = [];
        foreach ($keys as $key) {
            $copy[$key] = __('account.ui.'.$key);
        }

        // Merchant overrides last so they always win.
        $copy['welcome_heading'] = $settings->welcomeHeading() ?? $copy['welcome_heading'];
        $copy['welcome_subtext'] = $settings->welcomeSubtext() ?? $copy['welcome_subtext'];

        foreach ($this->benefitKinds() as $kind) {
            $copy['benefit_'.$kind] = __('account.benefit.'.$kind);
        }

        foreach (CustomerSubscriptionActions::ACTIONS as $action) {
            $copy['result_'.$action] = __('account.result.'.$action);
        }

        // accept_offer is the one verb whose failures each need their own
        // sentence: "your card was declined" and "you already took this" are not
        // the same news, and the generic failure toast would say neither.
        foreach (CustomerSubscriptionActions::OFFER_RESULTS as $result) {
            $key = 'result_'.CustomerSubscriptionActions::ACTION_ACCEPT_OFFER.'_'.$result;
            $copy[$key] = __('account.result.'.CustomerSubscriptionActions::ACTION_ACCEPT_OFFER.'_'.$result);
        }

        // Plan and payment statuses share the `status_*` prefix: the renderer
        // looks up whatever value the server sent and falls back to the raw
        // string, so a status added later degrades to readable rather than blank.
        foreach (PlanStatus::cases() as $status) {
            $copy['status_'.$status->value] = __('account.status.'.$status->value);
        }
        foreach (PaymentStatus::cases() as $status) {
            $copy['status_'.$status->value] = __('account.status.'.$status->value);
        }

        return $copy;
    }

    /** @return list<string> */
    private function benefitKinds(): array
    {
        return [
            UpcomingBenefits::KIND_NEXT_DELIVERY,
            UpcomingBenefits::KIND_NEXT_ORDER_EXTRA,
            UpcomingBenefits::KIND_INTRO_ENDING,
            UpcomingBenefits::KIND_PLAN_COMPLETES,
            UpcomingBenefits::KIND_BIRTHDAY_POINTS,
            UpcomingBenefits::KIND_TIER_PROGRESS,
            UpcomingBenefits::KIND_REDEEM_READY,
        ];
    }

    // === Subscriptions ===

    /**
     * The order a subscriber reads their own plans in: the LIVE one first.
     *
     * Newest-first put an abandoned "awaiting first payment" card — a checkout that
     * was never finished — above the subscription the customer is actually paying
     * for, which reads as though the working one is the leftover. Within a rank the
     * newest still wins, so the query's order is preserved by the stable sort.
     *
     * @param  Collection<int, InstallmentPlan>  $plans
     * @return Collection<int, InstallmentPlan>
     */
    private function inReadingOrder(Collection $plans): Collection
    {
        return $plans
            ->sortBy(
                fn (InstallmentPlan $plan): int => self::STATUS_ORDER[
                    $plan->status instanceof PlanStatus ? $plan->status->value : (string) $plan->status
                ] ?? self::STATUS_ORDER_DEFAULT,
                SORT_REGULAR,
                false,
            )
            ->values();
    }

    /**
     * @param  list<array<string, mixed>>  $offers  cards drawn under THIS card
     * @return array<string, mixed>
     */
    private function plan(InstallmentPlan $plan, array $offers = []): array
    {
        $recurring = $plan->plan_kind === PlanKind::RECURRING;
        $status = $plan->status instanceof PlanStatus ? $plan->status->value : (string) $plan->status;
        $actions = app(CustomerSubscriptionActions::class)->availableFor($plan);

        return [
            'id' => (string) $plan->public_id,
            'kind' => $recurring ? 'recurring' : 'installments',
            'title' => (string) ($plan->itemTitle() ?: $plan->productTitle() ?: ''),
            'status' => $status,
            'tone' => self::STATUS_TONES[$status] ?? self::TONE_ATTENTION,
            // A subscription that is OVER opens folded: it is history, and a
            // shopper with one live plan and three cancelled ones was reading
            // four cards of equal weight to find the one that still bills them.
            // Decided here, not in the renderer, so the merchant's preview and
            // the live page cannot disagree about it.
            'collapsed' => $this->isCollapsed($status),
            'currency' => (string) ($plan->currency ?: ''),
            'currency_symbol' => self::currencySymbol($plan->currency),
            'amount' => $recurring
                ? $this->cycles->amountForCharge($plan, $this->cycles->chargeNumberForNext($plan))
                : round((float) $plan->installment_amount, 2),
            'regular_amount' => $plan->regular_amount !== null ? round((float) $plan->regular_amount, 2) : null,
            'total' => $recurring ? null : round((float) $plan->total_amount, 2),
            'paid' => $recurring ? null : round((float) $plan->total_charged, 2),
            'remaining' => $recurring ? null : round((float) $plan->remainingAmount(), 2),
            'frequency' => $plan->billing_frequency?->value,
            'interval_count' => max(1, (int) $plan->interval_count),
            'cadence' => self::cadence($plan->billing_frequency?->value, (int) $plan->interval_count),
            'next_charge_at' => $plan->next_charge_at instanceof Carbon ? $plan->next_charge_at->toDateString() : null,
            'intro' => $this->cycles->introWindowStatus($plan),
            'next_order' => $this->nextOrderSummary($plan),
            'card' => $this->card($plan->activePaymentMethod()),
            'actions' => array_keys(array_filter($actions)),
            // WHY the exit buttons are missing, when a commitment is what is
            // holding them. A button that simply is not there reads as a bug, or
            // worse as a shop hiding the way out; the sentence says how many
            // more cycles and stops the support call.
            'commitment_note' => $this->commitmentNote($plan),
            'payments' => $this->payments($plan),
            'offers' => $offers,
        ];
    }

    /**
     * "You can cancel after 3 payments (1 so far)" — or null when the subscriber
     * is free to leave, which is every plan sold without a commitment.
     */
    private function commitmentNote(InstallmentPlan $plan): ?string
    {
        $remaining = $plan->cyclesUntilExit();

        if ($remaining === 0) {
            return null;
        }

        return __('account.ui.commitment_note', [
            'required' => max(0, (int) $plan->min_cycles_before_exit),
            'paid' => $plan->paidCycles(),
        ]);
    }

    /**
     * "every month" / "כל חודש" / "כל 3 חודשים" — resolved HERE, not in the browser.
     *
     * The renderer used to hold an English map of frequency → unit, so a Hebrew
     * store read "ILS 1 כל month". Hebrew also needs the plural to agree with the
     * count (חודש / חודשים), which a lookup table in JS cannot express — so the
     * unit is a choice string and the sentence is assembled from lang/{en,he}.
     *
     * Public + static because the offer cards say the same sentence about the
     * same cadences, and two implementations of "every 3 months" is one too many.
     */
    public static function cadence(?string $frequency, int $intervalCount): ?string
    {
        if ($frequency === null || $frequency === '') {
            return null;
        }

        $count = max(1, $intervalCount);
        $unit = trans_choice('account.cycle.unit.'.$frequency, $count);

        // A frequency with no translation of its own must not print the raw key.
        if (! is_string($unit) || str_starts_with($unit, 'account.cycle.')) {
            $unit = $frequency;
        }

        return $count === 1
            ? __('account.cycle.every', ['unit' => $unit])
            : __('account.cycle.every_n', ['count' => $count, 'unit' => $unit]);
    }

    /** The symbol a price is read with, falling back to the currency's own code. */
    public static function currencySymbol(mixed $currency): string
    {
        $code = strtoupper(trim((string) ($currency ?: '')));

        return self::CURRENCY_SYMBOLS[$code] ?? $code;
    }

    /** What is already queued for the next cycle, if anything. */
    private function nextOrderSummary(InstallmentPlan $plan): ?array
    {
        $override = $plan->nextOrderOverride();
        if (! is_array($override) || empty($override['line_items'])) {
            return null;
        }

        $lines = [];
        foreach ((array) $override['line_items'] as $line) {
            $lines[] = [
                'name' => (string) ($line['name'] ?? ''),
                'quantity' => max(1, (int) ($line['quantity'] ?? 1)),
            ];
        }

        return ['lines' => $lines, 'amount' => round((float) ($override['amount'] ?? 0), 2)];
    }

    /**
     * Does this subscription open FOLDED in the personal area?
     *
     * Only the ones that are OVER — cancelled and completed. They cannot change
     * again, so the card is a record rather than a control, and a shopper with
     * one live plan among several dead ones should not have to read all of them
     * to find the one that still takes money.
     *
     * PAUSED, FAILED and AWAITING_FIRST_PAYMENT deliberately stay OPEN even
     * though none of them is "active": each one is a plan waiting on the shopper
     * to do something — resume it, fix a declined card, finish paying — and
     * folding an action away is how a subscription quietly dies.
     */
    private function isCollapsed(string $status): bool
    {
        return (self::STATUS_TONES[$status] ?? null) === self::TONE_ENDED;
    }

    /** @return list<array<string, mixed>> */
    private function payments(InstallmentPlan $plan): array
    {
        $receipts = $this->receiptUrls($plan);

        return $plan->payments
            ->sortByDesc('sequence')
            ->take(self::MAX_PAYMENTS)
            ->map(fn ($payment): array => [
                'sequence' => (int) $payment->sequence,
                'amount' => round((float) $payment->amount, 2),
                'status' => $payment->status instanceof PaymentStatus
                    ? $payment->status->value
                    : (string) $payment->status,
                'at' => $payment->charged_at instanceof Carbon ? $payment->charged_at->toDateString() : null,
                // The shopper's own receipt (Green Invoice view link), when the
                // charge produced one. Matched by transaction uid — the one value
                // the payment slot and the ledger row both witnessed — never by
                // date, which collides on a same-day retry.
                'receipt_url' => $payment->payplus_transaction_uid !== null
                    ? ($receipts[(string) $payment->payplus_transaction_uid] ?? null)
                    : null,
            ])
            ->values()
            ->all();
    }

    /**
     * transaction uid → the issued document's view URL, for this plan.
     *
     * Two queries, not a join: BelongsToShop's global scope constrains each
     * model unambiguously on its own, and both tables carry shop_id — a join
     * would make the scope's where ambiguous.
     *
     * @return array<string, string>
     */
    private function receiptUrls(InstallmentPlan $plan): array
    {
        $docs = IssuedDocument::query()
            ->where('plan_id', $plan->getKey())
            ->where('status', IssuedDocument::STATUS_ISSUED)
            ->whereNotNull('document_url')
            ->whereNotNull('ledger_id')
            ->get(['ledger_id', 'document_url']);

        if ($docs->isEmpty()) {
            return [];
        }

        $uidByLedger = PaymentLedger::query()
            ->whereIn('id', $docs->pluck('ledger_id')->all())
            ->whereNotNull('payplus_transaction_uid')
            ->pluck('payplus_transaction_uid', 'id');

        $map = [];
        foreach ($docs as $doc) {
            $uid = $uidByLedger[(int) $doc->ledger_id] ?? null;
            if ($uid !== null) {
                $map[(string) $uid] = (string) $doc->document_url;
            }
        }

        return $map;
    }

    /**
     * The card, masked. Only the four digits, the brand and the expiry ever leave
     * the server; the vault token is hidden on the model and is never shaped here.
     */
    private function card(?InstallmentPaymentMethod $method): ?array
    {
        if ($method === null) {
            return null;
        }

        return [
            'brand' => (string) ($method->card_brand ?: ''),
            'last_four' => (string) ($method->card_last_four ?: ''),
            'expires' => $method->exp_month !== null && $method->exp_year !== null
                ? str_pad((string) $method->exp_month, 2, '0', STR_PAD_LEFT).'/'.substr((string) $method->exp_year, -2)
                : null,
            'active' => $method->isActive(),
        ];
    }

    /** The distinct cards behind this shopper's plans, deduped by last four. */
    private function paymentMethods(iterable $plans): array
    {
        $out = [];
        foreach ($plans as $plan) {
            $card = $this->card($plan->activePaymentMethod());
            if ($card === null) {
                continue;
            }
            $out[$card['brand'].$card['last_four']] = $card;
        }

        return array_values($out);
    }

    // === Loyalty ===

    /**
     * The club, as much of it as a personal-area card needs. The full members page
     * stays where it is; duplicating its presenter here would give the shop two
     * places that can disagree about a balance.
     */
    private function loyalty(AccountVisitor $visitor, MerchantLoyaltySettings $settings): ?array
    {
        if (! $settings->enabled || ! $visitor->isIdentified()) {
            return null;
        }

        $model = app(LoyaltyPagePresenter::class)->present(LoyaltyVisitor::make(
            shop: $visitor->shop,
            customerRef: $visitor->customerRef,
            source: LoyaltyVisitor::SOURCE_SIGNED,
            email: $visitor->email,
            name: $visitor->name,
        ));

        return $this->compactLoyalty($model);
    }

    /** @param array<string, mixed> $model */
    private function compactLoyalty(array $model): array
    {
        return [
            'program_name' => $model['program_name'] ?? null,
            'is_member' => (bool) ($model['is_member'] ?? false),
            'balance' => $model['balance'] ?? null,
            'status' => $model['status'] ?? null,
            'referral' => $model['referral'] ?? null,
        ];
    }

    // === Sample data ===

    /** @return array<string, mixed> */
    /**
     * The verbs the sample card offers — the merchant's switches, applied to an
     * imaginary ACTIVE recurring plan (the state in which every verb is legal).
     *
     * @return list<string>
     */
    private function sampleActions(): array
    {
        $settings = MerchantBillingSettings::current();

        return array_values(array_filter([
            $settings->allowsCustomerSkip() ? CustomerSubscriptionActions::ACTION_SKIP : null,
            $settings->allowsCustomerReschedule() ? CustomerSubscriptionActions::ACTION_RESCHEDULE : null,
            $settings->allowsCustomerEditItems() ? CustomerSubscriptionActions::ACTION_ITEMS : null,
            $settings->allowsCustomerPause() ? CustomerSubscriptionActions::ACTION_PAUSE : null,
            $settings->allowsCustomerCancel() ? CustomerSubscriptionActions::ACTION_CANCEL : null,
        ]));
    }

    /** @param list<array<string, mixed>> $offers */
    private function sampleSubscription(string $nextDate, array $offers = []): array
    {
        return [
            'id' => self::SAMPLE_PLAN_ID,
            'kind' => 'recurring',
            'title' => __('account.sample.product'),
            'status' => 'active',
            'tone' => self::TONE_ACTIVE,
            // The sample plan is live, so the preview shows a card the way a
            // merchant's paying subscribers see theirs — open.
            'collapsed' => false,
            'currency' => 'ILS',
            'currency_symbol' => self::currencySymbol('ILS'),
            'amount' => 89.0,
            'regular_amount' => 119.0,
            'total' => null,
            'paid' => null,
            'remaining' => null,
            'frequency' => 'monthly',
            'interval_count' => 1,
            'cadence' => self::cadence('monthly', 1),
            'next_charge_at' => $nextDate,
            'intro' => ['used' => 2, 'total' => 3],
            'next_order' => null,
            'card' => ['brand' => 'visa', 'last_four' => '4242', 'expires' => '09/29', 'active' => true],
            // The preview obeys the merchant's own switches: a shop that turned
            // "skip" off must not see a sample card offering it.
            'actions' => $this->sampleActions(),
            // The sample subscriber is long past any commitment — a preview that
            // showed the note would be describing a plan the merchant is not
            // looking at.
            'commitment_note' => null,
            'payments' => [
                ['sequence' => 2, 'amount' => 89.0, 'status' => 'succeeded', 'at' => now()->subDays(21)->toDateString(), 'receipt_url' => null],
                ['sequence' => 1, 'amount' => 89.0, 'status' => 'succeeded', 'at' => now()->subDays(51)->toDateString(), 'receipt_url' => null],
            ],
            'offers' => $offers,
        ];
    }
}
