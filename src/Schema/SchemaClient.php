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
 * NO PERMISSIONS VIEW — AND THAT IS NOT AN OVERSIGHT. `/api/schema` serves
 * `Api::getExtendedSchema()`, which carries each entity's read/write/delete flags for the signed-in
 * editor. It cannot be called here: it dereferences `$app['auth.user']->getGroup()` without a
 * guard while that key defaults to `null`, and the `getPermissions()` it uses passes the same
 * `null` into `Permission::isReadable(User $user, …)`, whose parameter is typed and not nullable.
 * The admin has no signed-in editor yet — its route is not secured and nothing sets `auth.user`.
 * The permissions view therefore arrives with the login, in epic 002. What this client returns is
 * the plain schema, in which `Api::getSchema()` reports every permission as `0`, because it guards
 * the same call where `getExtendedSchema()` does not.
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

    public function __construct(ApplicationInterface $app)
    {
        $this->app = $app;
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

            $this->schema = (new Api($this->app))->getSchema();
        }

        return $this->schema;
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
