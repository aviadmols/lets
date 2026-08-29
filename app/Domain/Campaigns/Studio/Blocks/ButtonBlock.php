<?php

namespace App\Domain\Campaigns\Studio\Blocks;

use App\Domain\Campaigns\Studio\NewsletterDocument;

/**
 * The call to action. `{account_login_url}` and `{unsubscribe_url}` are legal
 * destinations — the send-time strtr turns them into the real links.
 */
final class ButtonBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'button';
    }

    public function defaultContent(): array
    {
        return ['label' => '', 'url' => ''];
    }

    public function cleanContent(array $raw): array
    {
        return [
            'label' => self::shortText($raw['label'] ?? null, 80),
            'url' => self::url($raw['url'] ?? null),
        ];
    }

    public function cleanStyles(array $raw): array
    {
        $out = parent::cleanStyles($raw);

        foreach (['color' => 'color', 'text_color' => 'text_color'] as $key => $target) {
            $hex = NewsletterDocument::hexOr($raw[$key] ?? null, '');
            if ($hex !== '') {
                $out[$target] = $hex;
            }
        }

        return $out;
    }
}
