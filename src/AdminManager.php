<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin;

use Areanet\ContentflyAdmin\Controller\AdminController;
use Areanet\PIM\Classes\Kernel\ApplicationInterface;

/**
 * The admin package's entry point into a Contentfly project.
 *
 * A project registers it in `custom/app.php` and is done:
 *
 *     $app['adminManager'] = new \Areanet\ContentflyAdmin\AdminManager($app);
 *
 * WHY A MANAGER OF ITS OWN AND NOT A CORE PLUGIN: the core's plugin system builds the class
 * name from `Plugins\<Name>\<Class>Plugin` and resolves its paths into the project tree, not
 * into `vendor/`. A package installed by Composer cannot satisfy that, and the plugin API knows
 * nothing about routes or services anyway. Recorded with file and line in ADR 0001.
 *
 * ZERO CONFIGURATION IS A HARD RULE, not a convenience. The admin is meant for headless
 * Contentfly projects, and in those nobody adopts a UI that demands setup first. Every option
 * therefore has a default that produces a working admin, and that includes `entities`: left
 * empty it means *all* entities of the schema, never none.
 */
final class AdminManager
{
    /**
     * Where the manager puts itself so its own controllers can find it again.
     *
     * A project is free to name its container key — the example in the documentation says
     * `$app['adminManager']`, but nothing enforces it. The controllers must not depend on that
     * choice, and they cannot be handed the manager either: the core builds every controller
     * itself as `new $controllerName($app)`. Hence a fixed key of our own, namespaced so it
     * cannot collide with a project's.
     */
    public const CONTAINER_KEY = 'contentfly_admin.manager';

    /**
     * The options a project may pass, with the values that apply when it passes nothing.
     *
     * This array is also the whitelist — an unknown key is a typo, and a typo that is silently
     * ignored looks exactly like an option that does not work.
     */
    private const DEFAULTS = [
        'prefix'   => '/admin',
        'title'    => 'Contentfly Admin',
        'entities' => [],
    ];

    /**
     * Path segments the core mounts itself in `bootstrap-web.php`.
     *
     * The admin's routes are collected before the core's, so an admin mounted on one of these
     * would swallow the API of the very project it administers — and it would do so silently,
     * because nothing in the core objects to a second mount on the same path.
     */
    private const RESERVED_PREFIXES = ['api', 'auth', 'file', 'system'];

    private ApplicationInterface $app;

    private string $prefix;

    private string $title;

    /** @var list<string> The configured whitelist; empty means "every entity of the schema". */
    private array $entities;

    /**
     * @param array<string,mixed> $options See self::DEFAULTS for the accepted keys.
     *
     * @throws \InvalidArgumentException on an unknown option or an unusable prefix
     */
    public function __construct(ApplicationInterface $app, array $options = [])
    {
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown option(s) for the AdminManager: %s. Known are: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::DEFAULTS))
            ));
        }

        $options = array_merge(self::DEFAULTS, $options);

        $this->app      = $app;
        $this->prefix   = self::validatePrefix($options['prefix']);
        $this->title    = self::validateTitle($options['title']);
        $this->entities = self::validateEntities($options['entities']);

        $this->mountRoutes();
    }

    /**
     * Registers the admin's routes with the core's route manager.
     *
     * CALLED FROM THE CONSTRUCTOR, because that is the whole contract a project gets: it writes
     * one `new AdminManager($app)` into `custom/app.php` and is done. A separate `mount()` call
     * would be one more line every project has to remember, and forgetting it would produce an
     * admin that silently is not there.
     *
     * THE MOMENT IS THE POINT. `custom/app.php` is required in `bootstrap.php` immediately
     * before `$app['routeManager']->bindRoutes()`, so the routes are collected in time. Reading
     * `$app['routeManager']` here resolves that key — unavoidable, since mounting is the job,
     * and harmless, because the bootstrap reads it on the next line anyway.
     *
     * The route name is the empty string so the path stays the mount path itself: the core
     * prepends a slash to every route name, which is what makes `/admin/` — with the trailing
     * slash — the canonical address. `/admin` without it matches no route at all, and the
     * core's catch-all for CORS preflights then matches the path but not the method: the
     * router reports MethodNotAllowed and the answer is 405, never 404. That is the core's
     * documented behaviour for every unmatched path, not something the admin introduces
     * (`bootstrap-web.php`, the OPTIONS catch-all); with debug output switched on, as in the
     * harness, it surfaces as an error page with a stack trace instead.
     *
     * The action name is passed explicitly because the core would otherwise derive it from the
     * empty route name and look for a method called `Action`.
     */
    private function mountRoutes(): void
    {
        $this->app[self::CONTAINER_KEY] = $this;

        $this->app['routeManager']
            ->mount($this->prefix, AdminController::class)
            ->get('', false, 'indexAction');
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * The entities the admin administers, in the order the schema lists them.
     *
     * RESOLVED HERE AND NOT IN THE CONSTRUCTOR, and that is load-bearing. The manager is built
     * while `custom/app.php` runs, which is before `bindRoutes()` and long before a request is
     * dispatched. Reading `$app['schema']` at that moment would resolve the container key,
     * freeze it against `extend()` (see `Kernel\Container`) and open the database on every
     * request, console run and install — including the ones that have no database yet.
     *
     * NOT EVERY KEY OF THE SCHEMA IS AN ENTITY. `_hash` carries the schema's own checksum —
     * `Api::getSchema()` writes it in next to the entities, and the core skips it wherever it
     * walks the schema (`Api.php:818`, `:998`, `:1147`). Reading the keys naively puts it in
     * the menu as an entity named `_hash`.
     *
     * @return list<string>
     */
    public function getEntities(): array
    {
        if ($this->entities !== []) {
            return $this->entities;
        }

        $names = array_keys($this->app['schema']);

        return array_values(array_filter($names, static function ($name): bool {
            return !str_starts_with((string) $name, '_');
        }));
    }

    /**
     * @param mixed $prefix
     *
     * @throws \InvalidArgumentException
     */
    private static function validatePrefix($prefix): string
    {
        if (!is_string($prefix) || $prefix === '') {
            throw new \InvalidArgumentException('The option "prefix" must be a non-empty string, for example "/admin".');
        }

        if (!str_starts_with($prefix, '/')) {
            throw new \InvalidArgumentException(sprintf('The prefix "%s" must start with a slash, for example "/admin".', $prefix));
        }

        if (str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException(sprintf('The prefix "%s" must not end with a slash — the routes add it.', $prefix));
        }

        /*
         * A dot would survive into the controller key. The core builds
         * `<mountPath>.controller:<action>`, lowercases it, turns the colon into a dot and
         * splits the result to assemble its event names — `pim.controller.before.<part0>.<part2>`
         * in `BaseControllerProvider`. An extra dot shifts those parts, so the before and after
         * events fire under names nobody subscribed to. Nothing crashes; the hooks simply stop
         * arriving, which is the kind of defect one looks for everywhere except in the prefix.
         */
        if (str_contains($prefix, '.')) {
            throw new \InvalidArgumentException(sprintf('The prefix "%s" must not contain a dot — the core builds its controller key from it.', $prefix));
        }

        $firstSegment = strtolower(explode('/', ltrim($prefix, '/'))[0]);
        if (in_array($firstSegment, self::RESERVED_PREFIXES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'The prefix "%s" collides with the core route "/%s". Reserved are: /%s.',
                $prefix,
                $firstSegment,
                implode(', /', self::RESERVED_PREFIXES)
            ));
        }

        return $prefix;
    }

    /**
     * @param mixed $title
     *
     * @throws \InvalidArgumentException
     */
    private static function validateTitle($title): string
    {
        if (!is_string($title) || trim($title) === '') {
            throw new \InvalidArgumentException('The option "title" must be a non-empty string.');
        }

        return $title;
    }

    /**
     * @param mixed $entities
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private static function validateEntities($entities): array
    {
        if (!is_array($entities)) {
            throw new \InvalidArgumentException('The option "entities" must be an array of entity names.');
        }

        foreach ($entities as $entity) {
            if (!is_string($entity) || $entity === '') {
                throw new \InvalidArgumentException('The option "entities" must contain non-empty entity names only.');
            }
        }

        return array_values($entities);
    }
}
