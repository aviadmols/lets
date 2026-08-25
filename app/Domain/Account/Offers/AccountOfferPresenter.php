<?php

namespace App\Domain\Account\Offers;

use App\Domain\Account\AccountPresenter;
use App\Models\AccountOffer;
use App\Models\AccountOfferTarget;
use App\Models\InstallmentPlan;
use Illuminate\Support\Carbon;

/**
 * One offer, as the renderer receives it: a promotion, plus the list of things
 * it sells.
 *
 * ONE payload, TWO callers — the live page and the merchant's preview — which is
 * the discipline that keeps the preview honest: a field the preview cannot
 * fabricate is a field the live page should not be inventing either.
 *
 * THE SHAPE. Everything about the PROMOTION (heading, subtext, image, custom
 * HTML, placement) sits at the top level; everything about a CHOICE (kind, mode,
 * timing, price, cadence, dates, button text, disclosure) sits inside a target.
 * That split is what lets one card carry "switch to monthly" beside "add the
 * mug": the shopper reads one promotion and picks one of its answers.
 *
 * Each target carries a `key`, which is the ONLY thing the browser posts back.
 * It is the merchant's slug where they set one, else the product id, else the
 * position — never a database id, because the target rows are rewritten on every
 * edit and a key that changes under a loaded page is a click that lands on the
 * wrong thing.
 *
 * The money is never recomputed here. It arrives already resolved in
 * AccountOfferQuote, from the merchant's own template or their own catalog, and
 * the same object prices what a click creates.
 *
 * CUSTOM HTML is the one place in the personal area where markup crosses into
 * the browser, so the order of operations is load-bearing:
 *
 *   1. SafeHtml::clean() — an ALLOW-list, applied on the server (already applied
 *      at save time too; this is the copy that reaches the page).
 *   2. strtr() of the merchant's tokens, with every substituted VALUE escaped —
 *      strtr and never Blade::render, the same rule the email templates live
 *      under, because merchant input compiled as a template is remote code
 *      execution.
 *   3. Each button token becomes an inert sentinel span carrying its target's
 *      key, which the renderer replaces with a control IT wired. A merchant can
 *      decide where each button goes; they can never decide what it does.
 */
final class AccountOfferPresenter
{
    // === CONSTANTS ===
    /** The disclosure a saved-token charge always carries (docs/ux/60). */
    public const COPY_DISCLOSURE_NOW = 'account.ui.offer_disclosure_now';

    public const COPY_DISCLOSURE_NOW_REPLACE = 'account.ui.offer_disclosure_now_replace';

    public const COPY_DISCLOSURE_LATER = 'account.ui.offer_disclosure_later';

    public const COPY_DISCLOSURE_LATER_REPLACE = 'account.ui.offer_disclosure_later_replace';

    /** A prorated switch: the one-off difference now, the full price from :date. */
    public const COPY_DISCLOSURE_PRORATED_REPLACE = 'account.ui.offer_disclosure_prorated_replace';

    /** A one-time product bought on the click. */
    public const COPY_DISCLOSURE_BUY_NOW = 'account.ui.offer_disclosure_buy_now';

    /** A one-time product that rides the next recurring order. No money today. */
    public const COPY_DISCLOSURE_NEXT_ORDER = 'account.ui.offer_disclosure_next_order';

    /**
     * One payload, whoever is asking.
     *
     * $pairs are the OFFERABLE targets only, in position order — the caller has
     * already asked eligibility which of them are on sale for this shopper, so a
     * target that cannot be bought never reaches the page with a button on it.
     *
     * $source is the subscription the offer is taken FROM. The preview has no
     * real one, so it passes null plus the sample card's public id and a
     * renewal date to promise — which is why this takes the id separately.
     *
     * @param  list<array{0: AccountOfferTarget, 1: AccountOfferQuote}>  $pairs
     * @return array<string, mixed>
     */
    public function present(
        AccountOffer $offer,
        array $pairs,
        string $sourcePublicId,
        ?InstallmentPlan $source = null,
        ?Carbon $renewalFallback = null,
    ): array {
        $targets = [];
        $index = 0;

        foreach ($pairs as [$target, $quote]) {
            $targets[] = $this->target($target, $quote, ++$index, $source, $renewalFallback);
        }

        $first = $targets[0] ?? null;

        return [
            'id' => (string) $offer->getKey(),
            'placement' => $offer->placement(),
            'heading' => $offer->heading(),
            'subtext' => $offer->subtext(),
            'image_url' => $offer->imageUrl(),
            'html' => $this->html($offer, $pairs, $first),
            // The block's own stylesheet, SafeCss-cleaned (null when empty). The
            // renderer assigns it to a <style> element's textContent — never
            // parsed as HTML — and the admin preview writes the same string, so
            // the card the merchant sees is the card the shopper gets.
            'css' => $offer->customCss(),
            'source_plan' => $sourcePublicId,
            'targets' => $targets,
        ];
    }

    /**
     * When a SUBSCRIPTION target's first charge falls — the same answer the accept
     * service will reach, so the card cannot promise one date and the subscription
     * be born with another. Null for a one-time target: it has no schedule.
     */
    public function firstChargeAt(
        AccountOfferTarget $target,
        ?InstallmentPlan $source,
        ?Carbon $renewalFallback = null,
    ): ?Carbon {
        if ($target->isOneTime()) {
            return null;
        }

        $today = now()->startOfDay();

        // A PRORATED switch's meaningful date is when the FULL price starts —
        // the old renewal — even though the difference is charged today; the
        // disclosure names both. Everything else that charges now starts now.
        if ($target->chargesNow() && $target->timing() !== AccountOfferTarget::TIMING_PRORATED) {
            return $today;
        }

        $renewal = $this->renewal($source, $renewalFallback);

        return $renewal !== null && $renewal->greaterThan($today) ? $renewal : $today;
    }

    /**
     * The date a NEXT-ORDER add-on rides along on — the source subscription's own
     * next charge. Null for anything else, including a next-order target on a
     * subscription with no scheduled charge (which is exactly why eligibility
     * refuses to offer one: there is nothing to ride).
     */
    public function nextOrderAt(
        AccountOfferTarget $target,
        ?InstallmentPlan $source,
        ?Carbon $renewalFallback = null,
    ): ?Carbon {
        if (! $target->isOneTime() || $target->fulfilment() !== AccountOfferTarget::FULFILMENT_NEXT_ORDER) {
            return null;
        }

        return $this->renewal($source, $renewalFallback);
    }

    // === Private ===

    /**
     * One target, as the renderer receives it.
     *
     * @return array<string, mixed>
     */
    private function target(
        AccountOfferTarget $target,
        AccountOfferQuote $quote,
        int $index,
        ?InstallmentPlan $source,
        ?Carbon $renewalFallback,
    ): array {
        $symbol = AccountPresenter::currencySymbol($quote->currency);
        $priceDisplay = $this->priceDisplay($quote->amount, $symbol);
        $firstChargeAt = $this->firstChargeAt($target, $source, $renewalFallback);
        $nextOrderAt = $this->nextOrderAt($target, $source, $renewalFallback);

        // The prorated one-off, computed by the SAME class the accept service
        // asks — the number on the card and the number that charges can only
        // ever drift DOWN (days shrink), never up.
        $dueNow = ReplaceProration::dueNow($target, $source, $quote->amount);

        return [
            'key' => $target->stableKey(),
            'index' => $index,
            'kind' => $target->kind(),
            'mode' => $target->mode(),
            'timing' => $target->timing(),
            'fulfilment' => $target->fulfilment(),
            'product' => [
                'title' => $quote->itemTitle,
                'image' => $quote->imageUrl,
            ],
            'quantity' => $quote->quantity,
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'currency_symbol' => $symbol,
            'price_display' => $priceDisplay,
            'cadence' => $quote->frequency !== null
                ? (string) AccountPresenter::cadence($quote->frequency->value, (int) $quote->intervalCount)
                : null,
            'first_charge_at' => $firstChargeAt?->toDateString(),
            'next_order_at' => $nextOrderAt?->toDateString(),
            'due_now' => $dueNow,
            'due_now_display' => $dueNow !== null && $dueNow >= ReplaceProration::MIN_CHARGE
                ? $this->priceDisplay($dueNow, $symbol)
                : null,
            'button_text' => $target->buttonText(),
            'disclosure' => $this->disclosure($target, $priceDisplay, $firstChargeAt, $nextOrderAt, $dueNow, $symbol),
        ];
    }

    /**
     * The merchant's custom block, cleaned and with its tokens filled in.
     *
     * Values are escaped BEFORE substitution, so a product title containing a `<`
     * cannot re-open the markup the sanitizer just closed. The button tokens are
     * the one substitution whose replacement is markup, and it is a constant this
     * codebase owns, not anything a merchant typed.
     *
     * The single-value tokens ({{price}}, {{product}}, {{cadence}}) describe the
     * FIRST offerable target. A multi-target block that wants to name each price
     * writes them itself; the merchant who wrote a single-target block before
     * targets became a list keeps the block they had.
     *
     * @param  list<array{0: AccountOfferTarget, 1: AccountOfferQuote}>  $pairs
     * @param  array<string, mixed>|null  $firstCard
     */
    private function html(AccountOffer $offer, array $pairs, ?array $firstCard): ?string
    {
        $clean = $offer->customHtml();

        if ($clean === null) {
            return null;
        }

        $descriptors = array_map(
            static fn (array $pair): array => $pair[0]->descriptor(),
            $pairs,
        );

        $heading = $offer->heading();

        $map = [
            AccountOffer::TOKEN_PRICE => $this->escape((string) ($firstCard['price_display'] ?? '')),
            AccountOffer::TOKEN_PRODUCT => $this->escape((string) ($firstCard['product']['title'] ?? '')),
            AccountOffer::TOKEN_CADENCE => $this->escape((string) ($firstCard['cadence'] ?? '')),
            AccountOffer::TOKEN_HEADING => $this->escape((string) $heading),
        ];

        // The button tokens LAST in the map, but strtr applies the whole map in one
        // pass, so nothing a value contains can be re-read as a token.
        return strtr($clean, $map + OfferButtonTokens::slots($clean, $descriptors));
    }

    /**
     * What the shopper is told before their saved card is used.
     *
     * Mandatory for any saved-token charge: the amount, when it is taken, and —
     * for a replacement — that the subscription they hold today ends. A shopper
     * who is surprised by a charge they authorised is a chargeback either way.
     *
     * The two one-time sentences are the newer half. "Buy now" says the money
     * moves on the click; "next order" says the opposite in as many words, because
     * a shopper who thinks they have just been charged will check their statement
     * and then call.
     */
    private function disclosure(
        AccountOfferTarget $target,
        string $priceDisplay,
        ?Carbon $firstChargeAt,
        ?Carbon $nextOrderAt,
        ?float $dueNow = null,
        string $symbol = '',
    ): string {
        if ($target->isOneTime()) {
            return $target->chargesNow()
                ? (string) __(self::COPY_DISCLOSURE_BUY_NOW, ['amount' => $priceDisplay])
                : (string) __(self::COPY_DISCLOSURE_NEXT_ORDER, [
                    'amount' => $priceDisplay,
                    'date' => $nextOrderAt?->toDateString() ?? now()->toDateString(),
                ]);
        }

        // A PRORATED switch says BOTH numbers. When nothing is chargeable today
        // (a downgrade, a spent period, the admin preview with no real source),
        // "nothing today, the full price from :date" is the honest sentence —
        // and it is exactly the period-end one.
        if ($target->timing() === AccountOfferTarget::TIMING_PRORATED) {
            $date = $firstChargeAt?->toDateString() ?? now()->toDateString();

            if ($dueNow !== null && $dueNow >= ReplaceProration::MIN_CHARGE) {
                return (string) __(self::COPY_DISCLOSURE_PRORATED_REPLACE, [
                    'due' => $this->priceDisplay($dueNow, $symbol),
                    'amount' => $priceDisplay,
                    'date' => $date,
                ]);
            }

            return (string) __(self::COPY_DISCLOSURE_LATER_REPLACE, [
                'amount' => $priceDisplay,
                'date' => $date,
            ]);
        }

        if ($target->chargesNow()) {
            return (string) __(
                $target->isReplace() ? self::COPY_DISCLOSURE_NOW_REPLACE : self::COPY_DISCLOSURE_NOW,
                ['amount' => $priceDisplay],
            );
        }

        return (string) __(
            $target->isReplace() ? self::COPY_DISCLOSURE_LATER_REPLACE : self::COPY_DISCLOSURE_LATER,
            [
                'amount' => $priceDisplay,
                'date' => $firstChargeAt?->toDateString() ?? now()->toDateString(),
            ],
        );
    }

    /** The source subscription's renewal date, or the preview's stand-in. */
    private function renewal(?InstallmentPlan $source, ?Carbon $fallback): ?Carbon
    {
        if ($source?->next_charge_at instanceof Carbon) {
            return $source->next_charge_at->copy()->startOfDay();
        }

        return $fallback?->copy()->startOfDay();
    }

    /** "89 ₪" — a price, not a code beside a number. Trailing zeros dropped. */
    private function priceDisplay(float $amount, string $symbol): string
    {
        $formatted = rtrim(rtrim(number_format($amount, 2, '.', ','), '0'), '.');

        return trim($formatted.' '.$symbol);
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
    }
}
