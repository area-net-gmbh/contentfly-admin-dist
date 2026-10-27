<?php

namespace Areanet\ContentflyAdmin\Attribute;

use Attribute;

/**
 * Declares a class to be one page of the admin — stage 3a of the extension contract.
 *
 * Stage 1 tunes a field with attributes and no code, stage 2 replaces one field's control. This
 * is the jump to a screen that is not a table at all: a newsletter generator, an evaluation, an
 * import. The contract provides exactly three things — route plus menu entry, layout plus
 * authentication plus permission check, and access to the data layer. Everything else is the
 * page's own business, and that is deliberate: the smaller the contract, the longer it holds.
 *
 * A PROJECT REGISTERS ITS PAGES EXPLICITLY, class name by class name, and the admin does not go
 * looking for them. Concept section 9 asked for autodiscovery by service tag; this stack has no
 * service tags, and a directory scan — the only replacement — would have to run while
 * `custom/app.php` is being read, because the routes must be mounted before `bindRoutes()`. That
 * is the one moment this package promises not to touch the filesystem, `appcms:install` included
 * (`View\TemplateRenderer`). ADR 0028 carries the full reasoning and names the price.
 *
 * THE ROUTE IS RELATIVE TO THE ADMIN'S PREFIX and sits beside `login` and `logout` rather than
 * under a segment of its own. That is not a new decision: ADR 0012 put the list under
 * `/<prefix>/list/<Entity>` instead of `/<prefix>/<Entity>` precisely so that project pages could
 * have the flat namespace without competing with entity names.
 *
 * UNLIKE `Admin`, THE FIRST TWO ARGUMENTS ARE REQUIRED. A field without a label falls back to its
 * humanised property name, so three-state arguments are right there; a page without a route has
 * no address and a page without a label cannot appear in a menu. There is no sensible fallback for
 * either, and inventing one would hide a mistake rather than report it.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AdminPage
{
    /**
     * @param string      $route      The page's address below the admin's prefix, with a leading
     *                                slash — `/newsletter` becomes `/<prefix>/newsletter`. One or
     *                                more segments; a placeholder matches a single segment
     *                                (ADR 0007). No dot: the core builds its controller key from
     *                                the path, and an extra dot shifts the parts of its event
     *                                names (`AdminManager::validatePrefix()`).
     * @param string      $label      The menu entry's text, shown to editors as written.
     * @param string|null $group      Menu group this page is listed under. Null lists it beside
     *                                the entities rather than inventing a group of one.
     * @param string|null $permission Required right, as `'<Entity>:<action>'` with `read`, `write`
     *                                or `delete` — `'Angebot:read'`. Null makes the page reachable
     *                                for every signed-in editor; the sign-in itself is never
     *                                optional. The form is checked when the page is registered,
     *                                the entity only when a request arrives, and an entity nobody
     *                                knows counts as "no right" rather than as an error
     *                                (ADR 0028 §3).
     */
    public function __construct(
        public readonly string $route,
        public readonly string $label,
        public readonly ?string $group = null,
        public readonly ?string $permission = null,
    ) {
    }
}
