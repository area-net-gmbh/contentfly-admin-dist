<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Auth;

use Areanet\PIM\Classes\Kernel\ApplicationInterface;
use Areanet\PIM\Classes\Security\TokenSources;
use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The one place where the admin's session and the core's token expectation meet.
 *
 * The core has neither a session nor cookie handling — it dropped both in its story `012-004`,
 * because an exclusive file lock serialised parallel API calls of one user — and none of the five
 * places its token check looks at is a cookie (`Classes/Security/TokenSources.php`). So the admin
 * brings the carrier itself: it keeps the token in a cookie of its own and translates that cookie
 * into the `appcms-token` header on every request, server side, before the core's guard runs.
 * That translation is why this class exists and why it is not a controller. Recorded in ADR 0009.
 *
 * SIGNING IN GOES THROUGH THE REAL `/auth` ROUTE, as an internal sub-request. The core has no
 * reusable credential service — login and logout are request-bound controller actions — so any
 * other way would mean rebuilding their orchestration, the `LoginThrottle` included. The package's
 * other door to the core, `Schema\SchemaClient`, deliberately goes in-process instead; that is not
 * a contradiction but the same rule applied twice: use what the core offers in the shape it offers
 * it. The schema is a value, signing in is an action that an action route already performs.
 *
 * THE OPAQUE TOKEN, NOT A JWT, and therefore no refresh at all. A refresh exists only for
 * `tokenType=jwt`, and it is rotating and single-use — two parallel Turbo requests holding the
 * same expired token would turn into a 401 for the second one. The opaque token's sliding timeout
 * extends on every request instead, so an editor at work never expires.
 */
final class AdminAuthenticator
{
    /**
     * Where the authenticator puts itself, namespaced like the manager's key so it cannot collide
     * with a project's own.
     */
    public const CONTAINER_KEY = 'contentfly_admin.authenticator';

    /**
     * The name of the admin's own session cookie.
     *
     * A CONSTANT AND NOT AN OPTION, which answers the question ADR 0009 left open — how an auth
     * configuration value would reach this package at all. It does not, because nothing here needs
     * one: the manager's option whitelist stays as it is. The moment a project has to rename this
     * cookie, or a JWT switch arrives, that is when the whitelist grows a slot — not before.
     */
    public const COOKIE_NAME = 'contentfly_admin_session';

    private ApplicationInterface $app;

    /** The admin's mount prefix, without a trailing slash — the scope the cookie is bound to. */
    private string $prefix;

    public function __construct(ApplicationInterface $app, string $prefix)
    {
        $this->app    = $app;
        $this->prefix = $prefix;
    }

    /**
     * Signs in against `/auth/login` and reports what the core answered.
     *
     * THE CREDENTIALS GO INTO THE `request` BAG, NOT INTO A JSON BODY. For a sub-request none of
     * the core's `before` hooks run (`Classes/Kernel/Application.php`, the main-request guard in
     * `before()`), and its JSON body decoding is one of them — a JSON body would arrive and never
     * be unpacked. `loginAction` reads `$request->request->all()['alias'|'pass']`, so that is where
     * they are put.
     *
     * THE SERVER BAG OF THE MAIN REQUEST IS PASSED ALONG, and that is not cosmetic.
     * `Request::create()` would otherwise set `REMOTE_ADDR` to `127.0.0.1`, and the IP axis of the
     * core's `LoginThrottle` would then count every admin login of every client into one bucket —
     * the throttle would still fire, just at the wrong people.
     *
     * No `tokenType` is sent, which is what asks for the opaque token.
     *
     * @return array{ok:bool,status:int,token:?string,retryAfter:?int} `token` is set exactly when
     *         `ok` is true; `retryAfter` carries the core's `Retry-After` on a 429 and is null
     *         otherwise
     */
    public function login(Request $request, string $alias, string $password): array
    {
        $sub = Request::create(
            '/auth/login',
            'POST',
            ['alias' => $alias, 'pass' => $password],
            [],
            [],
            $request->server->all()
        );

        $response = $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST);

        $status = $response->getStatusCode();
        $token  = $this->readToken($response);

        return [
            'ok'         => $status === 200 && $token !== null,
            'status'     => $status,
            'token'      => $status === 200 ? $token : null,
            'retryAfter' => $this->readRetryAfter($response),
        ];
    }

    /**
     * Ends the session at the core and reports whether the core accepted it.
     *
     * ONLY CALLABLE FROM A SECURED ROUTE, and the reason is worth stating because nothing fails
     * loudly otherwise. `logoutAction` acts on `$app['auth.token']` and `$app['auth.user']`, and
     * those are set by `authenticate()` of the **main** request alone — the per-route guard skips
     * sub-requests by design (`Classes/Kernel/Routing/RouteSecurityListener.php`). Called from an
     * unsecured route the core would answer 200 and delete nothing: the browser would lose its
     * cookie while the token stayed valid for anyone still holding it.
     *
     * `/auth/logout` is a GET.
     */
    public function logout(Request $request): bool
    {
        $sub = Request::create(
            '/auth/logout',
            'GET',
            [],
            [],
            [],
            $request->server->all()
        );

        return $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST)->getStatusCode() === 200;
    }

    /**
     * Hands the cookie's token to the core, as the header it does look at.
     *
     * Registered as an `$app->before()` hook, which runs on `kernel.request` and for the main
     * request only, while the core's route guard runs later, on `kernel.controller`. So the header
     * is in place in time.
     *
     * BOUND TO THE ADMIN'S OWN PREFIX. Without that check the admin's cookie would authenticate
     * every other request the browser makes in passing — a `/api` call would silently run as the
     * signed-in editor. That is the CSRF surface ADR 0009 accepted when it chose a cookie, and
     * this is where it is kept closed. The cookie's own `path` says the same thing a second time,
     * to the browser; this check is the half that does not depend on the client.
     *
     * An existing token on the request wins. Whoever already sends one of the core's five sources
     * means it — overwriting it from a cookie would make the admin's session impossible to bypass
     * for a deliberate API-style call.
     */
    public function applyTokenHeader(Request $request): void
    {
        if (!$this->isInScope($request)) {
            return;
        }

        $token = $request->cookies->get(self::COOKIE_NAME);

        if (!is_string($token) || $token === '') {
            return;
        }

        if (TokenSources::chain()->extractAccessToken($request) !== null) {
            return;
        }

        $request->headers->set(TokenSources::HEADER_ALT, $token);
    }

    /**
     * Attaches the session cookie to the admin's own response.
     *
     * IT NEVER GETS A LIFETIME. `expire = 0` makes it a session cookie, so the browser drops it
     * when it closes and the core alone decides how long the token is good for. A fixed lifetime
     * here would be a second, silently diverging answer to that question: the core's sliding
     * timeout comes from the user's group — in minutes — otherwise from `APP_TOKEN_TIMEOUT`, and
     * `APP_CHECK_TOKEN_TIMEOUT` can switch it off entirely.
     *
     * `secure` IS DERIVED, NOT HARDCODED. Hardcoding it would be the safer-looking choice and the
     * wrong one: the development harness serves plain HTTP, so a cookie that insists on HTTPS
     * would simply never come back and the admin would look broken rather than strict.
     */
    public function withSessionCookie(Response $response, Request $request, string $token): Response
    {
        $response->headers->setCookie($this->cookie($request, $token));

        return $response;
    }

    /**
     * Clears the session cookie on the admin's own response.
     *
     * Written as an empty cookie that has already expired rather than through `clearCookie()`, so
     * name, path, secure and SameSite come from the same place as when it was set — a cleared
     * cookie whose attributes differ from the original is one the browser keeps.
     */
    public function withoutSessionCookie(Response $response, Request $request): Response
    {
        $response->headers->setCookie($this->cookie($request, '', 1));

        return $response;
    }

    /** Whether the request carries an admin session at all. */
    public function hasSession(Request $request): bool
    {
        $token = $request->cookies->get(self::COOKIE_NAME);

        return is_string($token) && $token !== '';
    }

    private function cookie(Request $request, string $value, int $expire = 0): Cookie
    {
        return Cookie::create(
            self::COOKIE_NAME,
            $value,
            $expire,
            $this->prefix === '' ? '/' : $this->prefix,
            null,
            $request->isSecure(),
            true,
            false,
            Cookie::SAMESITE_LAX
        );
    }

    /**
     * Whether this request is one of the admin's own.
     *
     * The comparison is against the prefix **plus a separator**, never a bare `str_starts_with`:
     * a project mounted on `/admin` must not hand its cookie to a `/administration` of its own.
     */
    private function isInScope(Request $request): bool
    {
        $path = $request->getPathInfo();

        return $path === $this->prefix || str_starts_with($path, $this->prefix.'/');
    }

    /**
     * Digs the token out of the core's envelope.
     *
     * The shape is `{"data": {"token": …, "user": …}, "errors": null, "meta": …}`; on a rejection
     * `data` is null, so a missing token is the normal failure path and not an anomaly.
     */
    private function readToken(Response $response): ?string
    {
        $body = json_decode((string) $response->getContent(), true);

        if (!is_array($body) || !isset($body['data']['token'])) {
            return null;
        }

        $token = $body['data']['token'];

        return is_string($token) && $token !== '' ? $token : null;
    }

    private function readRetryAfter(Response $response): ?int
    {
        $value = $response->headers->get('Retry-After');

        return is_string($value) && ctype_digit($value) ? (int) $value : null;
    }
}
