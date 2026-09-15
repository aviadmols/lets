<?php

namespace App\Domain\Installments\Models;

use App\Models\Concerns\BelongsToShop;
use App\Models\InstallmentPlan;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * What a "find saved cards" pass learned about ONE member, and which cards it saw.
 *
 * The run row counts; this row explains. The counters could not answer the
 * question a merchant actually asks — "PayPlus shows two cards for this person,
 * so why did you say there was nothing?" — and answering it meant opening the
 * gateway and comparing by hand, which is the work the screen exists to remove.
 *
 * A real case made the gap concrete: a member's vault held two cards, the report
 * said "no replacement", and the merchant reasonably assumed we had missed one.
 * We had not — their second card had expired fourteen months earlier. The truth
 * was one word away and the report did not have it.
 *
 * So every pass now writes what it saw, and the refusals say WHICH refusal:
 *
 *   only_the_dead_card       nothing else in their vault — a dead end
 *   other_cards_all_expired  there IS another card, and it is past its date
 *   several_possible_cards   two or more live ones, and no safe way to order them
 *                            — a decision waiting for a human, not a dead end
 *   customer_not_found_at_payplus  we could not find THEM, which is a mismatched
 *                            email somebody can fix, not a missing card
 */
class TokenRecoveryResult extends Model
{
    use BelongsToShop;

    // === CONSTANTS ===
    protected $table = 'token_recovery_results';

    /** A card was found and attached. */
    public const OUTCOME_FIXED = 'fixed';

    /** The token we hold is valid and the decline was not a dead-card one. */
    public const OUTCOME_ALREADY_VALID = 'already_valid';

    /** Nothing was attached. `detail` says which wall. */
    public const OUTCOME_NONE = 'none';

    /** Not asked about: terminal, no card row, or gone. */
    public const OUTCOME_SKIPPED = 'skipped';

    /** Charged straight off a card that is not in doubt, or was already replaced. */
    public const OUTCOME_NOT_PROBED = 'not_probed';

    /**
     * The refusal that is NOT a dead end: live cards exist, and a person can pick.
     * Named as a constant because the screen keys its "choose a card" offer off it.
     */
    public const DETAIL_SEVERAL = 'several_possible_cards';

    public const DETAIL_ALL_EXPIRED = 'other_cards_all_expired';

    public const DETAIL_ONLY_DEAD = 'only_the_dead_card';

    public const DETAIL_CUSTOMER_MISSING = 'customer_not_found_at_payplus';

    protected $guarded = [];

    protected $casts = [
        'candidates' => 'array',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(InstallmentPlan::class, 'plan_id');
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(TokenRecoveryRun::class, 'run_id');
    }

    /**
     * The cards this member could be moved TO — everything we saw except the one
     * we already hold and anything past its date.
     *
     * This is what a merchant picks from, so an expired card is not offered: it
     * would be a choice that can only end in a decline.
     *
     * @return list<array<string, mixed>>
     */
    public function choosableCards(): array
    {
        return array_values(array_filter(
            (array) ($this->candidates ?? []),
            static fn (array $c): bool => ! ($c['held'] ?? false) && ($c['expired'] ?? true) === false,
        ));
    }

    /** Is there a real choice here for a human to make? */
    public function offersAChoice(): bool
    {
        return $this->choosableCards() !== [];
    }
}
