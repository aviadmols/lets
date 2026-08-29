<?php

namespace App\Domain\Campaigns\Studio\Render;

use App\Domain\Campaigns\Studio\NewsletterDocument;

/**
 * The same newsletter, as text — for the multipart alternative mail clients
 * and spam filters both want, and for the shopper reading on a watch.
 *
 * Tokens survive verbatim (the text part goes through the SAME strtr as the
 * HTML), and the footer's unsubscribe line is emitted unconditionally for the
 * same reason the HTML footer's is.
 */
final class PlainTextRenderer
{
    // === CONSTANTS ===
    private const LINE = "\n";

    private const GAP = "\n\n";

    public function render(NewsletterDocument $document): string
    {
        $parts = [];

        foreach ($document->blocks() as $block) {
            $text = $this->block($block);
            if ($text !== '') {
                $parts[] = $text;
            }
        }

        return trim(implode(self::GAP, $parts));
    }

    /** @param array{id: string, type: string, content: array<string, mixed>, styles: array<string, mixed>} $block */
    private function block(array $block): string
    {
        $content = $block['content'];

        return match ($block['type']) {
            'heading' => $this->emphasised((string) ($content['text'] ?? '')),
            'text' => $this->stripped((string) ($content['html'] ?? '')),
            'image' => trim((string) ($content['alt'] ?? '')) !== '' && trim((string) ($content['link_url'] ?? '')) !== ''
                ? $content['alt'].': '.$content['link_url']
                : '',
            'button' => trim((string) ($content['label'] ?? '')) !== ''
                ? $content['label'].': '.($content['url'] ?? '')
                : '',
            'hero' => trim(implode(self::LINE, array_filter([
                $this->emphasised((string) ($content['heading'] ?? '')),
                (string) ($content['text'] ?? ''),
                trim((string) ($content['button_label'] ?? '')) !== ''
                    ? $content['button_label'].': '.($content['button_url'] ?? '')
                    : '',
            ]))),
            'coupon' => trim((string) ($content['code'] ?? '')) !== ''
                ? trim(((string) ($content['description'] ?? '')).self::LINE.$content['code'])
                : '',
            'social_links' => implode(self::LINE, array_map(
                static fn (array $link): string => __('studio.network.'.$link['network']).': '.$link['url'],
                is_array($content['links'] ?? null) ? $content['links'] : [],
            )),
            'footer' => trim(implode(self::LINE, array_filter([
                (string) ($content['business_line'] ?? ''),
                (string) ($content['address_line'] ?? ''),
                (string) ($content['note'] ?? ''),
                __('studio.footer.unsubscribe').': {unsubscribe_url}',
            ]))),
            default => '', // divider, spacer — layout has no words.
        };
    }

    private function emphasised(string $text): string
    {
        $text = trim($text);

        return $text !== '' ? $text.self::LINE.str_repeat('=', min(40, max(4, mb_strlen($text)))) : '';
    }

    /** Sanitized fragment → readable text: breaks kept, tags dropped, entities decoded. */
    private function stripped(string $html): string
    {
        $html = preg_replace('/<br\s*\/?>/i', self::LINE, $html) ?? $html;
        $html = preg_replace('/<\/(p|li)>/i', self::LINE, $html) ?? $html;

        $text = html_entity_decode(strip_tags($html), ENT_QUOTES, 'UTF-8');

        return trim((string) preg_replace('/\n{3,}/', self::GAP, $text));
    }
}
