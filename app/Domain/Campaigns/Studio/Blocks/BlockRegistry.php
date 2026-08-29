<?php

namespace App\Domain\Campaigns\Studio\Blocks;

/**
 * type → definition, the one map everything reads.
 *
 * Adding a block type is adding a line here (and its class): the document
 * guard, the properties panel, the renderer and the AI's block catalogue all
 * follow. A saved document holding a type this build does not know simply
 * loses that block on read (fromArray drops it) — forward-compat in both
 * directions, no migration of stored documents ever.
 */
final class BlockRegistry
{
    /** @var array<string, BlockDefinition>|null */
    private static ?array $map = null;

    /** @return array<string, BlockDefinition> type → definition, in palette order */
    public static function all(): array
    {
        return self::$map ??= self::build();
    }

    public static function for(string $type): ?BlockDefinition
    {
        return self::all()[$type] ?? null;
    }

    /** @return list<string> */
    public static function types(): array
    {
        return array_keys(self::all());
    }

    /** @return array<string, BlockDefinition> */
    private static function build(): array
    {
        $definitions = [
            new HeroBlock,
            new HeadingBlock,
            new TextBlock,
            new ImageBlock,
            new ButtonBlock,
            new CouponBlock,
            new DividerBlock,
            new SpacerBlock,
            new SocialLinksBlock,
            new FooterBlock,
        ];

        $map = [];
        foreach ($definitions as $definition) {
            $map[$definition->type()] = $definition;
        }

        return $map;
    }
}
