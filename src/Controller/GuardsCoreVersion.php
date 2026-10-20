<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\Schema\CoreVersionMismatch;
use Areanet\ContentflyAdmin\Schema\VersionGuard;
use Symfony\Component\HttpFoundation\Response;

/**
 * The version check every page of the admin runs before it renders anything.
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

        return null;
    }

    /**
     * The one plain page the admin falls back to when it cannot render its own layout.
     *
     * NO TWIG HERE ON PURPOSE. Both callers are failures of the setup the layout itself depends
     * on, so rendering them through the template stack would risk failing a second time while
     * reporting the first.
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
