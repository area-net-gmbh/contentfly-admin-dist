<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\ContentflyAdmin\AdminManager;

/**
 * The admin's main menu, built from the schema.
 *
 * NOBODY MAINTAINS THIS MENU, and that is the whole point of it. A project installs the
 * package and the interface knows its entities, because they are in the schema. There is no
 * list of menu items anywhere in a project, and therefore nothing that can fall behind the
 * model.
 *
 * THE SCOPE COMES FROM `AdminManager::getEntities()` AND FROM NOWHERE ELSE. That method
 * already resolves the project's `entities` whitelist and falls back to the schema's own list
 * (ADR 0005). Asking the `SchemaClient` here instead would put a second opinion about what the
 * admin administers into the package — and two opinions are how a menu and a form end up
 * disagreeing.
 *
 * IT READS NOTHING UNTIL IT IS ASKED. The builder is registered as a lazy factory while
 * `custom/app.php` runs — before `bindRoutes()`, in every console run and during installation,
 * where there may not be a database at all. Constructing it therefore touches neither the
 * schema nor the container's heavier services; `build()` is the first moment anything is read.
 *
 * PERMISSIONS ARE NOT ITS JOB YET. That a user without read access does not see an entity here
 * is story 002-009; no provision is made for it on spec.
 */
final class MenuBuilder
{
    /** Where the builder puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.menu_builder';

    /**
     * The character that stands in for the namespace separator of an entity name in a URL.
     *
     * ENTITY NAMES ARE CLASS NAMES AND CARRY BACKSLASHES — `PIM\File`, `Harness\Feldtyp`. A
     * backslash cannot travel in a URL path: percent-encoded it survives the standard, but
     * proxies, WAFs and IIS are known to normalise `\` to `/` and would silently cut the
     * address into two segments. The admin therefore addresses an entity as `PIM-File`.
     *
     * THE MAPPING IS LOSSLESS BECAUSE A PHP CLASS NAME CANNOT CONTAIN A HYPHEN, so every
     * hyphen in such a segment is one of ours and `urlToEntity()` can turn it back. ADR 0011.
     */
    public const NAMESPACE_SEPARATOR = '-';

    private AdminManager $manager;

    public function __construct(AdminManager $manager)
    {
        $this->manager = $manager;
    }

    /**
     * The menu items, in the order the configuration or the schema lists the entities.
     *
     * @return list<array{entity: string, label: string, url: string}>
     */
    public function build(): array
    {
        $items = array();

        foreach ($this->manager->getEntities() as $entity) {
            $items[] = array(
                'entity' => $entity,
                'label'  => $entity,
                'url'    => $this->manager->getPrefix().'/list/'.self::entityToUrl($entity),
            );
        }

        return $items;
    }

    /**
     * The URL segment that addresses one entity.
     *
     * The label stays the schema name untouched: `tools/harness-admin-pruefen.php` requires
     * every entity name of the schema to appear in the page body, and a friendlier label is a
     * question of its own — it would need a source (schema, translation) that does not exist
     * yet.
     */
    public static function entityToUrl(string $entity): string
    {
        return rawurlencode(str_replace('\\', self::NAMESPACE_SEPARATOR, $entity));
    }

    /**
     * The entity name behind a URL segment — the exact inverse of `entityToUrl()`.
     *
     * Story 002-003 mounts the list route and is the first caller. It lives here and not there
     * so that the two directions cannot drift apart: whoever changes one sees the other.
     */
    public static function urlToEntity(string $segment): string
    {
        return str_replace(self::NAMESPACE_SEPARATOR, '\\', rawurldecode($segment));
    }
}
