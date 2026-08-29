<?php

namespace App\Domain\Campaigns\Studio\Blocks;

/**
 * The legal footer — and the block that satisfies the marketing law by
 * construction.
 *
 * Its renderer ALWAYS emits the `{unsubscribe_url}` line; there is no content
 * key that can switch it off, which is precisely why the existing "a marketing
 * email must carry an unsubscribe link" check passes for every studio campaign
 * that has a footer — and why the patch pipeline refuses to remove the last
 * one from a marketing document.
 */
final class FooterBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'footer';
    }

    public function defaultContent(): array
    {
        return ['business_line' => '{business_name}', 'address_line' => '', 'note' => ''];
    }

    public function cleanContent(array $raw): array
    {
        return [
            'business_line' => self::shortText($raw['business_line'] ?? null, 150),
            'address_line' => self::shortText($raw['address_line'] ?? null, 200),
            'note' => self::shortText($raw['note'] ?? null),
        ];
    }
}
