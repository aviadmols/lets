<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaignRecipient;
use App\Models\InstallmentPlan;
use Illuminate\Support\Facades\DB;

/**
 * Where does this recipient live? — the `{customer_address}` a campaign email
 * substitutes, as one readable line.
 *
 * IT NEVER CALLS THE STORE. A campaign writes to thousands of people from one
 * click; a live profile read per recipient would spend thousands of calls of
 * the merchant's API budget to fill a line of text, and would do it while the
 * queue is already pacing itself against an SMTP relay. Gift orders can afford
 * that read (GiftAddressResolver) because a package has to arrive at the
 * address the customer lives at TODAY; a sentence in a newsletter does not.
 *
 * So the answer comes from the one place an address IS on our side: the plan's
 * own contact block — what the import carried, or what an admin typed on the
 * subscription screen. Rendered through InstallmentPlan::contactAddressLine(),
 * the same method the admin's contact card reads.
 *
 * A person we hold no address for gets an EMPTY string, never a guess and never
 * somebody else's street. The form says so under the token chips, because a
 * merchant who builds a sentence around the token deserves to know that half a
 * club can come out blank.
 *
 * Tenant-bound by the model's BelongsToShop scope — this class never names a
 * shop_id; the send job's TenantContext binds it.
 */
final class RecipientAddress
{
    // === CONSTANTS ===
    /**
     * How many of one person's plans to look through before giving up. A member
     * has one or two; the cap is there so a customer with a long history cannot
     * turn one email into an unbounded read.
     */
    private const MAX_PLANS = 5;

    /** Only the columns the line is built from. */
    private const COLUMNS = ['id', 'meta'];

    public function for(EmailCampaignRecipient $recipient): string
    {
        if ($recipient->source_type === EmailCampaignRecipient::SOURCE_PLAN) {
            $plan = InstallmentPlan::query()
                ->select(self::COLUMNS)
                ->find((int) $recipient->source_id);

            $line = $plan?->contactAddressLine();
            if ($line !== null) {
                return $line;
            }
        }

        // The row that ENROLLED this person is often not the row that knows
        // where they live: a club membership and a Shopify contract carry no
        // address at all, and a plan can have been created without one. Same
        // person, same address — matched on email, the audience's own key for
        // "this is one human".
        return $this->fromPlansByEmail((string) $recipient->email) ?? '';
    }

    /** The most recent plan of this person's that holds an address. */
    private function fromPlansByEmail(string $email): ?string
    {
        $email = mb_strtolower(trim($email));
        if ($email === '') {
            return null;
        }

        return InstallmentPlan::query()
            ->where(DB::raw('lower(customer_email)'), $email)
            ->select(self::COLUMNS)
            // Newest first: an address typed last year is likelier to be stale
            // than the one that came with the plan they hold now.
            ->orderByDesc('id')
            ->limit(self::MAX_PLANS)
            ->get()
            ->map(static fn (InstallmentPlan $plan): ?string => $plan->contactAddressLine())
            ->first(static fn (?string $line): bool => $line !== null);
    }
}
