<?php

namespace App\Domain\Account\Offers;

use App\Domain\Account\AccountPresenter;
use App\Models\AccountOffer;
use App\Models\InstallmentPlan;
use Illuminate\Support\Carbon;

/**
 * One offer, as the renderer receives it.
 *
 * ONE payload, TWO callers — the live page and the merchant's preview — which is
 * the discipline that keeps the preview honest: a field the preview cannot
 * fabricate is a field the live page should not be inventing either.
 *
 * The money is never recomputed here. It arrives already resolved in
 * AccountOfferQuote, from the merchant's own template, and the same object
 * prices the plan that a click creates.
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
 *   3. `{{button}}` becomes an inert sentinel span, which the renderer replaces
 *      with a control IT wired. A merchant can decide where the button goes;
 *      they can never decide what it does.
 */
final class AccountOfferPresenter
{
    // === CONSTANTS ===
    /** The disclosure a saved-token charge always carries (docs/ux/60). */
    public const COPY_DISCLOSURE_NOW = 'account.ui.offer_disclosure_now';

    public const COPY_DISCLOSURE_NOW_REPLACE = 'account.ui.offer_disclosure_now_replace';

    public const COPY_DISCLOSURE_LATER = 'account.ui.offer_disclosure_later';

    public const COPY_DISCLOSURE_LATER_REPLACE = 'account.ui.offer_disclosure_later_replace';

    /**
     * One payload, whoever is asking.
     *
     * $source is the subscription the offer is taken FROM. The preview has no
     * real one, so it passes the sample card's public id as a string instead —
     * which is why this takes the id and not the model.
     *
     * @return array<string, mixed>
     */
    public function present(
        AccountOffer $offer,
        AccountOfferQuote $quote,
        string $sourcePublicId,
        ?Carbon $firstChargeAt = null,
    ): array {
        $symbol = AccountPresenter::currencySymbol($quote->currency);
        $priceDisplay = $this->priceDisplay($quote->amount, $symbol);
        $cadence = (string) AccountPresenter::cadence($quote->frequency->value, $quote->intervalCount);
        $heading = $offer->heading();

        return [
            'id' => (string) $offer->getKey(),
            'placement' => $offer->placement(),
            'mode' => $offer->mode(),
            'timing' => $offer->timing(),
            'product' => [
                'title' => $quote->itemTitle,
                'image' => $quote->imageUrl,
            ],
            'amount' => $quote->amount,
            'currency' => $quote->currency,
            'currency_symbol' => $symbol,
            'price_display' => $priceDisplay,
            'cadence' => $cadence,
            'first_charge_at' => $firstChargeAt?->toDateString(),
            'heading' => $heading,
            'subtext' => $offer->subtext(),
            'image_url' => $offer->imageUrl(),
            'button_text' => $offer->buttonText(),
            'html' => $this->html($offer, $priceDisplay, $quote->itemTitle, $cadence, $heading),
            'source_plan' => $sourcePublicId,
            'disclosure' => $this->disclosure($offer, $priceDisplay, $firstChargeAt),
        ];
    }

    /**
     * The date the first charge falls, for a real source plan — the same answer
     * the accept service will reach, so the card cannot promise one date and the
     * subscription be born with another.
     */
    public function firstChargeAt(AccountOffer $offer, ?InstallmentPlan $source): Carbon
    {
        $today = now()->startOfDay();

        if ($offer->isImmediate()) {
            return $today;
        }

        $renewal = $source?->next_charge_at instanceof Carbon
            ? $source->next_charge_at->copy()->startOfDay()
            : null;

        return $renewal !== null && $renewal->greaterThan($today) ? $renewal : $today;
    }

    // === Private ===

    /**
     * The merchant's custom block, cleaned and with its tokens filled in.
     *
     * Values are escaped BEFORE substitution, so a product title containing a `<`
     * cannot re-open the markup the sanitizer just closed. `{{button}}` is the one
     * substitution whose replacement is markup, and it is a constant this file
     * owns, not anything a merchant typed.
     */
    private function html(
        AccountOffer $offer,
        string $priceDisplay,
        string $title,
        string $cadence,
        ?string $heading,
    ): ?string {
        $clean = $offer->customHtml();

        if ($clean === null) {
            return null;
        }

        return strtr($clean, [
            AccountOffer::TOKEN_PRICE => $this->escape($priceDisplay),
            AccountOffer::TOKEN_PRODUCT => $this->escape($title),
            AccountOffer::TOKEN_CADENCE => $this->escape($cadence),
            AccountOffer::TOKEN_HEADING => $this->escape((string) $heading),
            AccountOffer::TOKEN_BUTTON => AccountOffer::BUTTON_SLOT,
        ]);
    }

    /**
     * What the shopper is told before their saved card is used.
     *
     * Mandatory for any saved-token charge: the amount, when it is taken, and —
     * for a replacement — that the subscription they hold today ends. A shopper
     * who is surprised by a charge they authorised is a chargeback either way.
     */
    private function disclosure(AccountOffer $offer, string $priceDisplay, ?Carbon $firstChargeAt): string
    {
        if ($offer->isImmediate()) {
            return (string) __(
                $offer->isReplace() ? self::COPY_DISCLOSURE_NOW_REPLACE : self::COPY_DISCLOSURE_NOW,
                ['amount' => $priceDisplay],
            );
        }

        return (string) __(
            $offer->isReplace() ? self::COPY_DISCLOSURE_LATER_REPLACE : self::COPY_DISCLOSURE_LATER,
            [
                'amount' => $priceDisplay,
                'date' => $firstChargeAt?->toDateString() ?? now()->toDateString(),
            ],
        );
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
