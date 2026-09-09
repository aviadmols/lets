<?php

namespace App\Http\Controllers\WooCommerce;

use App\Domain\Refunds\Models\RefundRequest;
use App\Domain\Refunds\RefundOrchestrator;
use App\Domain\Refunds\RefundPreview;
use App\Domain\Refunds\StoreRefundResult;
use App\Http\Controllers\WooCommerce\Storefront\WooStorefrontController;
use App\Models\ActivityEvent;
use App\Models\Shop;
use App\Support\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A refund the merchant made INSIDE WooCommerce.
 *
 * Merchants do not work in one admin. Somebody refunds an order from the WP
 * dashboard because that is where they were standing, and until now that money
 * went back with no PayPlus refund, no ledger adjustment and no credit note —
 * the shopper was made whole by WooCommerce alone and the merchant's books never
 * heard about it.
 *
 * THREE ENDPOINTS. The two that act differ in WHO MOVES THE MONEY — which is
 * why they are separate rather than one with a flag the plugin might mis-set,
 * because getting it wrong is a double refund. The third only reads.
 *
 *   /orders/{order}/refund     the LETS GATEWAY's own `process_refund`.
 *                              WooCommerce is ASKING us to move it, so this runs
 *                              the money leg — PayPlus, then the credit note —
 *                              and answers whether it worked. WooCommerce writes
 *                              its own refund record from that answer, which is
 *                              why the store leg here is already done.
 *
 *   /orders/{order}/refunded   a refund WooCommerce made WITHOUT us (another
 *                              gateway, an offline refund). The money is already
 *                              gone; this records it and issues the paperwork,
 *                              and PayPlus is never called.
 *
 *   /orders/{order}/refund-state  what LETS knows about this order, for the
 *                              plugin's own refund box: how much is still
 *                              refundable and through which rail. A READ.
 *
 *
 * THE MIRROR TAKES A RUNNING TOTAL, NOT AN AMOUNT. WooCommerce fires its refund
 * hook for the gateway's own refunds too, and a re-delivery fires it again — so
 * an endpoint that added up "this refund's amount" would count our own refund a
 * second time and report a customer as doubly refunded. The plugin sends the
 * ORDER'S TOTAL REFUNDED and we record the DELTA against what we already know.
 * Every duplicate then collapses to a delta of zero, by construction rather than
 * by a guard somebody has to remember.
 */
final class RefundMirrorController extends WooStorefrontController
{
    // === CONSTANTS ===
    /** The actor on a request opened from the store's own admin. */
    private const ACTOR = ActivityEvent::ACTOR_WEBHOOK;

    /** Rounding slack, the same grain the ledger uses. */
    private const EPSILON = 0.005;

    /**
     * POST /api/woocommerce/orders/{order}/refund — the gateway's process_refund.
     *
     * Runs INLINE rather than queueing: WooCommerce blocks on the answer, the
     * merchant is looking at a spinner in their own admin, and "we will get to
     * it" is not something WooCommerce knows how to render.
     */
    public function refund(Request $request, string $order): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $amount = round((float) $request->input('amount', 0), 2);
        if ($amount <= 0) {
            return response()->json(['error' => 'invalid_amount'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $result = Tenant::run($shop, function () use ($shop, $order, $amount, $request): RefundRequest {
            $orchestrator = app(RefundOrchestrator::class);

            return $orchestrator->run($shop, $this->open($shop, $order, $amount, $request));
        });

        $refunded = $result->refundedTotal();

        // WooCommerce reads `ok` as "did the gateway hand the money back?" — a
        // false leaves its own refund record unwritten, which is exactly right
        // when PayPlus refused.
        return response()->json([
            'ok' => $refunded > 0,
            'status' => (string) $result->status,
            'refunded' => $refunded,
            'reason' => $result->failure_code,
        ], $refunded > 0 ? Response::HTTP_OK : Response::HTTP_UNPROCESSABLE_ENTITY);
    }

    /**
     * POST /api/woocommerce/orders/{order}/refunded — a refund the store made.
     *
     * `total_refunded` is the order's RUNNING TOTAL, and we act only on the part
     * we did not already know about. @see the class doc.
     */
    public function refunded(Request $request, string $order): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        $total = round((float) $request->input('total_refunded', 0), 2);
        if ($total <= 0) {
            return response()->json(['error' => 'invalid_amount'], Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        return Tenant::run($shop, function () use ($shop, $order, $total, $request): JsonResponse {
            $orchestrator = app(RefundOrchestrator::class);
            $delta = round($total - $orchestrator->knownRefunded($shop, $order), 2);

            // Nothing new. A re-delivered hook, or the hook firing for a refund
            // this very app just made through the gateway.
            if ($delta <= self::EPSILON) {
                return response()->json(['ok' => true, 'recorded' => 0.0, 'reason' => 'already_known']);
            }

            $result = $orchestrator->mirrorExternal(
                $shop,
                $this->open($shop, $order, $delta, $request),
                $delta,
            );

            return response()->json([
                'ok' => true,
                'recorded' => $result->refundedTotal(),
                'request_id' => (int) $result->getKey(),
            ]);
        });
    }

    /**
     * POST /api/woocommerce/orders/{order}/refund-state — what LETS knows.
     *
     * POST rather than GET because the plugin's signer excludes the query string,
     * which is the same reason `/orders/documents` is a POST. It writes nothing.
     *
     * The figure it returns is the one the money leg will actually act on — the
     * same resolver, not a second opinion — so the box a merchant reads and the
     * refund they authorise cannot disagree.
     */
    public function state(Request $request, string $order): JsonResponse
    {
        $shop = $this->verifiedShop($request);
        if ($shop === null) {
            return response()->json(['error' => 'unauthorized'], Response::HTTP_UNAUTHORIZED);
        }

        return Tenant::run($shop, function () use ($shop, $order): JsonResponse {
            $preview = RefundPreview::for($shop, $order);

            return response()->json([
                'ok' => true,
                'state' => [
                    'refundable' => $preview->refundable,
                    'currency' => $preview->currency,
                    'rail' => $preview->rail,
                    'charges' => $preview->chargeCount,
                    // Whether a subscription hangs off this order: the box says
                    // so, because refunding money is not cancelling a plan and a
                    // merchant should not discover that next month.
                    'has_plan' => $preview->planId !== null,
                    'invoicing' => $preview->invoicingConnected,
                ],
            ]);
        });
    }

    // === Internals ===

    /**
     * The request the store's action implies, with the STORE LEG ALREADY DONE.
     *
     * WooCommerce is writing its own refund record — as the return value of
     * `process_refund`, or because it already wrote one. Calling back into the
     * store would put a SECOND refund record on the same order.
     */
    private function open(Shop $shop, string $orderId, float $amount, Request $request): RefundRequest
    {
        $refundRequest = app(RefundOrchestrator::class)->open($shop, [
            'mode' => RefundRequest::MODE_REFUND_PARTIAL,
            'amount' => $amount,
            'order_id' => $orderId,
            'reason' => $request->input('reason'),
            // The store made its own decision about stock; asking again would
            // return the same items twice.
            'restock' => false,
            // WooCommerce sends whatever notice it sends.
            'notify' => false,
            'requested_by' => self::ACTOR,
        ]);

        $refundRequest->recordLeg(
            'store_result',
            StoreRefundResult::skipped(RefundOrchestrator::STORE_APPLIED_BY_STORE)->toArray(),
        );

        return $refundRequest;
    }
}
