<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\AdminAuthenticator;
use Areanet\ContentflyAdmin\Auth\LoginScreen;
use Areanet\ContentflyAdmin\Schema\CoreVersionMismatch;
use Areanet\ContentflyAdmin\Schema\VersionGuard;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
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
 * EVERY PAGE GOES THROUGH TWIG AND THE SHARED LAYOUT since 002-002-0003. The last string
 * concatenation — the index page, a proof artifact from story 001-007 — is gone, which is what
 * ADR 0010 deferred to this story rather than doing in passing.
 *
 * WHAT THIS IS NOT YET: a user interface for working. There is a page, a title and the
 * entities of the schema, but nothing to open yet — list, form and the menu that links to them
 * are stories 002-002-0002 and 002-003 onwards.
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
     *
     * THE FOUR LAYOUT VARIABLES ARE ASSEMBLED HERE, like `LoginScreen` does for the mask.
     * `layout.html.twig` requires `title`, `stylesheet` and `importmap` in every render
     * context and fails loudly on a missing one (`strict_variables`); `controllers` is the
     * optional declaration that makes the layout load Stimulus and register `hello`.
     *
     * THE MENU IS THE `MenuBuilder`'S, and handing it over is what makes the layout render the
     * navigation at all. `logoutUrl` travels with it, because a menu without a way out is not
     * one. The builder resolves the project's `entities` whitelist through the manager, so
     * there is exactly one answer in the package to what the admin administers.
     */
    public function indexAction(): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('index.html.twig', array(
            'title'       => $manager->getTitle(),
            'stylesheet'  => $manager->assetUrl('admin.css'),
            'importmap'   => Importmap::scriptTag($manager),
            'controllers' => array('hello' => 'admin/hello'),
            'menu'        => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'   => $manager->getPrefix().'/logout',
        ));

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
