<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\CoreLog;
use Areanet\ContentflyAdmin\Schema\Envelope;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The one way the admin writes to the core: an internal sub-request, read as an envelope.
 *
 * IT IS A CONSOLIDATION, NOT A NEW LAYER. These three lines stood five times — `TreeController`,
 * `BulkController`, `FormController`, `FileController` and `Page\AbstractAdminPage` each had
 * their own copy, identical but for whether a file rode along. Story 007-001-0002 needed one
 * place that knows *which* core endpoint was called and *how it answered*, and five copies of
 * the call meant five places to ask. Merging them was cheaper than instrumenting them.
 *
 * `AdminAuthenticator` IS DELIBERATELY NOT A SIXTH USER. Its sub-request is the only one that
 * does not read an envelope: it reads the token out of the response headers and answers its own
 * shape (`src/Auth/AdminAuthenticator.php`). Folding it in would mean a return type that is an
 * envelope for five callers and something else for one, which is how a shared helper stops being
 * one. It stays as it is, and so does the sign-in path.
 *
 * THE SERVER PARAMETERS ARE CARRIED OVER, and that is load-bearing rather than tidy: the core
 * checks its per-record permission against the guarded request's `auth.user`, and ADR 0014
 * records that a sub-request from an `isSecure = false` route fails in a way that looks like
 * "not signed in". The data goes in the `request` bag and not a JSON body, because the core's
 * `before` hooks do not run in a sub-request and nothing there unpacks a body.
 *
 * ONLY FAILURES ARE LOGGED, and only what cannot carry content — see `CoreLog`.
 */
trait CallsCore
{
    /**
     * `$status` IS AN OUT-PARAMETER AND ONLY ONE CALLER READS IT. `FileController` needs the raw
     * status for its per-file report: with no readable envelope it shows `HTTP <status>` as the
     * reason, because that is the only thing left to say. It stays an optional reference rather
     * than becoming part of the return value, which would change the envelope shape every other
     * caller passes straight on — and rather than leaving `FileController` its own copy of the
     * call, which is the duplication this trait exists to end.
     *
     * @param array<string,mixed> $payload
     * @param array<string,mixed> $files   The main request's `UploadedFile` instances, passed
     *                                     through untouched and never rebuilt (ADR 0021)
     * @param int|null            $status  Set to the sub-request's HTTP status
     *
     * @return array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null}
     */
    private function callCore(Request $request, string $endpoint, array $payload, array $files = array(), ?int &$status = null): array
    {
        $sub = Request::create($endpoint, 'POST', $payload, array(), $files, $request->server->all());

        $response = $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST);
        $status   = $response->getStatusCode();
        $envelope = Envelope::read($response->getContent(), $status);

        if (!$envelope['ok']) {
            $this->app[CoreLog::CONTAINER_KEY]->failedCall($endpoint, $status, $envelope);
        }

        return $envelope;
    }
}
