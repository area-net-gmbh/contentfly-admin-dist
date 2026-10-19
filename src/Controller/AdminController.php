<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\AdminAuthenticator;
use Areanet\ContentflyAdmin\Schema\CoreVersionMismatch;
use Areanet\ContentflyAdmin\Schema\VersionGuard;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's own routes.
 *
 * NOT A SUBCLASS OF THE CORE'S `BaseController`, on purpose. That base class answers in the
 * API envelope (`data`, `errors`, `meta`) and brings the entity manager along; the admin
 * serves HTML to a browser and needs neither. What it does share is the constructor
 * signature — `CustomControllerProvider::connect()` builds every controller as
 * `new $controllerName($app)`, so that shape is fixed from the outside.
 *
 * WHAT THIS IS NOT YET: a user interface. The index page is the proof that the mount works and
 * nothing more, and the two auth routes next to it answer in status codes rather than in pages —
 * they exist so the sign-in can be exercised end to end before there is anything to look at. The
 * login screen and the Turbo-conformant submit are story 002-001-0003, layout and navigation are
 * 002-002.
 */
final class AdminController
{
    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The admin's entry page.
     *
     * The action name is given explicitly when the route is registered. It has to be: the
     * core derives a missing action from the route name, and this route's name is the empty
     * string — which is what puts the page on the mount path itself.
     */
    public function indexAction(): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];

        $items = '';
        foreach ($manager->getEntities() as $entity) {
            $items .= '<li>'.htmlspecialchars($entity, ENT_QUOTES, 'UTF-8').'</li>';
        }

        $title = htmlspecialchars($manager->getTitle(), ENT_QUOTES, 'UTF-8');

        /*
         * THE PAGE IS STILL NOT A USER INTERFACE — it now also proves the asset path.
         *
         * Stylesheet, import map and the module below are what turns story 001-007 from "files
         * are reachable by URL" into "the browser actually loads and runs them". A stylesheet
         * that 404s looks exactly like one that was never linked, so the proof has to happen in
         * a page, not only in a `curl` call.
         *
         * `data-controller="hello"` is the hook Stimulus looks for; the controller sets
         * `data-admin-assets-ready` on connect, which is a state a check can read without a
         * human looking at the screen.
         */
        $assets = '<link rel="stylesheet" href="'
            .htmlspecialchars($manager->assetUrl('admin.css'), ENT_QUOTES, 'UTF-8')
            .'">'
            .Importmap::scriptTag($manager)
            .'<script type="module">'
            ."import { Application } from '@hotwired/stimulus';"
            ."import HelloController from 'admin/hello';"
            .'Application.start().register(\'hello\', HelloController);'
            .'</script>';

        $html = '<!doctype html>'
            .'<html lang="de"><head><meta charset="utf-8"><title>'.$title.'</title>'
            .$assets
            .'</head>'
            .'<body data-controller="hello"><h1>'.$title.'</h1><ul>'.$items.'</ul></body></html>';

        return new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));
    }

    /**
     * Takes the credentials of the login form and turns a successful sign-in into a session.
     *
     * THIS ACTION IS DELIBERATELY THIN. Everything that decides anything — how the core is asked,
     * which token is requested, what the cookie looks like — lives in `AdminAuthenticator`,
     * because ADR 0009 puts the one meeting point of the admin's session and the core's token
     * expectation there and not in a controller.
     *
     * NO HTML, IN EITHER DIRECTION, and that is the seam to story 002-001-0003. This route exists
     * so the authenticator can be exercised end to end against a running instance; the login
     * screen, the Turbo-conformant submit and a readable rejection are that story's work. A
     * rejection is therefore the core's status code and nothing else — 401 for wrong credentials,
     * 429 plus `Retry-After` when the core's throttle has had enough.
     */
    public function loginAction(Request $request): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        /** @var AdminAuthenticator $authenticator */
        $authenticator = $this->app[AdminAuthenticator::CONTAINER_KEY];

        $result = $authenticator->login(
            $request,
            (string) ($request->request->all()['alias'] ?? ''),
            (string) ($request->request->all()['pass'] ?? '')
        );

        if (!$result['ok']) {
            $headers = $result['retryAfter'] !== null
                ? array('Retry-After' => (string) $result['retryAfter'])
                : array();

            return new Response('', $result['status'], $headers);
        }

        $prefix = $this->app[AdminManager::CONTAINER_KEY]->getPrefix();

        return $authenticator->withSessionCookie(
            new RedirectResponse($prefix.'/'),
            $request,
            $result['token']
        );
    }

    /**
     * Ends the session — at the core and in the browser.
     *
     * BOTH HALVES OR NEITHER. Clearing the cookie alone would only make the browser forget a
     * token that stays valid for anyone who still has it, which is the opposite of what logging
     * out is for. The cookie is therefore cleared after the core has confirmed, and the route is
     * mounted `isSecure = true` so that the core's guard has set `auth.token` by the time the
     * authenticator asks it to be dropped.
     *
     * 204 AND NOT A REDIRECT, for now. The honest target would be the login screen, and that does
     * not exist yet — it arrives with story 002-001-0003, which is where this turns into a
     * redirect. Sending the browser to `/admin/` instead would answer 401 and call it a feature.
     */
    public function logoutAction(Request $request): Response
    {
        /** @var AdminAuthenticator $authenticator */
        $authenticator = $this->app[AdminAuthenticator::CONTAINER_KEY];

        if (!$authenticator->logout($request)) {
            return new Response('', 500);
        }

        return $authenticator->withoutSessionCookie(new Response('', 204), $request);
    }

    /**
     * Runs the version check and turns a refusal into the response the browser gets.
     *
     * Returns `null` when the admin may proceed, so every entry point added later is one `if`
     * away from being guarded too.
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
            $message = htmlspecialchars($mismatch->getMessage(), ENT_QUOTES, 'UTF-8');

            $html = '<!doctype html>'
                .'<html lang="en"><head><meta charset="utf-8">'
                .'<title>Contentfly Admin — version mismatch</title></head>'
                .'<body><h1>Contentfly Admin cannot start</h1><p>'.$message.'</p></body></html>';

            return new Response($html, 500, array('Content-Type' => 'text/html; charset=UTF-8'));
        }

        return null;
    }
}
