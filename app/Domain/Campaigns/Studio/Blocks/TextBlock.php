<?php

namespace App\Domain\Campaigns\Studio\Blocks;

use App\Domain\Campaigns\Studio\BlockTextSanitizer;
use App\Domain\Campaigns\Studio\NewsletterDocument;

/**
 * A paragraph of limited rich text. The ONLY block that carries HTML at all,
 * and it goes through the sanitizer's allowlist walker on EVERY clean — a
 * model's patch payload and a merchant's paste hit the same wall.
 */
final class TextBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'text';
    }

    public function defaultContent(): array
    {
        return ['html' => ''];
    }

    public function cleanContent(array $raw): array
    {
        return ['html' => BlockTextSanitizer::clean($raw['html'] ?? null)];
    }

    public function cleanStyles(array $raw): array
    {
        $out = parent::cleanStyles($raw);

        $size = NewsletterDocument::clampInt($raw['font_size'] ?? null, 12, 22, -1);
        if ($size >= 12) {
            $out['font_size'] = $size;
        }

        $color = NewsletterDocument::hexOr($raw['color'] ?? null, '');
        if ($color !== '') {
            $out['color'] = $color;
        }

        return $out;
    }
}
