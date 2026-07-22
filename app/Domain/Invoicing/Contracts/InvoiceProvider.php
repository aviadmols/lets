<?php

namespace App\Domain\Invoicing\Contracts;

use App\Domain\Invoicing\IssueDocumentRequest;
use App\Domain\Invoicing\IssuedDocumentResult;

/**
 * One accounting-document provider, bound to ONE shop's credentials.
 *
 * Implementations are built by InvoiceProviderFactory::for($shop), which decrypts
 * the shop's bag ONCE and injects it as constructor state. An instance is never
 * reused across shops and never reads credentials from config() at call time —
 * the same tenancy law the PayPlus gateway follows.
 *
 * CONTRACT: issue() NEVER throws. A transport error, a rejected payload, or an
 * expired token all come back as IssuedDocumentResult::failed(). The document
 * path hangs off the money path, and it must never be able to disturb it.
 */
interface InvoiceProvider
{
    /** The provider discriminator stored on issued_documents.provider. */
    public function name(): string;

    /**
     * A NON-issuing credential probe for the settings screen's "Test connection".
     * Obtains an access token and nothing else — it must never mint a document.
     *
     * @return array{0:bool, 1:?string} [ok, reasonCode]
     */
    public function testConnection(): array;

    public function issue(IssueDocumentRequest $request): IssuedDocumentResult;
}
