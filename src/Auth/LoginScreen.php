<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Auth;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything about the login form: how it looks in each of its states, and the token that
 * proves a submission came from it.
 *
 * WHY THIS IS NOT IN THE CONTROLLER. The controller's job is to route a request to a decision
 * and hand back what that decision produced; `AdminController` is deliberately thin and stays
 * that way (ADR 0009 puts the session mechanics in `AdminAuthenticator` for the same reason).
 * The mask has three states — empty, wrong credentials, throttled — plus a form token with its
 * own rules, and that is a thing of its own.
 *
 * THE FORM TOKEN IS NOT THE `X-XSRF-TOKEN` MECHANISM ADR 0009 NAMED, and that is a correction,
 * not an omission. In the core, `X-XSRF-TOKEN` is one of five *token sources* for the auth
 * token (`Classes/Security/TokenSources.php`) — a place the core looks for the credential, not
 * a CSRF channel. The double-submit pattern the ADR meant by it needs a cookie that JavaScript
 * can read, and the admin's session cookie is `HttpOnly` by the same ADR's decision. So the
 * form gets a double-submit token of its own: a second cookie plus a hidden field, compared on
 * the server. No JavaScript reads it, nothing is stored server-side, and the session cookie
 * stays `HttpOnly`.
 *
 * WHAT IT PROTECTS, STATED HONESTLY: login CSRF — a third party cannot make someone's browser
 * sign in as an account the attacker controls. The CSRF surface that matters more is the one
 * around *writing* data, and that belongs to the story that introduces writes (`002-006`), not
 * here, where there is nothing yet to write.
 */
final class LoginScreen
{
    /** Where the screen puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.login_screen';

    /**
     * The form token's cookie.
     *
     * A SECOND COOKIE AND NOT THE SESSION ONE, because the two have opposite lifecycles: this
     * one has to exist *before* anybody is signed in, which is precisely when there is no
     * session cookie to hang it on.
     */
    public const TOKEN_COOKIE_NAME = 'contentfly_admin_form';

    /** The hidden field carrying the same value back. */
    public const TOKEN_FIELD = '_token';

    private AdminManager $manager;

    private TemplateRenderer $renderer;

    public function __construct(AdminManager $manager, TemplateRenderer $renderer)
    {
        $this->manager  = $manager;
        $this->renderer = $renderer;
    }

    /** The empty mask, as a `GET /admin/login` gets it. */
    public function show(Request $request): Response
    {
        return $this->respond($request, 200, null, '');
    }

    /**
     * The mask again, carrying a message, as a rejected `POST /admin/login` gets it.
     *
     * 422 AND NOT 401 OR 429, and this is the one place the task changes a contract story
     * 002-001-0002 established. Turbo treats a form submission that answers 2xx as a
     * navigation it cannot apply and a redirect as success; the documented way to say "your
     * input was wrong, here is the form again" is a 422 with the re-rendered form. Answering
     * 401 would leave the browser on the old page with the error thrown away.
     *
     * The distinction between wrong credentials and throttling therefore moves out of the
     * status code and into the message — which is where a person reads it anyway. On the
     * throttled path `Retry-After` is passed through, so the machine-readable half is not lost.
     *
     * @param array<string,string> $headers
     */
    public function reject(Request $request, string $message, string $alias, array $headers = array()): Response
    {
        return $this->respond($request, 422, $message, $alias, $headers);
    }

    /**
     * Whether this submission carries the token the form was rendered with.
     *
     * `hash_equals` RATHER THAN `===` — the comparison is against a secret the client supplies,
     * and a timing-safe compare costs nothing here.
     *
     * A MISSING COOKIE IS A FAILURE, not a reason to skip the check. Treating "no cookie" as
     * "nothing to verify" would turn the whole mechanism off for exactly the request that
     * forged one.
     */
    public function verifyToken(Request $request): bool
    {
        $expected  = $request->cookies->get(self::TOKEN_COOKIE_NAME);
        $submitted = $request->request->all()[self::TOKEN_FIELD] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /**
     * Renders the mask and makes sure a usable token travels with it.
     *
     * AN EXISTING TOKEN IS REUSED, never replaced. Issuing a fresh one on every render would
     * invalidate the form a second browser tab is already showing — the person fills in that
     * tab, submits, and is told their session is wrong for no reason they can see.
     *
     * @param array<string,string> $headers
     */
    private function respond(
        Request $request,
        int $status,
        ?string $error,
        string $alias,
        array $headers = array()
    ): Response {
        $existing = $request->cookies->get(self::TOKEN_COOKIE_NAME);
        $reused   = is_string($existing) && $existing !== '';
        $token    = $reused ? $existing : bin2hex(random_bytes(32));

        $prefix = $this->manager->getPrefix();

        $html = $this->renderer->render('login.html.twig', array(
            'title'      => $this->manager->getTitle(),
            'action'     => $prefix.'/login',
            'stylesheet' => $this->manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($this->manager),
            'tokenField' => self::TOKEN_FIELD,
            'token'      => $token,
            'alias'      => $alias,
            'error'      => $error,
        ));

        $response = new Response(
            $html,
            $status,
            array_merge(array('Content-Type' => 'text/html; charset=UTF-8'), $headers)
        );

        if (!$reused) {
            $response->headers->setCookie($this->tokenCookie($request, $token));
        }

        return $response;
    }

    /**
     * The token cookie, with the same attributes as the session cookie.
     *
     * `HttpOnly` IS CORRECT EVEN THOUGH THIS IS A DOUBLE-SUBMIT TOKEN. The classic pattern has
     * JavaScript read the cookie and copy it into a header; here the *server* renders the value
     * into the form, so nothing in the browser ever needs to read it. Keeping it unreadable
     * costs nothing and removes it as a target for injected script.
     *
     * No lifetime, for the same reason the session cookie has none: it is only meaningful for
     * as long as the form it belongs to is open.
     */
    private function tokenCookie(Request $request, string $value): Cookie
    {
        $prefix = $this->manager->getPrefix();

        return Cookie::create(
            self::TOKEN_COOKIE_NAME,
            $value,
            0,
            $prefix === '' ? '/' : $prefix,
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }
}
