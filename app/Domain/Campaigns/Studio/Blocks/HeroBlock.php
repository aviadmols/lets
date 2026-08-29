<?php

namespace App\Domain\Campaigns\Studio\Blocks;

/** The opening act: image + heading + line + button, rendered as one piece. */
final class HeroBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'hero';
    }

    public function defaultContent(): array
    {
        return ['image_url' => '', 'heading' => '', 'text' => '', 'button_label' => '', 'button_url' => ''];
    }

    public function cleanContent(array $raw): array
    {
        return [
            'image_url' => self::imageUrl($raw['image_url'] ?? null),
            'heading' => self::shortText($raw['heading'] ?? null),
            'text' => self::shortText($raw['text'] ?? null, 600),
            'button_label' => self::shortText($raw['button_label'] ?? null, 80),
            'button_url' => self::url($raw['button_url'] ?? null),
        ];
    }
}
