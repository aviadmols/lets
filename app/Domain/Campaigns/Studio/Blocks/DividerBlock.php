<?php

namespace App\Domain\Campaigns\Studio\Blocks;

use App\Domain\Campaigns\Studio\NewsletterDocument;

/** A horizontal rule. */
final class DividerBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'divider';
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
        $out = parent::cleanStyles($raw);

        $color = NewsletterDocument::hexOr($raw['color'] ?? null, '');
        if ($color !== '') {
            $out['color'] = $color;
        }

        $thickness = NewsletterDocument::clampInt($raw['thickness'] ?? null, 1, 8, -1);
        if ($thickness >= 1) {
            $out['thickness'] = $thickness;
        }

        return $out;
    }
}
