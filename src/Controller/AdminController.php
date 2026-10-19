<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\AdminAuthenticator;
use Areanet\ContentflyAdmin\Auth\LoginScreen;
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
 * WHAT THIS IS NOT YET: a user interface for working. Signing in is now a page a person can
 * use, but the index behind it is still the proof that the mount and the asset path work —
 * layout, navigation and the menu from the schema are story 002-002. That is also why the
 * index page is still built by concatenating strings while the login mask is a Twig template:
 * the mask is new and takes input, the index page is an existing proof artifact that 002-002
 * replaces wholesale. One rendering path is leftover and has a named successor, which is
 * better than rewriting a proof in passing (ADR 0010).
 *
 * THE ACTIONS STAY THIN. What decides anything lives next door: the session mechanics in
 * `AdminAuthenticator` (ADR 0009), the mask and its form token in `LoginScreen`.
 */
final class AdminController
{
    /** Wrong alias or password — deliberately not saying which of the two. */
    private const MESSAGE_CREDENTIALS = 'Benutzername oder Passwort ist falsch.';

    /** The core's throttle has had enough; `%d` is its own `Retry-After` in seconds. */
    private const MESSAGE_THROTTLED = 'Zu viele Fehlversuche. Bitte in %d Sekunden erneut versuchen.';

    /** The form token did not match — a stale form, a second tab, or a forged post. */
    private const MESSAGE_TOKEN = 'Das Formular war nicht mehr gültig. Bitte erneut anmelden.';

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
         *
         * TURBO IS IMPORTED FOR ITS SIDE EFFECT. The vendored ES module calls `start()` at top
         * level, so the bare import is the whole wiring. It belongs on this page and not only
         * on the login mask: without it the redirect a successful sign-in sends the browser to
         * would arrive as an ordinary page load, and the Turbo-conformant submit would be
         * provable only on the way in, never on the way through.
         */
        $assets = '<link rel="stylesheet" href="'
            .htmlspecialchars($manager->assetUrl('admin.css'), ENT_QUOTES, 'UTF-8')
            .'">'
            .Importmap::scriptTag($manager)
            .'<script type="module">'
            ."import '@hotwired/turbo';"
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
     * Serves the sign-in mask and takes its submission.
     *
     * ONE ROUTE FOR BOTH METHODS, AND THAT IS NOT A STYLE CHOICE. `CustomControllerProvider`
     * keys the routes of one provider by their **name** (`$this->routes[$routeName]`), so a
     * `get('login', …)` next to a `post('login', …)` does not add a second route — it silently
     * replaces the first. The core offers `match()` for precisely this case, a route without a
     * method restriction, and the branch then happens here. Registering the mask on a mount of
     * its own was the other way out and a worse one: a mount's own route carries the empty name,
     * so the address would have become `/admin/login/` and the bare form would answer 405.
     *
     * THE ORDER OF THE TWO CHECKS MATTERS. The form token is verified **before** the credentials
     * ever reach the core. Checking it afterwards would let a forged post drive the core's login
     * throttle, which is a way to lock a person out of their own admin without ever knowing
     * their password.
     */
    public function loginAction(Request $request): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        /** @var LoginScreen $screen */
        $screen = $this->app[LoginScreen::CONTAINER_KEY];

        if (!$request->isMethod('POST')) {
            return $screen->show($request);
        }

        $alias = (string) ($request->request->all()['alias'] ?? '');

        if (!$screen->verifyToken($request)) {
            return $screen->reject($request, self::MESSAGE_TOKEN, $alias);
        }

        /** @var AdminAuthenticator $authenticator */
        $authenticator = $this->app[AdminAuthenticator::CONTAINER_KEY];

        $result = $authenticator->login(
            $request,
            $alias,
            (string) ($request->request->all()['pass'] ?? '')
        );

        if (!$result['ok']) {
            if ($result['retryAfter'] !== null) {
                return $screen->reject(
                    $request,
                    sprintf(self::MESSAGE_THROTTLED, $result['retryAfter']),
                    $alias,
                    array('Retry-After' => (string) $result['retryAfter'])
                );
            }

            return $screen->reject($request, self::MESSAGE_CREDENTIALS, $alias);
        }

        /*
         * 303 AND NOT 302. After a POST, 303 is the code that tells every client to follow up
         * with a GET; 302 leaves that to the client's goodwill. Turbo follows both, but the
         * admin should not depend on which of the two a browser or a proxy decides to be
         * generous about.
         */
        $prefix = $this->app[AdminManager::CONTAINER_KEY]->getPrefix();

        return $authenticator->withSessionCookie(
            new RedirectResponse($prefix.'/', 303),
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
     * THE REDIRECT GOES TO THE MASK, which is what story 002-001-0002 said it would once the
     * mask existed. Sending the browser to `/admin/` instead would be a redirect into the
     * rejection the mask was built to replace.
     */
    public function logoutAction(Request $request): Response
    {
        /** @var AdminAuthenticator $authenticator */
        $authenticator = $this->app[AdminAuthenticator::CONTAINER_KEY];

        if (!$authenticator->logout($request)) {
            return new Response('', 500);
        }

        $prefix = $this->app[AdminManager::CONTAINER_KEY]->getPrefix();

        return $authenticator->withoutSessionCookie(
            new RedirectResponse($prefix.'/login', 303),
            $request
        );
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
