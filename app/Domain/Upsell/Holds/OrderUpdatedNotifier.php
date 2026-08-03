<?php

namespace App\Domain\Upsell\Holds;

use App\Domain\Upsell\Models\UpsellOrderHold;
use App\Mail\OrderUpdatedMail;
use App\Models\Shop;
use Illuminate\Support\Facades\Mail;

/**
 * "Your order was updated" — sent when an add-on window closes on an order the
 * shopper actually added to.
 *
 * THE ITEMS TABLE IS PRE-RENDERED HERE, into a single {items_table} scalar.
 * TemplateRenderer substitutes scalars with strtr and nothing else — that is the
 * RCE wall — so a merchant-edited body cannot loop over a collection, and
 * handing it one would mean compiling merchant HTML. Building the rows in PHP
 * keeps the wall intact and still lets the merchant place the table wherever
 * they want it in their own copy.
 *
 * Every value that goes into the table is escaped: the product names come from
 * the store, and a merchant-typed product title is not trusted markup.
 */
final class OrderUpdatedNotifier
{
    // === CONSTANTS ===
    /** Inline CSS — the allowed email exception. */
    private const TABLE = 'style="width:100%;border-collapse:collapse;font-size:14px;margin:0 0 16px;"';
    private const TH = 'style="text-align:start;padding:8px 10px;border-bottom:2px solid #e5e7eb;font-size:12px;color:#6b7280;"';
    private const TD = 'style="padding:8px 10px;border-bottom:1px solid #e5e7eb;"';

    /** Beyond this the email is a catalogue, not a confirmation. */
    private const MAX_ROWS = 20;

    public function send(Shop $shop, UpsellOrderHold $hold): bool
    {
        $items = (array) ($hold->added_items ?? []);
        $recipient = $this->recipientFrom($items);

        if ($items === [] || $recipient === null) {
            return false;
        }

        OrderUpdatedMail::mergeMailSettingsIntoConfig($shop);

        Mail::to($recipient)->send(new OrderUpdatedMail(
            shop: $shop,
            orderNumber: (string) $hold->external_order_id,
            itemsTable: $this->table($items),
            addedTotal: $this->total($items),
            currency: $this->currency($items),
            customerName: (string) ($items[0]['customer_name'] ?? ''),
        ));

        return true;
    }

    /**
     * The added lines as one HTML table.
     *
     * @param  array<int, array<string, mixed>>  $items
     */
    private function table(array $items): string
    {
        $rows = '';
        $count = 0;

        foreach ($items as $item) {
            if (! is_array($item) || $count >= self::MAX_ROWS) {
                continue;
            }

            $name = trim((string) ($item['name'] ?? ''));
            if ($name === '') {
                continue;
            }

            $quantity = max(1, (int) ($item['quantity'] ?? 1));
            $price = $this->money($item['price'] ?? 0);

            // e() on every cell: a product title is store data, not our markup.
            $rows .= '<tr>'
                .'<td '.self::TD.'>'.e($name).'</td>'
                .'<td '.self::TD.'>'.e((string) $quantity).'</td>'
                .'<td '.self::TD.'>'.e($price).'</td>'
                .'</tr>';

            $count++;
        }

        if ($rows === '') {
            return '';
        }

        return '<table '.self::TABLE.'><thead><tr>'
            .'<th '.self::TH.'>'.e((string) __('upsell.hold.mail.item')).'</th>'
            .'<th '.self::TH.'>'.e((string) __('upsell.hold.mail.quantity')).'</th>'
            .'<th '.self::TH.'>'.e((string) __('upsell.hold.mail.price')).'</th>'
            .'</tr></thead><tbody>'.$rows.'</tbody></table>';
    }

    /** @param array<int, array<string, mixed>> $items */
    private function total(array $items): string
    {
        $total = 0.0;
        foreach ($items as $item) {
            if (! is_array($item)) {
                continue;
            }
            $total += (float) ($item['price'] ?? 0) * max(1, (int) ($item['quantity'] ?? 1));
        }

        return $this->money($total);
    }

    /** @param array<int, array<string, mixed>> $items */
    private function currency(array $items): string
    {
        foreach ($items as $item) {
            $currency = is_array($item) ? trim((string) ($item['currency'] ?? '')) : '';
            if ($currency !== '') {
                return $currency;
            }
        }

        return (string) config('payplus.currency', 'ILS');
    }

    /** @param array<int, array<string, mixed>> $items */
    private function recipientFrom(array $items): ?string
    {
        foreach ($items as $item) {
            $email = is_array($item) ? trim((string) ($item['customer_email'] ?? '')) : '';
            if (filter_var($email, FILTER_VALIDATE_EMAIL) !== false) {
                return $email;
            }
        }

        return null;
    }

    private function money(mixed $amount): string
    {
        return number_format(round((float) $amount, 2), 2);
    }
}
