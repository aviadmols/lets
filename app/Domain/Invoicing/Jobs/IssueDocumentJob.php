<?php

namespace App\Domain\Invoicing\Jobs;

use App\Domain\Invoicing\DocumentContext;
use App\Domain\Invoicing\DocumentIssuer;
use App\Models\IssuedDocument;
use App\Models\MerchantInvoicingSettings;
use App\Models\Shop;
use App\Services\WooCommerce\WooPluginNotifier;
use App\Support\TenantContext;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Issue ONE accounting document, off the money path. Mirrors ChargeJob:
 * ShouldBeUnique collapses a double dispatch to a single in-flight job, the
 * TenantContext middleware binds the shop in handle() and ALWAYS clears it after,
 * and shop_id is carried EXPLICITLY — never inferred from global state.
 *
 * WHY QUEUED: ChargeOrchestrator::charge() runs inside a DB transaction, so no
 * HTTP may happen there. Dispatching (afterCommit) keeps a slow or dead invoicing
 * provider from holding a database lock, slowing a charge, or — worst of all —
 * rolling back money that already moved.
 *
 * Queue-level retries are SAFE because DocumentIssuer keys every document to the
 * money event: a retry reuses the same issued_documents row and can never mint a
 * second document.
 */
final class IssueDocumentJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    // === CONSTANTS ===
    /** ShouldBeUnique lock TTL (seconds) — released when the job completes. */
    public int $uniqueFor = 600;

    /**
     * @param  int  $shopId  the tenant, carried explicitly
     * @param  string  $context  a DocumentContext value
     * @param  int|null  $ledgerId  the money movement, for a plan/upsell/refund document
     * @param  array<string, mixed>|null  $order  the reported store order, for `all_orders` scope
     * @param  string|null  $linkedDocumentId  the document this one credits, when known
     * @param  float|null  $amount  overrides the ledger amount — a PARTIAL refund
     *                              credits less than the original sale
     */
    public function __construct(
        public readonly int $shopId,
        public readonly string $context,
        public readonly ?int $ledgerId = null,
        public readonly ?array $order = null,
        public readonly ?string $linkedDocumentId = null,
        public readonly ?float $amount = null,
    ) {
        $this->onQueue((string) config('invoicing.queue', TenantContext::QUEUE_INVOICES));
    }

    /**
     * Queue this document from inside a money transaction, safely.
     *
     * Every money-path hook calls THIS rather than dispatch() directly, for two
     * reasons that are easy to get wrong separately:
     *
     *   - afterCommit(): the callers run inside DB::transaction, so the job must not
     *     become visible to a worker before the money it describes is committed —
     *     otherwise the worker reads a ledger row that does not exist yet.
     *   - try/catch: an afterCommit callback throws AFTER the money is committed, so
     *     an unreachable queue would surface as a failed charge to the caller even
     *     though the charge succeeded. A missing document is recoverable; a charge
     *     falsely reported as failed is not.
     *
     * @param  array<string, mixed>|null  $order
     */
    public static function queueAfterCommit(
        int $shopId,
        string $context,
        ?int $ledgerId = null,
        ?array $order = null,
        ?string $linkedDocumentId = null,
        ?float $amount = null,
    ): void {
        try {
            self::dispatch($shopId, $context, $ledgerId, $order, $linkedDocumentId, $amount)->afterCommit();
        } catch (Throwable $e) {
            Log::warning('invoicing.dispatch_failed', [
                'shop_id' => $shopId,
                'context' => $context,
                'ledger_id' => $ledgerId,
                'exception' => $e::class,
                'message' => $e->getMessage(),
            ]);
        }
    }

    public function tries(): int
    {
        return max(1, (int) config('invoicing.job_tries', 3));
    }

    /**
     * Backoff between attempts. A provider blip resolves in a minute; a longer
     * outage needs the second, longer wait rather than three rapid failures.
     *
     * @return list<int>
     */
    public function backoff(): array
    {
        $backoff = (array) config('invoicing.job_backoff_seconds', [60, 600]);

        return array_values(array_map(static fn ($s): int => max(1, (int) $s), $backoff)) ?: [60];
    }

    /**
     * Deterministic, shop-namespaced uniqueness — the same key shape the document
     * itself is stored under, so a duplicate dispatch never even reaches the issuer.
     */
    public function uniqueId(): string
    {
        if ($this->ledgerId === null) {
            return sprintf('shop:%d:doc:order:%s', $this->shopId, (string) ($this->order['order_id'] ?? ''));
        }

        // The context + amount are part of the key: a refund of a charge is a
        // DIFFERENT document from the charge's own, and two partial refunds of the
        // same charge are two different documents again.
        return sprintf(
            'shop:%d:doc:ledger:%d:%s:%s',
            $this->shopId,
            $this->ledgerId,
            $this->context,
            $this->amount !== null ? number_format($this->amount, 2, '.', '') : 'full',
        );
    }

    /** @return array<int, object> */
    public function middleware(): array
    {
        return [new TenantContext($this->shopId)];
    }

    public function handle(DocumentIssuer $issuer): void
    {
        $context = DocumentContext::tryFrom($this->context);

        // An unknown context can only come from a payload we no longer understand.
        // Drop it rather than guessing which books to write to.
        if ($context === null) {
            return;
        }

        // Render the document's own text in the MERCHANT's document language, not the
        // worker's. Laravel does not carry a locale onto a queued job, so without this
        // a shop set to Hebrew gets a Hebrew document with English line descriptions —
        // the provider is told `lang: he` while our __() strings resolve in `en`.
        $this->withDocumentLocale(fn () => $this->issue($issuer, $context));
    }

    /** Run $callback with the shop's document language bound, restoring it after. */
    private function withDocumentLocale(callable $callback): void
    {
        $previous = App::getLocale();

        try {
            App::setLocale(MerchantInvoicingSettings::forShop($this->shopId)->documentLanguage());
            $callback();
        } finally {
            App::setLocale($previous);
        }
    }

    private function issue(DocumentIssuer $issuer, DocumentContext $context): void
    {
        if ($this->ledgerId !== null) {
            $issuer->issueForLedger(
                $this->shopId,
                $this->ledgerId,
                $context,
                $this->linkedDocumentId,
                $this->amount,
            );

            return;
        }

        if ($this->order !== null) {
            $this->notifyStore($issuer->issueForPlatformOrder($this->shopId, $this->order));
        }
    }

    /**
     * The RETURN LEG of the `all_orders` scope: tell the WooCommerce plugin the
     * document's number + URL so it stamps the order meta and adds an order note the
     * merchant sees inside WooCommerce.
     *
     * Only for a shop that opted into attach-to-order, only for a WooCommerce shop,
     * and wrapped so a notification problem can never fail (and therefore re-run) a
     * job whose document has ALREADY been issued — a retry there would be the one way
     * to mint a second document.
     */
    private function notifyStore(?IssuedDocument $document): void
    {
        if ($document === null || ! $document->isIssued()) {
            return;
        }

        try {
            $shop = Shop::query()->find($this->shopId);
            if ($shop === null || $shop->platform !== Shop::PLATFORM_WOOCOMMERCE) {
                return;
            }

            if (! MerchantInvoicingSettings::forShop($this->shopId)->attachesToOrder()) {
                return;
            }

            app(WooPluginNotifier::class)->documentIssued(
                shop: $shop,
                orderId: (string) $document->external_order_id,
                documentId: (string) $document->provider_document_id,
                documentNumber: $document->document_number,
                documentUrl: $document->document_url,
            );
        } catch (Throwable $e) {
            Log::warning('invoicing.notify_store_failed', [
                'shop_id' => $this->shopId,
                'document_id' => $document->getKey(),
                'error' => $e->getMessage(),
            ]);
        }
    }
}
