<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Page\PageRegistry;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one controller every project page is mounted on — stage 3a's dispatcher.
 *
 * WHY THE PAGE CLASS IS NOT THE CONTROLLER, although `mount()` would take it. A mount binds one
 * controller class and the core derives the action from the route name
 * (`CustomControllerProvider::connect()`), so every page method would have to be named
 * `<route>Action` and would be called by the core directly — with no place left to run the
 * version check and the permission check, and no place to hand the page its request. The author's
 * `index(): Response` from concept section 9 only exists because something else owns the entry
 * point. This class is that something.
 *
 * ONE MOUNT PER PAGE, which is not a choice either: `RouteManager` keys its providers by mount
 * path, so a second mount on the same path replaces the first silently
 * (`AdminManager::mountRoutes()`). Every page therefore gets `<prefix><route>` to itself, and
 * `Page\PageRegistry` refuses two pages on one address before any of it is mounted.
 *
 * IT RESOLVES BY PATH, which is what makes the registry refuse placeholders: the address is
 * looked up, not matched. A literal lookup cannot be fooled into reaching a page that was not
 * registered, and it needs no router of its own next to the core's.
 *
 * THE GATES ARE NOT HERE. `AbstractAdminPage::handle()` runs them, so a page reached through this
 * dispatcher and a page tested on its own behave the same way. This class resolves and delegates;
 * it decides nothing.
 */
final class PageController
{
    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Answers the page route and every one of its `#[AdminAction]` routes.
     *
     * The action name is given explicitly for every route this is mounted on, because the core
     * would otherwise derive it from the route name — and the route names here are the project's
     * addresses, not method names.
     *
     * THE TRAILING SLASH IS STRIPPED BEFORE THE LOOKUP. A mount's own route carries the empty
     * name, so the page's address arrives as `<prefix><route>/` while the registry holds it
     * without the slash. That asymmetry belongs here, in one line, rather than in the registry
     * where two spellings of one address would have to be kept apart.
     *
     * A MISS IS A 404 AND NOT AN EXCEPTION. Reaching this with an unregistered route means the
     * mounts and the registry disagree, which would be a fault of this package — but answering
     * with a stack trace would turn a package fault into a page that leaks paths.
     */
    public function dispatchAction(Request $request): Response
    {
        $prefix = $this->app[AdminManager::CONTAINER_KEY]->getPrefix();
        $route  = substr(rtrim($request->getPathInfo(), '/'), strlen($prefix));

        $target = $this->app[PageRegistry::CONTAINER_KEY]->target($route);

        if ($target === null) {
            return new Response('', 404);
        }

        $class = $target['class'];

        return (new $class($this->app))->handle($request, $target['method']);
    }
}
