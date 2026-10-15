<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin;

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
     * @return list<string>
     */
    public function getEntities(): array
    {
        if ($this->entities !== []) {
            return $this->entities;
        }

        return array_keys($this->app['schema']);
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
         * A dot would survive into the controller key. The core builds `<mountPath>.controller`
         * and its middleware splits that string on dots and reads the third part — a prefix
         * carrying its own dot shifts every part by one and the request dies in the core, far
         * away from the line that caused it.
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
