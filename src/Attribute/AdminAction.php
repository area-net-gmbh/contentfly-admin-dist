<?php

namespace Areanet\ContentflyAdmin\Attribute;

use Attribute;

/**
 * One further route of the page this method belongs to — the second half of stage 3a.
 *
 * A page is rarely only a screen. The newsletter generator of concept section 9 has a reset, an
 * import has a start button, an evaluation has a download. Without this attribute each of them
 * would need a page of its own, with its own address and its own menu entry, which is why the
 * concept introduced both attributes together.
 *
 * THE ROUTE IS WRITTEN IN FULL, not as the remainder. `#[AdminAction(method: 'POST', route:
 * '/newsletter/reset')]` on a page routed at `/newsletter` repeats the page's own route on
 * purpose: what the author reads in the attribute is the address they will see in the browser,
 * and a remainder (`reset`) would only be resolvable by looking up the class attribute as well.
 * The route must therefore begin with the page's own route — a mismatch is a mistake and is
 * refused when the page is registered, because a route mounted elsewhere would answer under an
 * address the page does not own.
 *
 * NOT REPEATABLE, ONE ROUTE PER METHOD. Two addresses answering with the same method body is
 * either a redirect or two methods; making it expressible here would mostly produce the third
 * case, where the method has to ask which of its routes it was reached through.
 *
 * NO PERMISSION ARGUMENT. An action belongs to its page and is reached through the page's gates —
 * a right of its own would mean a button the page shows and its own action refuses. Where an
 * action needs to be stricter than its page, it belongs on a page of its own.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final class AdminAction
{
    /**
     * @param string $route  The full address below the admin's prefix, beginning with the page's
     *                       own route — `/newsletter/reset` for a page routed at `/newsletter`.
     *                       Same rules as `AdminPage::$route`: leading slash, no dot, a
     *                       placeholder matches one segment.
     * @param string $method The HTTP method this route answers. `'GET'`, `'POST'` or `'ANY'`;
     *                       `'ANY'` is the core's `match()` and the way to let one method serve
     *                       both, which is what the core's own routing requires — two routes of
     *                       the same name within one mount do not add up, the second silently
     *                       replaces the first (`AdminManager::mountRoutes()`).
     */
    public function __construct(
        public readonly string $route,
        public readonly string $method = 'GET',
    ) {
    }
}
