<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Schema;

use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Kernel\ApplicationInterface;

/**
 * The one place the admin reads the entity schema.
 *
 * The schema is the ground truth for menu, list, form and permissions. Every one of those will
 * need it, and none of them may fetch it itself — not because a fetch is expensive, but because
 * two callers reading two schemas is how a list ends up showing a field the form does not know.
 *
 * IN-PROCESS, NOT OVER HTTP, and that is a recorded decision (ADR 0006). The admin runs in the
 * same process as the core — `custom/app.php` is loaded from `bootstrap.php` right before the
 * routes are bound — so there is no network between the two. A real self-call would deadlock on
 * the development server, which runs `php -S` single-threaded, and it would need a base URL the
 * admin deliberately does not have.
 *
 * WHY ITS OWN `Api` AND NOT `$app['schema']`. Both build the schema the same way; the container's
 * key is literally `function ($app) { return (new Api($app))->getSchema(); }`. The difference is
 * that the container resolves a factory **once** and then hands out the stored result forever
 * (`Kernel\Container::offsetGet`). Reading through it would make `invalidate()` a method that
 * cannot invalidate anything inside the process it is called in — an API that lies about its own
 * name is worse than no API. The price is named rather than hidden: a project that decorated
 * `$app['schema']` through `extend()` would not be honoured here. Nothing in the tree does that
 * today; if something starts to, this client is the place to change, not the caller.
 *
 * NO PERMISSIONS VIEW HERE — IT IS `Auth\PermissionView`'S. `/api/schema` serves
 * `Api::getExtendedSchema()`, which carries each entity's read/write/delete flags for the signed-in
 * editor. That call stays out of this client for two reasons. It cannot run without a login: it
 * dereferences `$app['auth.user']->getGroup()` without a guard while that key defaults to `null`,
 * and the `getPermissions()` it uses passes the same `null` into
 * `Permission::isReadable(User $user, …)`, whose parameter is typed and not nullable. And its
 * answer differs per editor, while this client's deliberately does not — see `getHash()`, which
 * is kept out of every cache key for exactly that reason. So the editor's rights moved to their
 * own service when the login arrived in epic 002 (ADR 0019); it guards the call and holds its
 * answer per request. What this client returns is still the plain schema, in which
 * `Api::getSchema()` reports every permission as `0`, because it guards the same call where
 * `getExtendedSchema()` does not — never read a permission off it.
 *
 * THE CORE'S OWN PERSISTED CACHE IS A SEPARATE LAYER. With `APP_ENABLE_SCHEMA_CACHE` switched on,
 * `Api::getSchema()` serves `data/cache/schema.cache` and writes it back. That file outlives the
 * request, and this client neither reads nor deletes it — clearing it stays a deliberate act by a
 * developer, because deleting project data behind someone's back is not a cache strategy. The
 * harness ships with that flag off, so there the schema is rebuilt per request anyway.
 */
final class SchemaClient
{
    /**
     * Where the client puts itself, namespaced like the manager's key so it cannot collide with
     * a project's own.
     */
    public const CONTAINER_KEY = 'contentfly_admin.schema_client';

    /**
     * The key the core writes its schema fingerprint under, in among the entity names.
     *
     * `Api::getSchema()` sets it as `md5(serialize($data) . serialize($permissions))`. Two
     * consequences the admin has to live with, both recorded in
     * `an_project/docs/schema-vertrag.md` section 3.3: the permissions are part of it, so the
     * value depends on who is asking, and it changes on every schema change, harmless ones
     * included. It is a fingerprint, never a version — see `getHash()`.
     */
    private const HASH_KEY = '_hash';

    private ApplicationInterface $app;

    /**
     * The schema as the core returned it, or `null` while nothing has been read yet.
     *
     * `null` is the "not read" marker and not a missing value: the core never returns `null`, so
     * the two cannot be confused.
     *
     * @var array<string,mixed>|null
     */
    private ?array $schema = null;

    private int $reads = 0;

    /**
     * Stage 3 of the shaping cascade, as `AdminManager` validated it.
     *
     * Empty is the normal case and means the project states nothing — then this class behaves
     * exactly as it did before stage 3 existed.
     *
     * @var array<string,array<string,mixed>>
     */
    private array $ui;

    /**
     * @param array<string,array<string,mixed>> $ui The `ui` option, already checked for form
     */
    public function __construct(ApplicationInterface $app, array $ui = [])
    {
        $this->app = $app;
        $this->ui  = $ui;
    }

    /**
     * The schema, built on first use and then held for the rest of the process.
     *
     * @return array<string,mixed> Entity names to their `settings` and `properties`, plus the
     *                             core's own `_hash` entry
     */
    public function getSchema(): array
    {
        if ($this->schema === null) {
            ++$this->reads;

            $this->schema = self::applyUi((new Api($this->app))->getSchema(), $this->ui);
        }

        return $this->schema;
    }

    /**
     * The project's own UI configuration, merged into the shaping key of the schema.
     *
     * WHY HERE AND NOWHERE ELSE. This is the one seam list and form both read the schema
     * through, and it is held for the process — so the merge runs once and every reader inherits
     * it without a line of its own: `Shaping`, `ColumnBuilder`, `EntityView` and the
     * `WidgetResolver` stay exactly as they were, and `listFilters()`, `changeableFields()` and
     * the four controllers that read through this client are served along the way. Injecting the
     * data into `Shaping` was the alternative and fails on what that class is: `final`, nothing
     * but `public static`, no state — the configuration would have to travel through all eleven
     * of its call sites (ADR 0031).
     *
     * IT SITS AFTER THE CORE CALL, WHICH KEEPS THE PERSISTED CACHE HONEST. `Api::getSchema()`
     * may serve and write `data/cache/schema.cache`; the merge happens on the value it hands
     * back, so what lands in that file stays the unmodified core schema. A changed
     * `custom/admin.php` therefore takes effect without anyone clearing a cache — and a process
     * that runs without the option can never poison the file for one that has it.
     *
     * THE LOUD HALF OF THE KEY CHECK LIVES HERE. `AdminManager::validateUi()` has checked the
     * form, but it cannot check existence: the constructor must not load a schema (ADR 0028),
     * which is also why `getEntities()` reads it only on access. A typo in an entity key used to
     * be silent; it is a message now, and the message names what could not be found.
     *
     * `widgets` IS DELIBERATELY NOT MERGED. A widget choice reaches the resolver through the
     * `WidgetRegistry`, which `AdminManager` feeds inside the one window ADR 0027 allows — that
     * is how the choice takes effect without touching the resolver or the registry. Only the
     * field names are checked here, so both halves of the configuration answer to one place.
     *
     * @param array<string,mixed>               $schema
     * @param array<string,array<string,mixed>> $ui
     *
     * @return array<string,mixed>
     *
     * @throws \InvalidArgumentException when the configuration names an entity or field the
     *                                   schema does not have
     */
    private static function applyUi(array $schema, array $ui): array
    {
        foreach ($ui as $entity => $stated) {
            if (!isset($schema[$entity]) || !is_array($schema[$entity])) {
                throw new \InvalidArgumentException(sprintf(
                    'The option "ui" configures entity "%s", which this schema does not have. Known are: %s.',
                    $entity,
                    implode(', ', self::entityNamesOf($schema))
                ));
            }

            $properties = $schema[$entity]['properties'] ?? array();
            $properties = is_array($properties) ? $properties : array();

            if (array_key_exists('label', $stated)) {
                /*
                 * THE ENTITY LABEL GOES UNDER THE SAME NAMESPACED KEY, one level up. A bare
                 * `settings['label']` was free — the core's apidoc promises one and the code
                 * writes `labelProperty` instead (Schema-Vertrag 1.2) — but taking a name the
                 * core advertises would hand the next core version a collision. Its reader is
                 * `MenuBuilder` and the two headings (005-005-0004).
                 */
                $settings = $schema[$entity]['settings'] ?? array();
                $settings = is_array($settings) ? $settings : array();
                $carried  = $settings[ShapingListener::SCHEMA_KEY] ?? array();

                $settings[ShapingListener::SCHEMA_KEY] = array_merge(
                    is_array($carried) ? $carried : array(),
                    array('label' => $stated['label'])
                );

                $schema[$entity]['settings'] = $settings;
            }

            if (array_key_exists('list', $stated)) {
                foreach ($stated['list'] as $field) {
                    self::assertField($properties, $entity, $field, 'list');
                }

                /*
                 * EVERY FIELD IS STATED, NOT ONLY THE CHOSEN ONES. One `showInList: true` on an
                 * entity switches the automatic column fill off (ADR 0017 §1), so the chosen
                 * fields alone would already select — but a field that stage 2 marked
                 * `showInList: true` would stay a column, and stage 3 is supposed to win. So the
                 * others are stated `false` rather than left open.
                 *
                 * `position` carries the order, which is what makes `ColumnBuilder` show it
                 * without a change. It orders form fields within their tab as well (ADR 0017
                 * §2) — a side effect on an entity that states `list` but no `tabs`; where
                 * `tabs` is stated, 005-005-0005 decides the order.
                 */
                $rank = array_flip(array_values($stated['list']));

                foreach (array_keys($properties) as $field) {
                    $properties[$field] = self::stateShaping(
                        $properties[$field],
                        isset($rank[$field])
                            ? array('showInList' => true, 'position' => $rank[$field] + 1)
                            : array('showInList' => false)
                    );
                }
            }

            if (array_key_exists('tabs', $stated)) {
                foreach ($stated['tabs'] as $tab => $fields) {
                    foreach ($fields as $field) {
                        self::assertField($properties, $entity, $field, sprintf('tabs.%s', $tab));

                        $properties[$field] = self::stateShaping($properties[$field], array('tab' => $tab));
                    }
                }
            }

            if (array_key_exists('widgets', $stated)) {
                foreach (array_keys($stated['widgets']) as $field) {
                    self::assertField($properties, $entity, (string) $field, 'widgets');
                }
            }

            $schema[$entity]['properties'] = $properties;
        }

        return $schema;
    }

    /**
     * Writes stage-3 values into one field's shaping key, beating what is already there.
     *
     * The carried value is stage 2's, put there by `ShapingListener` — and stage 3 goes in front
     * of it (ADR 0031). Only the keys stage 3 actually states are touched; everything else the
     * entity said about this field survives untouched, because the precedence is per value and
     * not per entity.
     *
     * @param array<string,mixed>|mixed      $meta
     * @param array<string,string|int|bool>  $stated
     *
     * @return array<string,mixed>
     */
    private static function stateShaping($meta, array $stated): array
    {
        $meta    = is_array($meta) ? $meta : array();
        $carried = $meta[ShapingListener::SCHEMA_KEY] ?? array();

        $meta[ShapingListener::SCHEMA_KEY] = array_merge(is_array($carried) ? $carried : array(), $stated);

        return $meta;
    }

    /**
     * @param array<string,mixed> $properties
     *
     * @throws \InvalidArgumentException when the entity has no such field
     */
    private static function assertField(array $properties, string $entity, string $field, string $setting): void
    {
        if (array_key_exists($field, $properties)) {
            return;
        }

        throw new \InvalidArgumentException(sprintf(
            'The setting "%s" of entity "%s" in the option "ui" names field "%s", which that entity does not have. Known are: %s.',
            $setting,
            $entity,
            $field,
            implode(', ', array_keys($properties))
        ));
    }

    /**
     * @param array<string,mixed> $schema
     *
     * @return list<string>
     */
    private static function entityNamesOf(array $schema): array
    {
        $names = array_filter(array_keys($schema), static function ($name): bool {
            return !str_starts_with((string) $name, '_');
        });

        return array_values(array_map('strval', $names));
    }

    /**
     * The entity names of the schema, in the order the core lists them.
     *
     * NOT EVERY KEY OF THE SCHEMA IS AN ENTITY. `_hash` sits in among them, and the core skips it
     * wherever it walks the schema itself (`Api.php:818`, `:998`, `:1147`). Reading the keys
     * naively puts a menu entry called `_hash` on the screen. The filter is on the underscore
     * rather than on that one name on purpose — it is the convention the core follows, so a
     * second meta key added later is excluded without a change here.
     *
     * @return list<string>
     */
    public function getEntityNames(): array
    {
        $names = array_keys($this->getSchema());

        return array_values(array_filter($names, static function ($name): bool {
            return !str_starts_with((string) $name, '_');
        }));
    }

    /**
     * The schema's fingerprint, or `null` if this core does not report one.
     *
     * USE IT TO NOTICE A CHANGE, NEVER TO JUDGE COMPATIBILITY. It is an `md5` over the schema and
     * the caller's permissions, so it says "something is different" and nothing else: two editors
     * in different groups get different values for the same schema, and an added field in a
     * project entity changes it without changing the contract. The version check belongs to
     * `VersionGuard`, which reads a version instead.
     *
     * It is deliberately not part of any cache key in this class. A key that varies by caller
     * would turn one held schema into one per editor, and the value only exists *after* the read
     * it would be keying — there is nothing to look up before it.
     */
    public function getHash(): ?string
    {
        $hash = $this->getSchema()[self::HASH_KEY] ?? null;

        return is_string($hash) ? $hash : null;
    }

    /**
     * Drops the held schema so the next read builds it again.
     *
     * This is what makes a schema change visible without restarting anything — the reason the
     * client holds its own copy instead of reading through `$app['schema']`, which can never be
     * resolved a second time.
     */
    public function invalidate(): void
    {
        $this->schema = null;
    }

    /**
     * How often the core was actually asked for the schema in this process.
     *
     * It exists to be asserted on. "The schema is read once" is a promise that nothing about the
     * admin's behaviour reveals — a second read returns the same data and merely costs time, so a
     * regression here is invisible until it is measured. `tools/harness-schema-pruefen.php` reads
     * this, which is the whole reason it is public.
     */
    public function getReadCount(): int
    {
        return $this->reads;
    }
}
