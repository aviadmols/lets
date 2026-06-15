<?php

namespace App\Domain\Upsell\Enums;

/**
 * Lifecycle of an upsell flow. Only ACTIVE flows are evaluated by the resolver.
 * draft = being built (never shown); inactive = paused (kept, not shown). The
 * allowed-transition table keeps a merchant from "publishing" a half-built flow
 * by guarding the move (HasGuardedStatus).
 */
enum UpsellFlowStatus: string
{
    case DRAFT = 'draft';
    case ACTIVE = 'active';
    case INACTIVE = 'inactive';

    /**
     * Legal moves: a draft is published to active; active pauses to inactive and
     * back; inactive may be retired to draft for re-editing.
     *
     * @return array<string, list<self>>
     */
    public static function allowed(): array
    {
        return [
            self::DRAFT->value => [self::ACTIVE],
            self::ACTIVE->value => [self::INACTIVE],
            self::INACTIVE->value => [self::ACTIVE, self::DRAFT],
        ];
    }

    public function isActive(): bool
    {
        return $this === self::ACTIVE;
    }
}
