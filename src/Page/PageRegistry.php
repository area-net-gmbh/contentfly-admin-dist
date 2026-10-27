<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Page;

use Areanet\ContentflyAdmin\Attribute\AdminAction;
use Areanet\ContentflyAdmin\Attribute\AdminPage;

/**
 * The pages a project has registered — the one list the mount, the dispatcher and the menu read.
 *
 * A PROJECT REGISTERS EXPLICITLY, AND THAT IS THE DECISION, not a shortcut (ADR 0028 §1).
 * Concept section 9 asked for autodiscovery in `custom/Admin/` by service tag. This stack has no
 * service tags — ADR 0027 established that for the widgets — and a directory scan, the only
 * replacement, would have to run while `custom/app.php` is being read, because the routes have to
 * be mounted before `bindRoutes()`. That is the one moment this package promises not to touch the
 * filesystem, `appcms:install` included (`View\TemplateRenderer`). The price is one line per page.
 *
 * UNLIKE THE `WidgetRegistry`, THIS ONE IS FILLED IN THE CONSTRUCTOR and not in a container
 * factory. It has to be: a widget is needed when a field renders, long after the request began,
 * but a route that does not exist before `bindRoutes()` is not a route. What keeps the promise
 * narrow is that nothing here reads a directory, the schema, the database or the request — only
 * the classes the project named are loaded, and `Foo::class` is a compile-time constant that
 * loads nothing by itself. Autoloading them is the named exception of ADR 0028 §2, which is why a
 * page class has to be free of side effects at load time.
 *
 * EVERYTHING IS CHECKED HERE, WHERE THE MISTAKE IS CHEAP. A page is registered inside the
 * manager's constructor, so a bad route, an unknown permission action or two pages on one address
 * stop the admin from starting with a message naming the class — instead of answering 404, or
 * worse, replacing a route that was already there. `RouteManager` keys its providers by mount
 * path and a second mount on the same path replaces the first *silently*
 * (`AdminManager::mountRoutes()`), so "two pages, one address" is the one mistake that cannot be
 * allowed to pass.
 */
final class PageRegistry
{
    /** Where the registry puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.page_registry';

    /** The HTTP methods an `#[AdminAction]` may ask for; `ANY` is the core's `match()`. */
    public const METHODS = array('GET', 'POST', 'ANY');

    /**
     * First segments a page may not take, because the admin already mounts them.
     *
     * Eight of them are mounts of their own (`AdminManager::mountRoutes()`), `login` and `logout`
     * are routes of the prefix mount. A page on one of these would not merely be shadowed: for
     * the eight, `RouteManager` would replace the admin's own provider with the page's, and the
     * list, the form or the asset route would stop existing. This list is the reason that cannot
     * happen by accident.
     *
     * @var list<string>
     */
    public const RESERVED_SEGMENTS = array(
        'list',
        'form',
        'options',
        'filepicker',
        'bulk',
        'files',
        'tree',
        '_asset',
        'login',
        'logout',
    );

    /** One segment: letters, digits, hyphen and underscore, not starting with a separator. */
    private const SEGMENT = '[A-Za-z0-9][A-Za-z0-9_-]*';

    /** @var array<string,PageDeclaration> Route below the prefix => the page on it */
    private array $pages = array();

    /** @var array<string,array{class:string,method:string}> Route below the prefix => what answers it */
    private array $targets = array();

    private bool $sealed = false;

    /**
     * Register one page by its class name.
     *
     * @param string $class A class extending `AbstractAdminPage` and carrying `#[AdminPage]`
     *
     * @throws \LogicException           when the registry is already sealed
     * @throws \InvalidArgumentException on anything wrong with the class or its attributes
     */
    public function register(string $class): void
    {
        if ($this->sealed) {
            throw new \LogicException(sprintf(
                'The page registry is sealed; "%s" arrives too late to get a route. Register pages '
                .'through the "pages" option of the AdminManager in custom/app.php — that callback '
                .'runs before the routes are mounted, which is the only moment a page can get one.',
                $class
            ));
        }

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf(
                'The page class "%s" does not exist. Check the namespace and that composer autoloads it.',
                $class
            ));
        }

        if (!is_subclass_of($class, AbstractAdminPage::class)) {
            throw new \InvalidArgumentException(sprintf(
                'The page class "%s" has to extend %s — that is what brings layout, authentication, '
                .'the permission check and the data layer with it.',
                $class,
                AbstractAdminPage::class
            ));
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(AdminPage::class);

        if ($attributes === array()) {
            throw new \InvalidArgumentException(sprintf(
                'The page class "%s" carries no #[AdminPage] attribute, so it has neither an address nor a label.',
                $class
            ));
        }

        /** @var AdminPage $page */
        $page = $attributes[0]->newInstance();

        $this->assertRoute($page->route, $class, 'route');

        if ($page->label === '') {
            throw new \InvalidArgumentException(sprintf(
                'The page "%s" has an empty label; the label is the text of its menu entry.',
                $class
            ));
        }

        if ($page->permission !== null) {
            /* Throws with its own message when the form is wrong — ADR 0028 §3. */
            PagePermission::parse($page->permission);
        }

        $this->claim($page->route, $class, 'index');

        $actions = $this->collectActions($class, $page->route);

        $this->pages[$page->route] = new PageDeclaration(
            $class,
            $page->route,
            $page->label,
            $page->group,
            $page->permission,
            $actions
        );
    }

    /** Closes the registry. Called once, by the manager, right after the project's callback. */
    public function seal(): void
    {
        $this->sealed = true;
    }

    /**
     * Every registered page, in registration order.
     *
     * @return list<PageDeclaration>
     */
    public function all(): array
    {
        return array_values($this->pages);
    }

    /**
     * What answers this route, or `null` when nothing does.
     *
     * The route is the path below the admin's prefix, without a trailing slash — the dispatcher
     * strips both before asking.
     *
     * @return array{class:string,method:string}|null
     */
    public function target(string $route): ?array
    {
        return $this->targets[$route] ?? null;
    }

    /**
     * The `#[AdminAction]` routes of one page, checked against the page's own route.
     *
     * AN ACTION ROUTE HAS TO BEGIN WITH ITS PAGE'S ROUTE, because the page owns exactly one mount
     * and the action is a route inside it. An action pointing somewhere else is not a stricter
     * version of the same mistake — it would need a mount of its own, on a path the page does not
     * own and possibly one another page does.
     *
     * @return array<string,array{method:string,target:string}>
     */
    private function collectActions(string $class, string $pageRoute): array
    {
        $actions = array();

        foreach ((new \ReflectionClass($class))->getMethods(\ReflectionMethod::IS_PUBLIC) as $method) {
            $declared = $method->getAttributes(AdminAction::class);

            if ($declared === array()) {
                continue;
            }

            /** @var AdminAction $action */
            $action = $declared[0]->newInstance();

            $this->assertRoute($action->route, $class, $method->getName().'()');

            if (!str_starts_with($action->route, $pageRoute.'/')) {
                throw new \InvalidArgumentException(sprintf(
                    'The action "%s::%s()" is routed at "%s", which is not below its page\'s route "%s". '
                    .'Write the full address, beginning with the page\'s own route — "%s/something".',
                    $class,
                    $method->getName(),
                    $action->route,
                    $pageRoute,
                    $pageRoute
                ));
            }

            if (!in_array($action->method, self::METHODS, true)) {
                throw new \InvalidArgumentException(sprintf(
                    'The action "%s::%s()" asks for the HTTP method "%s"; available are "%s".',
                    $class,
                    $method->getName(),
                    $action->method,
                    implode('", "', self::METHODS)
                ));
            }

            $this->claim($action->route, $class, $method->getName());

            $actions[$action->route] = array(
                'method' => $action->method,
                'target' => $method->getName(),
            );
        }

        return $actions;
    }

    /**
     * Takes one route for one target, or refuses because something already answers it.
     *
     * @throws \InvalidArgumentException
     */
    private function claim(string $route, string $class, string $method): void
    {
        if (isset($this->targets[$route])) {
            $taken = $this->targets[$route];

            throw new \InvalidArgumentException(sprintf(
                'Two targets on the address "%s": %s::%s() and %s::%s(). One address answers once — '
                .'a second mount on the same path replaces the first without a word.',
                $route,
                $taken['class'],
                $taken['method'],
                $class,
                $method
            ));
        }

        $this->targets[$route] = array('class' => $class, 'method' => $method);
    }

    /**
     * The shape every route has to have, page and action alike.
     *
     * NO PLACEHOLDERS IN STAGE 3A. The core matches one segment per placeholder (ADR 0007), so
     * they would work — but the dispatcher resolves a request by looking its path up in this
     * registry, and a pattern cannot be looked up. Supporting them means a matcher, and the
     * epic's own criterion is that the contract stays small. They are refused with a message that
     * says so, rather than silently mounted and then never dispatched.
     *
     * NO DOT, for the reason `AdminManager::validatePrefix()` records: the core builds its
     * controller key from the path, and an extra dot shifts the parts of its event names, so the
     * before/after hooks fire under names nobody subscribed to and nothing crashes.
     *
     * @throws \InvalidArgumentException
     */
    private function assertRoute(string $route, string $class, string $where): void
    {
        if ($route === '' || $route === '/') {
            throw new \InvalidArgumentException(sprintf(
                'The %s of "%s" is empty. A page needs an address of its own below the admin\'s prefix, '
                .'for example "/newsletter"; the prefix itself belongs to the admin\'s start page.',
                $where,
                $class
            ));
        }

        if (str_contains($route, '{') || str_contains($route, '}')) {
            throw new \InvalidArgumentException(sprintf(
                'The %s of "%s" is "%s". Placeholders are not part of stage 3a — a page is reached by '
                .'its own fixed address, and parameters travel in the query string.',
                $where,
                $class,
                $route
            ));
        }

        if (str_contains($route, '.')) {
            throw new \InvalidArgumentException(sprintf(
                'The %s of "%s" is "%s" and must not contain a dot — the core builds its controller key '
                .'from the path, and a dot shifts the parts of its event names.',
                $where,
                $class,
                $route
            ));
        }

        if (preg_match('#^(/'.self::SEGMENT.')+$#', $route) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'The %s of "%s" is "%s". A route is one or more segments, each beginning with a letter '
                .'or a digit and otherwise holding letters, digits, hyphens and underscores — "/newsletter" '
                .'or "/tools/newsletter".',
                $where,
                $class,
                $route
            ));
        }

        $first = explode('/', ltrim($route, '/'))[0];

        if (in_array($first, self::RESERVED_SEGMENTS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'The %s of "%s" starts with "%s", which the admin itself uses. Reserved are: %s. '
                .'A page there would not be shadowed — it would take the admin\'s own route away.',
                $where,
                $class,
                $first,
                implode(', ', self::RESERVED_SEGMENTS)
            ));
        }
    }
}
