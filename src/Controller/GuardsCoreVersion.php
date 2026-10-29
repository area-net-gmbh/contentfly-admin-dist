<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\Schema\CoreVersionMismatch;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\Schema\VersionGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * The two checks every page of the admin runs before it renders anything: that the core it is
 * installed next to fits, and that the project's own `ui` configuration names things the schema
 * actually has. Both are failures of the installation, both answer with the same plain page.
 *
 * IT BECAME A TRAIT WHEN THE SECOND CONTROLLER ARRIVED. `AdminController::guard()` was written
 * with the note that "every entry point added later is one `if` away from being guarded too" —
 * `ListController` is that entry point, and the alternative was a second copy of the refusal
 * page. A page that exists twice is a page that will say two different things.
 *
 * A TRAIT AND NOT A BASE CLASS, because `CustomControllerProvider::connect()` constructs every
 * controller as `new $controllerName($app)` and that signature is fixed from the outside. A base
 * class would be a second place that has to honour it for no gain; the trait reads `$this->app`,
 * which both controllers already hold.
 */
trait GuardsCoreVersion
{
    /**
     * Runs the version check and turns a refusal into the response the browser gets.
     *
     * Returns `null` when the admin may proceed.
     *
     * HTTP 500 AND NOT 503: a package that does not fit the core it is installed next to is a
     * deployment mistake, not a temporary condition, and 503 invites a client to retry something
     * that will not change by itself.
     *
     * The message is shown rather than only logged. It names both versions and the expected
     * range, which is the whole point — whoever opens the admin and sees this needs no second
     * step to resolve it. It discloses the two package versions involved and nothing else: no
     * paths, no configuration, no data.
     */
    private function guard(): ?Response
    {
        try {
            $this->app[VersionGuard::CONTAINER_KEY]->check();
        } catch (CoreVersionMismatch $mismatch) {
            return self::refusal('Contentfly Admin cannot start', $mismatch->getMessage());
        }

        /*
         * THE CONFIGURATION IS VALIDATED HERE BECAUSE HERE IS BEFORE THE LAYOUT. `applyUi()`
         * raises `\InvalidArgumentException` for a `ui` option naming an entity or a field the
         * schema does not have, and until story 007-001 nobody caught it — a single typo in
         * `custom/admin.php` answered with a blank page. It cannot be reported *through* the
         * layout either, because `MenuBuilder` reads the same schema and would fail a second
         * time while reporting the first; that is the reason `refusal()` exists.
         *
         * IT COSTS NO EXTRA READ. All eleven users of this trait render a page that reads the
         * schema anyway, and `SchemaClient` holds it for the process after the first call. The
         * login screen does not use this trait, so the sign-in path is unchanged.
         *
         * The message is the one `applyUi()` already writes: it names the unknown entity or
         * field and lists the known ones, in English, because whoever reads it is installing
         * the package (ADR 0033).
         */
        try {
            $this->app[SchemaClient::CONTAINER_KEY]->getSchema();
        } catch (\InvalidArgumentException $misconfigured) {
            return self::refusal('Contentfly Admin is misconfigured', $misconfigured->getMessage());
        } catch (\Throwable $unreachable) {
            /*
             * ANYTHING ELSE `Api::getSchema()` CAN RAISE — a corrupt `data/cache/schema.cache`,
             * a type error inside the core's own reader. It is a narrower net than it looks, and
             * the measurement that drew its border is worth recording: taking the harness
             * database away does NOT arrive here. The core's `RouteSecurityListener` authenticates
             * on `kernel.request` and `TokenHandler` reaches the database there, so a database
             * that is gone fails before any admin controller runs — the stack trace of that run
             * carries no frame of this package at all. The one place the admin can answer for it
             * is the kernel error handler next to `AdminManager::registerLoginRedirect()`, which
             * is why story 007-001 moved that catch into 007-001-0002 rather than spreading it
             * over the reads.
             *
             * THE EXCEPTION MESSAGE IS DELIBERATELY NOT SHOWN, unlike the misconfiguration above
             * where `applyUi()` wrote the sentence itself. What reaches here is the core's or a
             * driver's own text, and a driver exception carries the DSN — host, user, database.
             * The detail belongs in the log, which is 007-001-0002.
             */
            return self::refusal(
                'Contentfly Admin cannot read the schema',
                'The core failed while answering its schema, and the admin cannot render '
                .'without it. A stale or unreadable data/cache/schema.cache is the usual cause. '
                .'The underlying error is not shown here because it may name the database '
                .'connection; look in the server log for it.'
            );
        }

        return null;
    }

    /**
     * The one plain page the admin falls back to when it cannot render its own layout.
     *
     * NO TWIG HERE ON PURPOSE. All three callers are failures of the setup the layout itself
     * depends on — a core that does not fit, a core version that is not declared, a `ui` option
     * naming an unknown entity — so rendering them through the template stack would risk failing
     * a second time while reporting the first. An editor-facing failure of a *read* keeps the
     * layout; that page is `ReportsCoreFailure::coreFailurePage()`.
     */
    private static function refusal(string $heading, string $message): Response
    {
        $heading = htmlspecialchars($heading, ENT_QUOTES, 'UTF-8');
        $message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

        $html = '<!doctype html>'
            .'<html lang="en"><head><meta charset="utf-8">'
            .'<title>'.$heading.'</title></head>'
            .'<body><h1>'.$heading.'</h1><p>'.$message.'</p></body></html>';

        return new Response($html, 500, array('Content-Type' => 'text/html; charset=UTF-8'));
    }
}
