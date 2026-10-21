<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Auth;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * The admin's stateless double-submit token: a cookie plus a hidden field, compared on the
 * server.
 *
 * IT STARTED INSIDE `LoginScreen` AND MOVED HERE when the form view arrived (002-006-0004).
 * ADR 0010 closes by assigning the CSRF protection of *writing* actions to story 002-006, and
 * that gave the mechanism a second caller. The cookie's attributes are the part that must not
 * be duplicated: two writers that disagree on path, `SameSite` or `Secure` produce a token that
 * verifies on one page and silently fails on the other.
 *
 * WHY NOT THE CORE'S `X-XSRF-TOKEN`: in the core that header is one of five *sources* for the
 * auth token (`Classes/Security/TokenSources.php`), not a CSRF channel — the note at the top of
 * `LoginScreen` has the full reasoning, including why the session cookie stays `HttpOnly`.
 *
 * STATIC AND NOT A SERVICE, because it holds nothing: the state is the cookie in the browser
 * and the field in the form. A container entry would only add a place to look.
 */
final class FormToken
{
    /**
     * The token's cookie.
     *
     * A SECOND COOKIE AND NOT THE SESSION ONE, because the two have opposite lifecycles: this
     * one has to exist *before* anybody is signed in, which is precisely when there is no
     * session cookie to hang it on.
     */
    public const COOKIE_NAME = 'contentfly_admin_form';

    /** The hidden field carrying the same value back. */
    public const FIELD = '_token';

    /**
     * The value the next form should carry — the one already in the browser, or a fresh one.
     *
     * AN EXISTING TOKEN IS REUSED, never replaced. Issuing a fresh one on every render would
     * invalidate the form a second browser tab is already showing: the person fills in that tab,
     * submits, and is told their session is wrong for no reason they can see.
     */
    public static function issue(Request $request): string
    {
        $existing = $request->cookies->get(self::COOKIE_NAME);

        return is_string($existing) && $existing !== '' ? $existing : bin2hex(random_bytes(32));
    }

    /** Whether the browser already holds the value `issue()` just returned. */
    public static function isIssued(Request $request, string $token): bool
    {
        return $request->cookies->get(self::COOKIE_NAME) === $token;
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
    public static function verify(Request $request): bool
    {
        $expected  = $request->cookies->get(self::COOKIE_NAME);
        $submitted = $request->request->all()[self::FIELD] ?? null;

        if (!is_string($expected) || $expected === '' || !is_string($submitted) || $submitted === '') {
            return false;
        }

        return hash_equals($expected, $submitted);
    }

    /**
     * The cookie carrying the token, with the same attributes as the session cookie.
     *
     * `HttpOnly` IS CORRECT EVEN THOUGH THIS IS A DOUBLE-SUBMIT TOKEN. The classic pattern has
     * JavaScript read the cookie and copy it into a header; here the *server* renders the value
     * into the form, so nothing in the browser ever needs to read it. Keeping it unreadable
     * costs nothing and removes it as a target for injected script.
     *
     * No lifetime, for the same reason the session cookie has none: it is only meaningful for as
     * long as the form it belongs to is open.
     */
    public static function cookie(Request $request, string $value, string $prefix): Cookie
    {
        return Cookie::create(
            self::COOKIE_NAME,
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
