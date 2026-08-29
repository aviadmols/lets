<?php

namespace App\Domain\Campaigns\Studio\Blocks;

use App\Domain\Campaigns\Studio\NewsletterDocument;

/** Breathing room. Height is the whole configuration. */
final class SpacerBlock extends BlockDefinition
{
    // === CONSTANTS ===
    public const MIN_HEIGHT = 4;

    public const MAX_HEIGHT = 120;

    public const DEFAULT_HEIGHT = 24;

    public function type(): string
    {
        return 'spacer';
    }

    public function defaultContent(): array
    {
        return [];
    }

    public function cleanContent(array $raw): array
    {
        return [];
    }

    public function cleanStyles(array $raw): array
    {
        return [
            'height' => NewsletterDocument::clampInt(
                $raw['height'] ?? null,
                self::MIN_HEIGHT,
                self::MAX_HEIGHT,
                self::DEFAULT_HEIGHT,
            ),
        ];
    }
}
