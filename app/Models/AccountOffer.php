<?php

namespace App\Models;

use App\Domain\Account\Offers\OfferButtonTokens;
use App\Models\Concerns\BelongsToShop;
use App\Modules\PayPlusShopifyInstallments\Enums\BillingFrequency;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanKind;
use App\Modules\PayPlusShopifyInstallments\Enums\PlanStatus;
use App\Support\SafeCss;
use App\Support\SafeHtml;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A PROMOTION the merchant shows inside a customer's own account page.
 *
 * The offer is the wrapper: its name, whether it is switched on, who sees it,
 * when, where on the page, how it looks and how often it has been taken. WHAT it
 * sells lives in account_offer_targets — one offer, several targets, each its own
 * choice ("switch to monthly", "or to the annual saver", "and add the mug").
 *
 * Every merchant-set value is read back through a GUARD, never raw — the same
 * discipline MerchantPortalAppearance follows, and for the same reason: these
 * values are interpolated into a page on the merchant's storefront in front of a
 * signed-in shopper. An unvalidated URL is a hostile-content vector, an
 * unvalidated audience entry is an offer shown to the wrong person, and (on the
 * target) an unvalidated mode is a charge nobody meant to make.
 *
 * The offer never carries a PRICE. Each target reads its money from the
 * merchant's own template (a subscription target) or from the catalog (a
 * one-time target), at display AND at accept time, so the number on the card is
 * the number that is charged.
 */
class AccountOffer extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'account_offers';

    public const STATUS_DRAFT = 'draft';

    public const STATUS_ACTIVE = 'active';

    public const STATUSES = [self::STATUS_DRAFT, self::STATUS_ACTIVE];

    /** Where the card is drawn. `plan` sits under the subscription it targets. */
    public const PLACEMENT_TOP = 'top';

    public const PLACEMENT_RAIL = 'rail';

    public const PLACEMENT_PLAN = 'plan';

    public const PLACEMENTS = [self::PLACEMENT_TOP, self::PLACEMENT_RAIL, self::PLACEMENT_PLAN];

    /**
     * The tokens a merchant may write into custom HTML. A BUTTON token is the
     * only required one — without one the block has no way to be accepted, which
     * is a promotion the shopper cannot act on.
     *
     * `{{button}}` is the first target. `{{button_<slug>}}`, `{{button_<product
     * id>}}` and `{{button_<position>}}` address a specific one — the whole
     * vocabulary, and its resolution order, lives in OfferButtonTokens.
     */
    public const TOKEN_BUTTON = OfferButtonTokens::TOKEN_BUTTON;

    public const TOKEN_PRICE = '{{price}}';

    public const TOKEN_PRODUCT = '{{product}}';

    public const TOKEN_CADENCE = '{{cadence}}';

    public const TOKEN_HEADING = '{{heading}}';

    public const TOKENS = [
        self::TOKEN_BUTTON,
        self::TOKEN_PRICE,
        self::TOKEN_PRODUCT,
        self::TOKEN_CADENCE,
        self::TOKEN_HEADING,
    ];

    /**
     * What a button token becomes on the way out. A sentinel, not a `<button>`:
     * SafeHtml's allow-list has no `button` tag, and it should not — the renderer
     * swaps this span for a real control it wired itself, so the click handler is
     * never something a merchant could have typed. The `%s` is the target's stable
     * key, which is what the click posts back.
     */
    public const BUTTON_SLOT_FORMAT = OfferButtonTokens::SLOT_FORMAT;

    public const BUTTON_SLOT_CLASS = OfferButtonTokens::SLOT_CLASS;

    /** The error key the admin form shows when the block carries no button token. */
    public const ERROR_BUTTON_REQUIRED = 'account_offers.form.html_button_required';

    /**
     * The error key for a button token that names a target this offer does not
     * have — a typo'd slug, or a target the merchant deleted from the list while
     * the HTML still calls for it. Refused at save rather than rendered as a hole:
     * a promotion with a missing button reads to the shopper as a broken page and
     * to the merchant as nothing happening at all.
     */
    public const ERROR_BUTTON_UNKNOWN = 'account_offers.form.html_button_unknown';

    public const MAX_HEADING = 80;

    public const MAX_SUBTEXT = 300;

    public const MAX_URL = 500;

    public const MAX_BUTTON_TEXT = 60;

    public const MAX_NAME = 120;

    /** The custom stylesheet's ceiling — SafeCss's own, named here beside the rest. */
    public const MAX_CUSTOM_CSS = SafeCss::MAX_LENGTH;

    /** The statuses an offer targets when the merchant named none. */
    public const DEFAULT_AUDIENCE_STATUSES = [
        PlanStatus::ACTIVE->value,
        PlanStatus::PAUSED->value,
    ];

    /** The audience filter keys, in the order the admin form draws them. */
    public const AUDIENCE_KEYS = ['plan_kinds', 'frequencies', 'product_ids', 'statuses'];

    /** A shop cannot target more products than a person would ever pick. */
    public const MAX_AUDIENCE_PRODUCTS = 100;

    /** id + shop_id are never mass-assignable; shop_id is auto-stamped. */
    protected $guarded = ['id', 'shop_id'];

    protected function casts(): array
    {
        return [
            'audience' => 'array',
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'last_accepted_at' => 'datetime',
            'priority' => 'integer',
            'accepted_count' => 'integer',
        ];
    }

    /**
     * Merchant HTML — and its CSS — are sanitised on the way IN as well as on
     * the way out.
     *
     * Cleaning on read alone would be enough for safety, but it would leave the
     * raw string in the database — where a future export, a support tool or a
     * different reader could pick it up unscrubbed. Cleaning here means the
     * hostile version never exists at rest.
     */
    protected static function booted(): void
    {
        static::saving(static function (self $offer): void {
            if ($offer->isDirty('custom_html')) {
                $offer->custom_html = SafeHtml::clean($offer->custom_html);
            }

            if ($offer->isDirty('custom_css')) {
                $offer->custom_css = SafeCss::clean($offer->custom_css);
            }
        });
    }

    // === Relations ===

    /**
     * What this offer sells, in the order the merchant arranged it.
     *
     * Ordered by position at the RELATION, not at every call site: position is
     * also the fallback addressing scheme ({{button_2}}) and the order the cards
     * are drawn in, so a caller that forgot to sort would silently rename every
     * button.
     */
    public function targets(): HasMany
    {
        return $this->hasMany(AccountOfferTarget::class, 'offer_id')
            ->orderBy('position')
            ->orderBy('id');
    }

    // === Scopes ===

    /** Offers the merchant has switched on. The date window is a separate question. */
    public function scopeOpen(Builder $query): Builder
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }

    // === Guarded reads ===

    public function isActive(): bool
    {
        return $this->oneOf($this->status, self::STATUSES, self::STATUS_DRAFT) === self::STATUS_ACTIVE;
    }

    /**
     * The targets, loaded and in order. Every reader goes through here so a
     * relation that happens to be loaded unsorted cannot renumber the buttons.
     *
     * @return list<AccountOfferTarget>
     */
    public function orderedTargets(): array
    {
        $targets = $this->relationLoaded('targets') ? $this->targets : $this->targets()->get();

        return $targets
            ->sortBy([['position', 'asc'], ['id', 'asc']])
            ->values()
            ->all();
    }

    /**
     * The target a click names, or null.
     *
     * Resolved in PHP over the loaded list and never in SQL: the key is a string
     * from an untrusted request body, and `account_offer_targets.id` is a bigint
     * that Postgres would abort the whole statement over rather than simply not
     * match (sqlite, which the tests run on, shrugs — which is exactly how such a
     * bug ships).
     *
     * An EMPTY key means the first target. That is not a shim: `{{button}}` means
     * the same thing, and a renderer that has not been taught about targets yet
     * is asking for the offer's primary choice.
     */
    public function targetByKey(string $key): ?AccountOfferTarget
    {
        $targets = $this->orderedTargets();
        if ($targets === []) {
            return null;
        }

        $descriptors = array_map(static fn (AccountOfferTarget $t): array => $t->descriptor(), $targets);
        $resolved = OfferButtonTokens::resolve(trim($key), $descriptors);

        if ($resolved === null) {
            return null;
        }

        foreach ($targets as $target) {
            if ($target->stableKey() === $resolved['stable_key']) {
                return $target;
            }
        }

        return null;
    }

    public function placement(): string
    {
        return $this->oneOf($this->placement, self::PLACEMENTS, self::PLACEMENT_PLAN);
    }

    /**
     * The audience filters, sanitised. Every list is INCLUSIVE and an empty list
     * means "any" — so a filter that loses all its values stops narrowing rather
     * than hiding the offer from everyone.
     *
     * @return array{plan_kinds: list<string>, frequencies: list<string>, product_ids: list<string>, statuses: list<string>}
     */
    public function audience(): array
    {
        $raw = is_array($this->audience) ? $this->audience : [];

        return [
            'plan_kinds' => $this->enumValues($raw['plan_kinds'] ?? [], PlanKind::class),
            'frequencies' => $this->enumValues($raw['frequencies'] ?? [], BillingFrequency::class),
            'product_ids' => $this->productIds($raw['product_ids'] ?? []),
            'statuses' => $this->enumValues($raw['statuses'] ?? [], PlanStatus::class)
                ?: self::DEFAULT_AUDIENCE_STATUSES,
        ];
    }

    public function heading(): ?string
    {
        return $this->trimmedOrNull($this->heading, self::MAX_HEADING);
    }

    public function subtext(): ?string
    {
        return $this->trimmedOrNull($this->subtext, self::MAX_SUBTEXT);
    }

    public function buttonText(): ?string
    {
        return $this->trimmedOrNull($this->button_text, self::MAX_BUTTON_TEXT);
    }

    /** Only https survives — a customer page must not carry a hostile scheme. */
    public function imageUrl(): ?string
    {
        $value = is_string($this->image_url) ? trim($this->image_url) : '';
        if ($value === '' || ! str_starts_with(strtolower($value), 'https://')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_URL) !== false
            ? mb_substr($value, 0, self::MAX_URL)
            : null;
    }

    /**
     * The merchant's custom block, scrubbed AGAIN on read.
     *
     * The saving hook already cleaned it, so this is belt and braces — but the
     * belt is what a row written before this class existed (an import, a manual
     * SQL fix, a restored backup) has to pass through too.
     */
    public function customHtml(): ?string
    {
        return SafeHtml::clean($this->custom_html);
    }

    /**
     * The block's own stylesheet, scrubbed AGAIN on read — the same belt and
     * braces as customHtml(), for the same rows written before this class knew
     * about them.
     */
    public function customCss(): ?string
    {
        return SafeCss::clean($this->custom_css);
    }

    /** Is the merchant's date window open at this instant? No window = always. */
    public function isOpenAt(CarbonInterface $now): bool
    {
        if ($this->starts_at !== null && $now->lessThan($this->starts_at)) {
            return false;
        }

        return ! ($this->ends_at !== null && $now->greaterThan($this->ends_at));
    }

    // === Validation (called by the admin form) ===

    /**
     * Is this custom HTML usable? Returns the translation key + parameters of
     * what is wrong, or null when the block is fine (including when it is empty —
     * a merchant who wrote no custom HTML gets the designed cards instead).
     *
     * TWO RULES, and the second is what multiple targets bought us:
     *
     *   1. AT LEAST ONE button token, in TEXT position. A block with none cannot
     *      be accepted by the shopper, which is a promotion nobody can act on.
     *      Several are now legitimate — that is the whole point of a multi-target
     *      offer — but they must be tokens the page can render, so the count is
     *      taken after strip_tags: a token hidden inside an attribute would never
     *      become a button and must not pass for one.
     *
     *   2. EVERY button token must name a real target. `{{button_upgade}}` is a
     *      typo the merchant will otherwise discover from a shopper who could not
     *      buy the thing. Checked against the tokens in the CLEANED block, so a
     *      token the sanitizer removed is not held against them.
     *
     * @param  list<array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}>  $targets
     *                                                                                                                     descriptors, in position order (AccountOfferTarget::descriptor())
     * @return array{key: string, params: array<string, string>}|null
     */
    public static function validateCustomHtml(?string $html, array $targets = []): ?array
    {
        if (! is_string($html) || trim($html) === '') {
            return null;
        }

        $clean = SafeHtml::clean($html);

        if ($clean === null) {
            return ['key' => self::ERROR_BUTTON_REQUIRED, 'params' => []];
        }

        // Rule 1 — a button the shopper can actually see.
        if (OfferButtonTokens::tokensIn(strip_tags($clean)) === []) {
            return ['key' => self::ERROR_BUTTON_REQUIRED, 'params' => []];
        }

        // Rule 2 — every token names something.
        foreach (OfferButtonTokens::tokensIn($clean) as $token) {
            if (OfferButtonTokens::resolve($token['key'], $targets) === null) {
                return ['key' => self::ERROR_BUTTON_UNKNOWN, 'params' => ['token' => $token['token']]];
            }
        }

        return null;
    }

    /**
     * The same check, as the SENTENCE a form field shows. Separate from the
     * structured answer above because a validator wants the message while a test
     * wants to know WHICH rule refused it.
     *
     * @param  list<array{token_key: ?string, external_product_id: ?string, position: int, stable_key: string}>  $targets
     */
    public static function customHtmlError(?string $html, array $targets = []): ?string
    {
        $error = self::validateCustomHtml($html, $targets);

        return $error === null ? null : (string) __($error['key'], $error['params']);
    }

    // === Private guards ===

    /** @param list<string> $allowed */
    private function oneOf(mixed $value, array $allowed, string $fallback): string
    {
        $value = is_string($value) ? $value : '';

        return in_array($value, $allowed, true) ? $value : $fallback;
    }

    private function trimmedOrNull(mixed $value, int $max): ?string
    {
        $value = is_string($value) ? trim($value) : '';

        return $value !== '' ? mb_substr($value, 0, $max) : null;
    }

    /**
     * Keep only the values that are real cases of $enum, deduped, in order.
     *
     * @param  class-string<\BackedEnum>  $enum
     * @return list<string>
     */
    private function enumValues(mixed $raw, string $enum): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = (string) $value;

            if ($enum::tryFrom($value) !== null && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
        }

        return $out;
    }

    /**
     * Platform product ids, as STRINGS. Never cast to int: a WooCommerce id is
     * numeric but a Shopify one is not necessarily, and the plan column these are
     * compared against is a string too.
     *
     * Deduped by VALUE and not by array key — a key of "2666" becomes the integer
     * 2666 the moment PHP stores it, and the comparison downstream is strict, so
     * a whole product filter would silently match nothing.
     *
     * @return list<string>
     */
    private function productIds(mixed $raw): array
    {
        $out = [];

        foreach ((array) $raw as $value) {
            if (! is_string($value) && ! is_int($value)) {
                continue;
            }
            $value = trim((string) $value);
            if ($value !== '' && ! in_array($value, $out, true)) {
                $out[] = $value;
            }
            if (count($out) >= self::MAX_AUDIENCE_PRODUCTS) {
                break;
            }
        }

        return $out;
    }
}
