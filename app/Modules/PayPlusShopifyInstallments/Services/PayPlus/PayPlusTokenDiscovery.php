<?php

namespace App\Modules\PayPlusShopifyInstallments\Services\PayPlus;

use App\Models\Shop;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * READ-ONLY discovery of what PayPlus actually holds for a MIGRATED subscriber.
 *
 * A CSV-imported member charges against whatever `card_token` the merchant's old
 * system exported. When PayPlus answers `this-token-not-exist`, exactly three
 * questions decide what can be done, and none of them may be answered by
 * guessing:
 *
 *   A. Is that imported value a token PayPlus knows at all?  → /Token/Check
 *   B. Is `recurring_payment_id` a PayPlus recurring?         → /RecurringPayments/{uid}/ViewRecurring
 *      (this one ALSO returns the live card_token + customer_uid + `valid`)
 *   C. Does PayPlus know the customer by email, and which     → /Customers/View + /Token/List
 *      cards does it hold for them?
 *
 * This class ONLY asks. It writes nothing — not to PayPlus, not to our database.
 * Nothing here charges, and no endpoint used here can move money. That is the
 * whole contract: the merchant gets an answer before anything is changed.
 *
 * Auth + URL building reuse PayPlusGateway::HEADER_* and the same
 * rtrim(base_url) . api_prefix . path shape as PayPlusAccountDiscovery — one
 * source of truth. Secrets are NEVER logged; card numbers are never requested
 * (Token/View with mask=false is deliberately NOT used).
 */
final class PayPlusTokenDiscovery
{
    // === CONSTANTS ===
    private const PATH_TOKEN_CHECK = '/Token/Check/';

    private const PATH_TOKEN_LIST = '/Token/List';

    private const PATH_CUSTOMERS_VIEW = '/Customers/View';

    private const PATH_RECURRING_VIEW = '/RecurringPayments/';

    private const PATH_RECURRING_VIEW_SUFFIX = '/ViewRecurring';

    /** Tokens fetched per Token/List page. PayPlus caps `take` at 500. */
    public const TOKEN_PAGE = 500;

    /**
     * Customer records fetched for one email. Not one: a checkout that mints a
     * new PayPlus customer every time somebody types their name differently
     * leaves the same person behind as "Tuvia Lerner", "טוביה לרנר" and
     * "א.ט לרנר", each holding cards. Their cards are all theirs.
     */
    private const CUSTOMER_TAKE = 20;

    /**
     * Beyond this many records for one email we stop rather than fan out a
     * Token/List call per record — and an email on that many records is more
     * likely a shared mailbox than one prolific typist.
     */
    public const MAX_CUSTOMER_RECORDS = 10;

    /** Chars of a PayPlus response kept in a failure log (their body, no secrets of ours). */
    private const LOG_BODY_CHARS = 300;

    /** Typed outcomes, so a caller never has to parse a message. */
    public const REASON_NONE = null;

    public const REASON_AUTH = 'auth';

    public const REASON_TRANSPORT = 'transport';

    public const REASON_NOT_FOUND = 'not_found';

    public ?string $lastReason = self::REASON_NONE;

    public function __construct(
        private readonly string $apiKey,
        private readonly string $secretKey,
        private readonly string $baseUrl,
        private readonly string $apiPrefix,
        private readonly int $timeout,
        private readonly ?string $terminalUid,
    ) {}

    /**
     * Bind to ONE shop's decrypted PayPlus bag. `$terminalOverride` lets the
     * merchant probe the OLD system's terminal with the same company-level api
     * key — which is the whole point when the tokens were minted elsewhere.
     */
    public static function forShop(Shop $shop, ?string $terminalOverride = null): self
    {
        $bag = $shop->payplusConfig();

        return new self(
            apiKey: (string) ($bag['api_key'] ?? ''),
            secretKey: (string) ($bag['secret_key'] ?? ''),
            baseUrl: (string) ($bag['base_url'] ?: config('payplus.base_url')),
            apiPrefix: (string) config('payplus.api_prefix', '/api/v1.0'),
            timeout: (int) config('payplus.timeout', 30),
            terminalUid: $terminalOverride ?: ($bag['terminal_uid'] ?? null),
        );
    }

    public function terminalUid(): ?string
    {
        return $this->terminalUid;
    }

    public function isConfigured(): bool
    {
        return $this->apiKey !== '' && $this->secretKey !== '';
    }

    /**
     * ROUTE A — does PayPlus know this token uid at all?
     *
     * A 400 with an empty body is PayPlus's documented "no such token", which is
     * an ANSWER, not a failure: it is reported as REASON_NOT_FOUND and null.
     *
     * @return array<string, mixed>|null the token's card details, or null
     */
    public function checkToken(string $tokenUid): ?array
    {
        $body = $this->request('GET', self::PATH_TOKEN_CHECK.rawurlencode($tokenUid));

        return $this->payload($body);
    }

    /**
     * ROUTE B — is this a PayPlus recurring, and what card does it ride on?
     *
     * The response carries `card_token` and `customer_uid` (the two fields an
     * imported payment method is missing) and, critically, `valid` — because a
     * recurring still marked live at PayPlus is still billing the customer on
     * its own, and switching our scheduler on beside it would charge twice.
     *
     * @return array<string, mixed>|null
     */
    public function viewRecurring(string $recurringUid): ?array
    {
        $path = self::PATH_RECURRING_VIEW.rawurlencode($recurringUid).self::PATH_RECURRING_VIEW_SUFFIX;

        $body = $this->request('GET', $path, $this->terminalUid !== null
            ? ['terminal_uid' => $this->terminalUid]
            : []);

        return $this->payload($body);
    }

    /**
     * ROUTE C, step 1 — find the customer by email. PayPlus documents filters on
     * uuid / vat_number / email only: there is no phone or name lookup.
     *
     * @return array<string, mixed>|null the first matching customer
     */
    public function customerByEmail(string $email): ?array
    {
        $customers = $this->customersByEmail($email);

        if ($customers === []) {
            $this->lastReason = self::REASON_NOT_FOUND;

            return null;
        }

        return $customers[0];
    }

    /**
     * EVERY PayPlus customer record carrying EXACTLY this email, not just the
     * first.
     *
     * The first record is an accident of insertion order and may be the one
     * with no cards on it at all, while the card we are looking for sits on the
     * third. Exact equality is enforced here rather than trusted from the API,
     * so a filter that ever turned fuzzy could not hand back a stranger.
     *
     * @return list<array<string, mixed>>
     */
    public function customersByEmail(string $email): array
    {
        $body = $this->request('GET', self::PATH_CUSTOMERS_VIEW, [
            'email' => $email,
            'take' => self::CUSTOMER_TAKE,
        ]);

        if ($body === null) {
            return [];
        }

        $customers = $body['customers'] ?? $body['data']['customers'] ?? $body['data'] ?? [];
        $wanted = mb_strtolower(trim($email));

        return array_values(array_filter(
            (array) $customers,
            static fn ($c): bool => is_array($c)
                && ($c['customer_uid'] ?? $c['uid'] ?? '') !== ''
                && mb_strtolower(trim((string) ($c['email'] ?? ''))) === $wanted,
        ));
    }

    /**
     * One entry per physical card. The same card re-vaulted on three duplicate
     * customer records comes back as three tokens with one last-4 and one
     * expiry; counting it three times would make "the customer's only card"
     * look like a choice. The first token seen is kept — any of them charges
     * the same card.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return list<array<string, mixed>>
     */
    public static function dedupeCards(array $tokens): array
    {
        $seen = [];
        $out = [];

        foreach ($tokens as $t) {
            if (! is_array($t) || ($t['token'] ?? '') === '') {
                continue;
            }

            $key = trim((string) ($t['last_4_digits'] ?? '')).'/'.trim((string) ($t['card_date_mmyy'] ?? ''));

            if (isset($seen[$key])) {
                continue;
            }

            $seen[$key] = true;
            $out[] = $t;
        }

        return $out;
    }

    /**
     * ROUTE C, step 2 — the cards PayPlus holds, for one customer or (with
     * $customerUid null) for the whole terminal.
     *
     * @return list<array<string, mixed>>
     */
    public function tokens(?string $customerUid = null, int $skip = 0, int $take = self::TOKEN_PAGE): array
    {
        $payload = array_filter([
            'terminal_uid' => $this->terminalUid,
            'customer_uid' => $customerUid,
            'skip' => (string) $skip,
            'take' => (string) $take,
        ], static fn ($v): bool => $v !== null && $v !== '');

        $body = $this->request('POST', self::PATH_TOKEN_LIST, $payload);

        if ($body === null) {
            return [];
        }

        $rows = $body['data'] ?? [];

        return array_values(array_filter((array) $rows, 'is_array'));
    }

    /**
     * The token in $tokens whose last-4 AND expiry match the card we hold.
     *
     * Both must match: last-4 alone repeats across a book of 1,300 members, and
     * handing back the wrong customer's token would charge the wrong person.
     * When we hold no last-4 there is nothing to match on, so this refuses
     * rather than guessing — the caller reports it as ambiguous.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return array<string, mixed>|null
     */
    public static function matchCard(array $tokens, ?string $lastFour, ?int $expMonth, ?int $expYear): ?array
    {
        $lastFour = trim((string) $lastFour);

        if ($lastFour === '' || $expMonth === null || $expYear === null) {
            return null;
        }

        $mmyy = self::mmyy($expMonth, $expYear);

        $hits = array_values(array_filter($tokens, static fn (array $t): bool => trim((string) ($t['last_4_digits'] ?? '')) === $lastFour
            && trim((string) ($t['card_date_mmyy'] ?? '')) === $mmyy));

        // Two cards that agree on both is not a match — it is a coin toss.
        return count($hits) === 1 ? $hits[0] : null;
    }

    /**
     * The card to use when we hold NO last-4 — or hold one that matches nothing.
     *
     * This is only safe because of where $tokens came from: Token/List filtered
     * by ONE customer_uid, found by email. Every candidate is the same person's
     * card, so the strict matcher's fear — charging somebody else — does not
     * apply here. What remains is picking the wrong card of the RIGHT person,
     * and the worst that does is a decline, which the dunning ladder already
     * handles. So the rules only have to be unambiguous, not paranoid:
     *
     *   only_card       — they have exactly one card. Nothing to choose.
     *   expiry          — exactly one card carries the expiry we hold.
     *   only_unexpired  — exactly one card has not expired yet.
     *
     * The last rule uses no evidence of OURS at all, so it is offered only when
     * $allowUnexpiredRule is true — the caller sets that false when the cards
     * were pooled from several customer records under one email, because a
     * shared family or office inbox is exactly where "the same person's cards"
     * stops being guaranteed, and a rule that picks a card on liveness alone
     * would then pick somebody else's.
     *
     * Anything still ambiguous after that is refused, as before. Pass tokens
     * through dedupeCards() first when they were pooled across records.
     *
     * @param  list<array<string, mixed>>  $tokens
     * @return array{card: array<string, mixed>, basis: string}|null
     */
    public static function matchCardRelaxed(array $tokens, ?int $expMonth, ?int $expYear, bool $allowUnexpiredRule = true): ?array
    {
        $tokens = array_values(array_filter($tokens, static fn ($t): bool => is_array($t) && ($t['token'] ?? '') !== ''));

        if (count($tokens) === 1) {
            return ['card' => $tokens[0], 'basis' => 'only_card'];
        }

        if ($tokens === []) {
            return null;
        }

        if ($expMonth !== null && $expYear !== null) {
            $mmyy = self::mmyy($expMonth, $expYear);
            $byExpiry = array_values(array_filter($tokens, static fn (array $t): bool => trim((string) ($t['card_date_mmyy'] ?? '')) === $mmyy));

            if (count($byExpiry) === 1) {
                return ['card' => $byExpiry[0], 'basis' => 'expiry'];
            }
        }

        if (! $allowUnexpiredRule) {
            return null;
        }

        $unexpired = array_values(array_filter($tokens, static fn (array $t): bool => self::isUnexpired((string) ($t['card_date_mmyy'] ?? ''))));

        if (count($unexpired) === 1) {
            return ['card' => $unexpired[0], 'basis' => 'only_unexpired'];
        }

        return null;
    }

    /** PayPlus returns expiry as MMYY; ours is stored as two integers. */
    private static function mmyy(int $expMonth, int $expYear): string
    {
        return str_pad((string) $expMonth, 2, '0', STR_PAD_LEFT)
            .str_pad((string) ($expYear % 100), 2, '0', STR_PAD_LEFT);
    }

    /** A card whose MMYY month has not ended yet. Malformed expiry counts as expired. */
    private static function isUnexpired(string $mmyy): bool
    {
        if (preg_match('/^(\d{2})(\d{2})$/', trim($mmyy), $m) !== 1) {
            return false;
        }

        $month = (int) $m[1];
        $year = 2000 + (int) $m[2];

        if ($month < 1 || $month > 12) {
            return false;
        }

        // Valid through the last day of its month.
        return ($year * 100 + $month) >= ((int) date('Y') * 100 + (int) date('n'));
    }

    // === Internals ===

    /**
     * Authenticated request. Returns the decoded body on 2xx, or NULL after
     * stamping $lastReason. A 4xx from these endpoints means "no such record",
     * which is a legitimate answer here rather than an error to shout about.
     *
     * @return array<string, mixed>|null
     */
    private function request(string $method, string $path, array $data = []): ?array
    {
        $this->lastReason = self::REASON_NONE;

        try {
            $client = Http::withHeaders([
                PayPlusGateway::HEADER_API_KEY => $this->apiKey,
                PayPlusGateway::HEADER_SECRET_KEY => $this->secretKey,
            ])->timeout($this->timeout)->acceptJson();

            $response = $method === 'POST'
                ? $client->post($this->endpoint($path), $data)
                : $client->get($this->endpoint($path), $data);
        } catch (Throwable $e) {
            Log::warning('payplus.token_discovery.transport_error', [
                'path' => $path,
                'exception' => $e::class,
            ]);
            $this->lastReason = self::REASON_TRANSPORT;

            return null;
        }

        if ($response->unauthorized() || $response->forbidden()) {
            Log::warning('payplus.token_discovery.auth_failed', [
                'path' => $path,
                'status' => $response->status(),
            ]);
            $this->lastReason = self::REASON_AUTH;

            return null;
        }

        if (! $response->successful()) {
            // Documented shape for "no such token/recurring": 4xx with `{}`.
            $this->lastReason = $response->clientError()
                ? self::REASON_NOT_FOUND
                : self::REASON_TRANSPORT;

            if (! $response->clientError()) {
                Log::warning('payplus.token_discovery.http_error', [
                    'path' => $path,
                    'status' => $response->status(),
                    'body' => mb_substr($response->body(), 0, self::LOG_BODY_CHARS),
                ]);
            }

            return null;
        }

        $body = $response->json();

        return is_array($body) ? $body : null;
    }

    /**
     * Unwrap `{results:{status}, data:{…}}`, treating a non-success `results`
     * as "not found" so a 200-with-an-error-body is never read as a hit.
     *
     * @param  array<string, mixed>|null  $body
     * @return array<string, mixed>|null
     */
    private function payload(?array $body): ?array
    {
        if ($body === null) {
            return null;
        }

        $status = strtolower((string) ($body['results']['status'] ?? GatewayResult::STATUS_SUCCESS));

        if ($status !== GatewayResult::STATUS_SUCCESS) {
            $this->lastReason = self::REASON_NOT_FOUND;

            return null;
        }

        $data = $body['data'] ?? $body;

        return is_array($data) && $data !== [] ? $data : null;
    }

    private function endpoint(string $path): string
    {
        return rtrim($this->baseUrl, '/').$this->apiPrefix.$path;
    }
}
