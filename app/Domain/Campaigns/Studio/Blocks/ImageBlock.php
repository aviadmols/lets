<?php

namespace App\Domain\Campaigns\Studio\Blocks;

use App\Domain\Campaigns\Studio\NewsletterDocument;

/**
 * An image by REMOTE https URL — this app hosts no files (the house fact:
 * product images are platform-CDN URLs, web and worker share no disk), so a
 * block that pretended to accept uploads would be a promise nothing keeps.
 */
final class ImageBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'image';
    }

    public function defaultContent(): array
    {
        return ['url' => '', 'alt' => '', 'link_url' => '', 'width_pct' => 100];
    }

    public function cleanContent(array $raw): array
    {
        return [
            'url' => self::imageUrl($raw['url'] ?? null),
            'alt' => self::shortText($raw['alt'] ?? null, 150),
            'link_url' => self::url($raw['link_url'] ?? null),
            'width_pct' => NewsletterDocument::clampInt($raw['width_pct'] ?? null, 20, 100, 100),
        ];
    }
}
