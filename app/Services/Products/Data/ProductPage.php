<?php

namespace App\Services\Products\Data;

/**
 * One page of a paginated product fetch. `nextCursor` is the opaque continuation
 * token (Shopify endCursor / a Woo page number) the import loop feeds back into
 * fetchPage(); null means "no more pages" and ends the loop. The cursor is OPAQUE
 * to callers — only the originating source interprets it.
 */
final readonly class ProductPage
{
    public function __construct(
        /** @var array<int, ProductData> */
        public array $items = [],
        public ?string $nextCursor = null,
    ) {}

    public function hasMore(): bool
    {
        return $this->nextCursor !== null;
    }
}
