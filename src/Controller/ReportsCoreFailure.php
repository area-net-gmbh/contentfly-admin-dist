<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Symfony\Component\HttpFoundation\Response;

/**
 * What a route does when a read against the core fails in a way nobody planned for.
 *
 * IT EXISTS BECAUSE ONLY ONE EXCEPTION TYPE WAS EVER CAUGHT. Before story 007-001 the package
 * caught `ContentflyException` in three places and `CoreVersionMismatch` in one, and nothing
 * else anywhere — so every other failure of the core reached the editor as the core's debug page
 * or as a blank one. That is not a hypothetical class of failure: ADR 0006 records that
 * `Api::getExtendedSchema()` dereferences `->getGroup()` unguarded and that
 * `Permission::isReadable(User $user, …)` takes the user typed and non-nullable, so a missing
 * `auth.user` raises a `\TypeError`, not a `ContentflyException`.
 *
 * `ContentflyException` IS DELIBERATELY NOT HANDLED HERE. Where the package already catches it —
 * `FormController::load()`, `FileController`, `View\RelationOptions` — it means "not there, or
 * not yours" and becomes a 404 or an empty option list, which is the per-record rule of ADR 0019
 * working as intended. Routing it through this trait would dress a known answer up as a failure.
 * `ListController` is the exception: it caught nothing at all, so it passes both kinds here and
 * says which is which at the call site.
 *
 * THE MESSAGE IS GERMAN AND THE PAGE KEEPS THE LAYOUT (ADR 0033): whoever meets this is an
 * editor in a working admin whose last click did not come back. A failure of the *installation*
 * reads differently and is not here at all — a core that does not fit and a `ui` option naming
 * an unknown entity are both English, both without Twig, and both answered by
 * `GuardsCoreVersion::guard()` before this trait is ever reached.
 */
trait ReportsCoreFailure
{
    use RendersRefusalPage;

    /**
     * The page an editor gets when the core could not answer a read.
     *
     * HTTP 500 AND NOT 502: there is no second host to be a gateway to. The admin runs in the
     * core's own process (ADR 0006), so a core that fails is this application failing.
     *
     * It names what did not load and nothing else — no exception message, no class name, no
     * query. The editor cannot act on any of it, and the exception text is the one part of a
     * failure that may carry field values. Where that text goes instead is 007-001-0002.
     *
     * @param string $what The thing that did not load, as an editor would name it
     */
    private function coreFailurePage(string $what): Response
    {
        return $this->refusalPage(
            'Nicht geladen',
            $what.' konnte nicht geladen werden, weil der Core nicht geantwortet hat. '
            .'Versuche es noch einmal — bleibt es dabei, melde dich bei einer Administratorin '
            .'oder einem Administrator.',
            500
        );
    }
}
