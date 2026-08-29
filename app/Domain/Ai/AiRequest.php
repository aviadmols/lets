<?php

namespace App\Domain\Ai;

/**
 * One structured-output call, described without any provider's vocabulary.
 *
 * The tool schema IS the contract: the model is forced to answer through it,
 * so a caller receives validated-shape data or a typed failure — never prose
 * to parse, never raw HTML to trust.
 */
final readonly class AiRequest
{
    /**
     * @param  string  $stage  the pipeline stage (prompt + model routing key)
     * @param  int  $shopId  who this call is FOR — the usage row's tenant
     * @param  list<array{role: string, content: string}>  $messages  user/assistant turns, oldest first
     * @param  array<string, mixed>  $toolSchema  JSON schema of the forced tool's input
     */
    public function __construct(
        public string $stage,
        public int $shopId,
        public array $messages,
        public string $toolName,
        public array $toolSchema,
        public ?int $campaignId = null,
    ) {}
}
