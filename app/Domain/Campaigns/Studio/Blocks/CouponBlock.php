<?php

namespace App\Domain\Campaigns\Studio\Blocks;

/** A coupon: a code worth copying, in a dashed box the eye finds. */
final class CouponBlock extends BlockDefinition
{
    public function type(): string
    {
        return 'coupon';
    }

    public function defaultContent(): array
    {
        return ['code' => '', 'description' => ''];
    }

    public function cleanContent(array $raw): array
    {
        return [
            'code' => self::shortText($raw['code'] ?? null, 40),
            'description' => self::shortText($raw['description'] ?? null),
        ];
    }
}
