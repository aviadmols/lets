<?php

namespace App\Domain\Ai\Providers;

use App\Domain\Ai\AiRequest;
use App\Domain\Ai\AiResult;

/**
 * A model vendor, reduced to the one thing the product asks of it: run a
 * structured-output call and answer with the tool's input or a typed failure.
 *
 * Nothing outside Providers/ may speak a vendor's dialect — swapping or adding
 * a provider is a new implementation and a factory entry, never a product
 * change. Implementations NEVER throw to the caller.
 */
interface AiProvider
{
    public function complete(AiRequest $request): AiResult;
}
