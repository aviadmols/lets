<?php

namespace App\Domain\Campaigns\Studio\Blocks;

use App\Domain\Campaigns\Studio\NewsletterDocument;

/** A heading — plain text plus `{token}`s, escaped whole on render. */
final class HeadingBlock extends BlockDefinition
{
    // === CONSTANTS ===
    public const LEVELS = [1, 2];

    public function type(): string
    {
        return 'heading';
    }

    public function defaultContent(): array
    {
        return ['text' => '', 'level' => 1];
    }

    public function cleanContent(array $raw): array
    {
        return [
            'text' => self::shortText($raw['text'] ?? null),
            'level' => NewsletterDocument::clampInt($raw['level'] ?? null, 1, 2, 1),
        ];
    }

    public function cleanStyles(array $raw): array
    {
        $out = parent::cleanStyles($raw);

        $size = NewsletterDocument::clampInt($raw['font_size'] ?? null, 14, 40, -1);
        if ($size >= 14) {
            $out['font_size'] = $size;
        }

        $color = NewsletterDocument::hexOr($raw['color'] ?? null, '');
        if ($color !== '') {
            $out['color'] = $color;
        }

        return $out;
    }
}
