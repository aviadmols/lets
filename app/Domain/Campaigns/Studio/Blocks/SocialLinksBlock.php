<?php

namespace App\Domain\Campaigns\Studio\Blocks;

/**
 * Social links, as TEXT links in v1 — the app hosts no icon assets, and a
 * broken icon in a footer reads worse than a plain word.
 */
final class SocialLinksBlock extends BlockDefinition
{
    // === CONSTANTS ===
    public const NETWORKS = ['facebook', 'instagram', 'tiktok', 'youtube', 'whatsapp', 'x', 'linkedin', 'website'];

    public const MAX_LINKS = 6;

    public function type(): string
    {
        return 'social_links';
    }

    public function defaultContent(): array
    {
        return ['links' => []];
    }

    public function cleanContent(array $raw): array
    {
        $links = [];

        foreach (is_array($raw['links'] ?? null) ? $raw['links'] : [] as $link) {
            if (count($links) >= self::MAX_LINKS || ! is_array($link)) {
                continue;
            }

            $network = is_string($link['network'] ?? null) ? $link['network'] : '';
            $url = self::url($link['url'] ?? null);

            if (! in_array($network, self::NETWORKS, true) || $url === '') {
                continue;
            }

            $links[] = ['network' => $network, 'url' => $url];
        }

        return ['links' => $links];
    }
}
