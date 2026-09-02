<?php

namespace App\Domain\Campaigns\Email;

use App\Domain\Campaigns\Email\Models\EmailCampaign;
use Illuminate\Support\Facades\DB;

/**
 * Copy a campaign into a fresh draft.
 *
 * WHAT COMES ACROSS is everything the merchant WROTE: the subject, the body (in
 * whichever editor produced it — a studio campaign carries its block document
 * too, or the copy would open empty in the studio), the audience, the marketing
 * flag and the link TTL. That is the whole point: last month's newsletter is
 * next month's starting draft.
 *
 * WHAT DOES NOT is everything that is a RECORD OF A SEND, and the reason is the
 * same for every one of them — a copy has not been sent to anybody:
 *
 *   - status: always back to `draft`, whatever the source was doing;
 *   - the recipients, and therefore the counts, the timestamps, the schedule;
 *   - the login tokens. These are live credentials minted per person for ONE
 *     email that has already left; a second campaign holding the same links
 *     would mean revoking one campaign's links silently disarms the other's,
 *     and the merchant's one lever for a leaked link would no longer be true.
 *     The copy mints its own, when and if it is ever sent.
 *   - the document version history and any AI chat, which belong to the
 *     conversation that produced the original, not to a new draft.
 *
 * The name is suffixed rather than reused: two rows called the same thing in a
 * list where one is sent and one is a draft is how the wrong one gets sent.
 */
final class CampaignDuplicator
{
    // === CONSTANTS ===
    /** Appended to the copied name, then numbered if that is taken too. */
    public const SUFFIX_KEY = 'campaigns.duplicate.suffix';

    /** Give up numbering after this many tries and let the name repeat. */
    private const MAX_NAME_ATTEMPTS = 50;

    /** The merchant's own work — copied verbatim. */
    private const CARRIED = [
        'subject',
        'body_html',
        'body_text',
        'document',
        'editor_mode',
        'audience',
        'is_marketing',
        'login_link_ttl_hours',
    ];

    public function duplicate(EmailCampaign $source, ?int $userId = null): EmailCampaign
    {
        return DB::transaction(function () use ($source, $userId): EmailCampaign {
            $copy = new EmailCampaign;

            foreach (self::CARRIED as $column) {
                $copy->{$column} = $source->{$column};
            }

            $copy->name = $this->availableName($source);
            $copy->created_by_user_id = $userId;

            // A copied document has no history behind it: the studio's next save
            // writes version 1 with the first snapshot, as for any new campaign.
            $copy->document_version = 0;

            // shop_id is written by BelongsToShop from the bound tenant; status is
            // guarded, and a copy is a draft by definition.
            $copy->save();
            $copy->forceFill(['status' => EmailCampaign::STATUS_DRAFT])->save();

            return $copy;
        });
    }

    /**
     * "Newsletter (copy)", then "Newsletter (copy) 2" — and never longer than the
     * column, because a silently truncated name is worse than a numbered one.
     */
    private function availableName(EmailCampaign $source): string
    {
        $base = $this->fit(trim((string) $source->name).' '.__(self::SUFFIX_KEY));

        $candidate = $base;

        for ($n = 2; $n <= self::MAX_NAME_ATTEMPTS; $n++) {
            if (! EmailCampaign::query()->where('name', $candidate)->exists()) {
                return $candidate;
            }

            $candidate = $this->fit($base.' '.$n, mb_strlen((string) $n) + 1);
        }

        return $candidate;
    }

    private function fit(string $name, int $reserve = 0): string
    {
        $max = EmailCampaign::MAX_NAME - $reserve;

        return mb_strlen($name) <= $max ? $name : mb_substr($name, 0, $max);
    }
}
