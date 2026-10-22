<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Auth;

use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\I18nPermission;
use Areanet\PIM\Classes\Kernel\ApplicationInterface;
use Areanet\PIM\Classes\Language;
use Areanet\PIM\Entity\User;

/**
 * What the signed-in editor is allowed to do — the one place the admin asks.
 *
 * Menu, list, form and the bulk actions all need the same answer, and none of them may work it
 * out itself: four copies of a permission rule are four chances to let one drift. The decisions
 * behind this class are recorded in ADR 0019; the shape of the data it reads is section 7 of
 * `an_project/docs/schema-vertrag.md`.
 *
 * IN-PROCESS, LIKE THE SCHEMA (ADR 0006). `Api::getExtendedSchema()` is the only public way to
 * the permission flags — the narrower `getPermissions()` is `protected` (gap L16). So the whole
 * extended schema is built and all of it but two keys thrown away.
 *
 * IT THROWS AWAY `data` ON PURPOSE. The extended schema carries a second copy of the entity
 * schema, and keeping it would be exactly what the `SchemaClient` exists to prevent: two
 * callers holding two schemas is how a list ends up showing a field the form does not know.
 * Where this class needs a schema fact — whether an entity is translatable — it asks the
 * client.
 *
 * NOT IN THE CONTAINER AS A VALUE, held per instance instead. `Kernel\Container::offsetGet`
 * resolves a factory once and hands out the stored result forever, which is the wrong lifetime
 * for an answer that differs per editor. The container holds the *factory*; this object holds
 * the view for the request it was built in.
 *
 * NOT PART OF THE `SchemaClient`, and that is the same decision from the other side: the
 * client's return value is deliberately caller-independent, down to keeping the schema hash out
 * of every cache key so one held schema does not become one per editor.
 *
 * WHY NOTHING HERE RANKS A PERMISSION LEVEL. The core's four levels are not ordered by their
 * numbers — `GROUP` is 3 while `ALL` is 2 — so comparing them as numbers is a bug waiting to
 * happen, and `Classes/Permission.php` keeps a rank table for its own duplicate-row handling.
 * This class needs no rank: the only question it answers is "allowed at all", and the core
 * itself answers that by testing the level for truth (`Api.php:1284`). A level it does not
 * recognise is therefore allowed, which is the same outcome the core's table produces by
 * ranking an unknown level like `ALL`.
 *
 * `OWN` AND `GROUP` ARE NOT NARROWED HERE (ADR 0019). Both restrict per record, and the core
 * applies them itself: the list query is narrowed before it runs (`Api.php:1295-1306`), and a
 * single record, a write and a delete are refused per record. Re-deciding that here would mean
 * rebuilding an SQL condition out of record fields the admin does not even fetch.
 */
final class PermissionView
{
    /**
     * Where this view puts itself, namespaced like the schema client's key so a project's own
     * container entry cannot collide with it.
     */
    public const CONTAINER_KEY = 'contentfly_admin.permission_view';

    private const MODE_READ   = 'readable';
    private const MODE_WRITE  = 'writable';
    private const MODE_DELETE = 'deletable';

    private ApplicationInterface $app;

    private SchemaClient $schema;

    /**
     * The two keys of the extended schema this class keeps, or `null` while nothing has been
     * read yet.
     *
     * `null` is the "not read" marker, not a missing value — the read always produces an array,
     * so the two cannot be confused. `languages` stays `null` when the editor's group sets no
     * restriction at all, which is a meaningful value of its own: unrestricted.
     *
     * @var array{permissions: array<string,mixed>, languages: array<string,mixed>|null}|null
     */
    private ?array $view = null;

    private int $reads = 0;

    public function __construct(ApplicationInterface $app, SchemaClient $schema)
    {
        $this->app    = $app;
        $this->schema = $schema;
    }

    /**
     * Is somebody signed in at all?
     *
     * On the admin's own routes this is always true — they are mounted `isSecure = true`, so the
     * core's guard has run. It exists for the two routes that are not (`login` and the asset
     * routes) and for console code, where asking the core for a permission view would mean
     * dereferencing a `null` user.
     */
    public function hasEditor(): bool
    {
        return $this->editor() !== null;
    }

    /**
     * Does the editor bypass every check?
     *
     * This cannot be read off the permission flags. The flags already come back as `ALL` for an
     * administrator, but the *language* restrictions do not: `meta.i18nPermissions` publishes the
     * raw map of whatever group the administrator happens to be in, while the core waves them
     * through regardless (`Classes/I18nPermission.php:53`). Without this question the admin would
     * refuse a language its own core allows.
     */
    public function isAdmin(): bool
    {
        $editor = $this->editor();

        return $editor !== null && (bool) $editor->getIsAdmin();
    }

    public function mayRead(string $entity): bool
    {
        return $this->may(self::MODE_READ, $entity);
    }

    public function mayWrite(string $entity): bool
    {
        return $this->may(self::MODE_WRITE, $entity);
    }

    public function mayDelete(string $entity): bool
    {
        return $this->may(self::MODE_DELETE, $entity);
    }

    /**
     * May the editor change this entity's records in this language?
     *
     * This is the question the form asks, and it is deliberately the core's *write* question,
     * not its *translate* one: inserting and updating are refused when the language is
     * only-readable (`Api.php:325`, `:611`), and under `translatable` both are allowed. The
     * stricter `isWritable()` guards something else — deleting a record's rows in *other*
     * languages (`Api.php:277`) — and answering that needs the languages a given record
     * actually has, which is a question about a record and not about a right.
     *
     * The group's map lists **restrictions, not permissions**: a configured language it does not
     * mention stays open (`Entity/Group.php:164-178`). Both sides of the lookup are normalised,
     * because the map is written through the API by an administrator and its spelling is no more
     * trustworthy than a request's.
     *
     * @param string|null $lang The language, or `null` for an entity that is not translatable
     */
    public function mayEditLanguage(string $entity, ?string $lang): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        if (!$this->hasEditor()) {
            return false;
        }

        if (empty($this->schema->getSchema()[$entity]['settings']['i18n'])) {
            return true;
        }

        $normalised = Language::normalise($lang);

        // A value that names no configured language is refused rather than waved through — the
        // core closes the same hole in `I18nPermission::isKnownLanguage()`.
        if (!Language::isConfigured($normalised)) {
            return false;
        }

        $restrictions = $this->languageRestrictions();

        if ($restrictions === null || empty($restrictions[$normalised])) {
            return true;
        }

        // The core's constant carries a typo (`IS_TRANSLATABALE`). Using it instead of the
        // literal keeps this bound to the core's spelling, whichever way it is fixed.
        return $restrictions[$normalised] === I18nPermission::IS_TRANSLATABALE;
    }

    /**
     * Drops the held view so the next question reads it again.
     *
     * The counterpart to `SchemaClient::invalidate()`, and needed for the same reason: a process
     * that signs an editor in after this view was built would otherwise keep answering for
     * nobody.
     */
    public function invalidate(): void
    {
        $this->view = null;
    }

    /**
     * How often the core was actually asked for the permission view in this process.
     *
     * It exists to be asserted on. "The permissions are read once" is a promise that nothing
     * about the admin's behaviour reveals — a second read returns the same answer and merely
     * costs a schema rebuild, so a regression is invisible until it is measured.
     */
    public function getReadCount(): int
    {
        return $this->reads;
    }

    /**
     * The level for one mode, tested the way the core tests it.
     *
     * Falsy covers both of the core's refusals: `NONE`, which is `0`, and `false`, which is what
     * `Permission::is()` returns when the editor's group carries no row for this entity at all.
     */
    private function may(string $mode, string $entity): bool
    {
        return (bool) ($this->view()['permissions'][$entity][$mode] ?? false);
    }

    /**
     * The group's language map with normalised keys, or `null` for "no restriction configured".
     *
     * Empty is folded into `null` on purpose, exactly as `Group::languagePermissions()` does: a
     * map that restricts nothing is not a restriction.
     *
     * @return array<string,mixed>|null
     */
    private function languageRestrictions(): ?array
    {
        $raw = $this->view()['languages'];

        if (!is_array($raw)) {
            return null;
        }

        $normalised = array();

        foreach ($raw as $lang => $restriction) {
            if (($lang = Language::normalise($lang)) !== null) {
                $normalised[$lang] = $restriction;
            }
        }

        return $normalised === array() ? null : $normalised;
    }

    /**
     * The signed-in editor, or `null`.
     *
     * The container always knows `auth.user` — the core sets it to `null` in `bootstrap.php:221`
     * and a guarded request replaces it — so reading it never throws. The `instanceof` is what
     * makes the `null` case a decision here instead of a `TypeError` inside the core:
     * `Permission::isReadable(User $user, …)` types its parameter and does not accept `null`.
     */
    private function editor(): ?User
    {
        $editor = $this->app['auth.user'];

        return $editor instanceof User ? $editor : null;
    }

    /**
     * The permission view, built on first use and then held for the rest of this instance.
     *
     * Without an editor nothing is read and the view stays empty, which answers every question
     * with "not allowed". That is the safe direction and it is a real case: `getExtendedSchema()`
     * dereferences `auth.user->getGroup()` without a guard (gap L17), so asking it here would
     * turn a missing login into a fatal error instead of a refusal.
     *
     * @return array{permissions: array<string,mixed>, languages: array<string,mixed>|null}
     */
    private function view(): array
    {
        if ($this->view !== null) {
            return $this->view;
        }

        if (!$this->hasEditor()) {
            return $this->view = array('permissions' => array(), 'languages' => null);
        }

        ++$this->reads;

        $extended = (new Api($this->app))->getExtendedSchema();

        $permissions = $extended['permissions'] ?? array();
        $languages   = $extended['i18nPermissions'] ?? null;

        return $this->view = array(
            'permissions' => is_array($permissions) ? $permissions : array(),
            'languages'   => is_array($languages) ? $languages : null,
        );
    }
}
