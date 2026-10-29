<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Auth\PermissionView;
use Areanet\ContentflyAdmin\Controller\FileController;
use Areanet\ContentflyAdmin\Page\PagePermission;
use Areanet\ContentflyAdmin\Page\PageRegistry;

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
 * IT SHOWS ONLY WHAT THE EDITOR MAY READ, and that is tidiness, not protection. An entry is
 * dropped when `PermissionView` says the entity is not readable (ADR 0019) — but hiding a link
 * secures nothing on its own: whoever knows the address would still get the page. The refusal
 * that does the securing sits in the controllers, next to each whitelist check. Both exist
 * because they answer different questions: this one keeps the menu honest, that one keeps the
 * data closed.
 *
 * THE WHITELIST STAYS THE OUTER BOUND. The permission is applied *within*
 * `AdminManager::getEntities()`, never beside it — a read permission on an entity the project
 * did not release still produces no entry (ADR 0005).
 *
 * ONE ENTRY IS NOT AN ENTITY, as of story 003-001 — and it is the one exception to everything
 * above. `PIM\File` and `PIM\Folder` get no generic list entry; in their place stands a single
 * "Dateien" entry pointing at the file manager (ADR 0020). Two menu ways to the same files,
 * one of them poorer, is the thing that decision avoids.
 *
 * The entry still obeys both bounds: it appears only where `PIM\File` is released *and*
 * readable, at the position the whitelist puts `PIM\File` in, so the configured order still
 * decides where it sits. The generic route stays reachable for whoever types it — removing it
 * would mean a special case for one entity inside the schema-driven controller.
 *
 * It takes the two entity names from `FileController`, which owns the function and is the only
 * other place that must agree about them. Repeating the two strings here is how a menu and a
 * controller start disagreeing about which entity the file manager covers.
 *
 * A TREE ENTITY IS LINKED TO THE TREE, NOT TO THE LIST, as of story 004-001 (ADR 0025). The
 * entry of an entity whose `settings.type` is `tree` points at `/<prefix>/tree/<Entity>`; the
 * list stays reachable and the tree page links to it, so filter, full-text search and bulk
 * actions are one click away rather than gone. There is still exactly **one** entry per entity —
 * a second one beside it is the duplication ADR 0020 removed for the folders.
 *
 * `PIM\Folder` IS A TREE AND STILL GETS NO ENTRY. It is skipped above for being the file
 * manager's left-hand column, and that reason outranks this one: an entry on `/tree/PIM-Folder`
 * would be the second menu way to the same folders that ADR 0020 argued away.
 *
 * WHETHER AN ENTITY IS A TREE IS ASKED OF `EntityView` and not of the schema directly, so that
 * the menu, `TreeController` and `ListController` cannot drift apart about it. The laziness
 * promise above still holds: `EntityView` reads nothing when it is constructed, only when it is
 * asked.
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

    private PermissionView $permissions;

    private EntityView $entityView;

    private PageRegistry $pages;

    public function __construct(
        AdminManager $manager,
        PermissionView $permissions,
        EntityView $entityView,
        PageRegistry $pages
    ) {
        $this->manager     = $manager;
        $this->permissions = $permissions;
        $this->entityView  = $entityView;
        $this->pages       = $pages;
    }

    /**
     * The menu items the editor may read, in the order the configuration or the schema lists
     * the entities.
     *
     * An editor with no read permission anywhere gets an empty list, which is a valid answer and
     * not an error: `layout.html.twig` renders no navigation at all for it rather than an empty
     * bar.
     *
     * THE PROJECT PAGES COME AFTER THE ENTITIES, and the entities keep the order the whitelist
     * gives them — a page cannot push a list entry anywhere. Pages without a `group` join the
     * plain tail; grouped ones follow, each group in the order the project registered it, and
     * the first entry of a group carries the heading.
     *
     * THE HEADING IS DECIDED HERE AND NOT IN THE TEMPLATE. A template tracking "has the group
     * changed?" across loop iterations would be the rule split in two, which is exactly what
     * `_navigation.html.twig` says it avoids for the URL mapping.
     *
     * IT STAYS ONE FLAT LIST, deliberately. `layout.html.twig` renders no navigation at all when
     * `menu` is empty, and that is the right answer for an editor who may read nothing. A
     * structure of two keys would always be non-empty and would hand that editor an empty bar.
     *
     * @return list<array{entity: string|null, label: string, url: string, groupHeading: string|null}>
     */
    public function build(): array
    {
        $items = array();

        foreach ($this->manager->getEntities() as $entity) {
            if (!$this->permissions->mayRead($entity)) {
                continue;
            }

            /*
             * THE FOLDERS NEVER GET AN ENTRY OF THEIR OWN. They are the file manager's left-hand
             * column, not a table an editor maintains — and a second entry leading to the same
             * tree is exactly the duplication ADR 0020 removes.
             */
            if ($entity === FileController::ENTITY_FOLDER) {
                continue;
            }

            /*
             * THE FILE MANAGER TAKES `PIM\File`'s PLACE rather than being appended, so the
             * whitelist keeps deciding the order of the menu. Both bounds still hold: this line
             * is only reached when the entity is released (it comes from `getEntities()`) and
             * readable (checked above).
             *
             * THE TRAILING SLASH IS NOT COSMETIC. `CustomControllerProvider::connect()` registers
             * a route as `'/'.$route`, so a mount at `<prefix>/files` with an empty route name
             * answers at `<prefix>/files/` — the same shape `AdminController` has at `<prefix>/`.
             * Linking the slashless form would rely on a trailing-slash redirect that does not
             * exist for the POST half of the `match()`.
             */
            if ($entity === FileController::ENTITY_FILE) {
                /*
                 * A CONFIGURED LABEL BEATS THE HARD-CODED ONE, and that is the decision rather
                 * than an oversight. `PIM\File` belongs to the core, so a project cannot put an
                 * attribute on it — stage 3 is the only lever it has there, and being the only
                 * lever for the core's entities is the whole reason stage 3 shipped in v1
                 * (ADR 0031). `Dateien` stays the fallback, so nothing changes for a project that
                 * configures nothing. `PIM\Folder` has no entry at all (skipped above), so a
                 * label on it has no reader — the scope limit ADR 0031 names.
                 */
                $items[] = array(
                    'entity'       => $entity,
                    'label'        => $this->entityView->label($entity, 'Dateien'),
                    'url'          => $this->manager->getPrefix().'/files/',
                    'groupHeading' => null,
                );

                continue;
            }

            /*
             * A TREE ENTITY POINTS AT THE TREE (ADR 0025). The segment is the only difference —
             * one entry either way, so the configured order of the whitelist still decides where
             * it sits. `PIM\Folder` never reaches this line; it was skipped above.
             */
            $area = $this->entityView->isTree($entity) ? '/tree/' : '/list/';

            $items[] = array(
                'entity'       => $entity,
                'label'        => $this->entityView->label($entity),
                'url'          => $this->manager->getPrefix().$area.self::entityToUrl($entity),
                'groupHeading' => null,
            );
        }

        return array_merge($items, $this->pageItems());
    }

    /**
     * The menu entries of the project's own pages, grouped.
     *
     * A PAGE WITH A `permission` APPEARS ONLY WHERE THE EDITOR HOLDS IT, and the answer comes
     * from the same `PagePermission` the page itself asks before it renders (ADR 0028 §3). That
     * is the point of it being one class: a menu offering a page that refuses itself is a dead
     * link, and a page reachable although the menu hides it is the inverse and worse.
     *
     * A PAGE WITHOUT A `permission` IS LISTED FOR EVERY SIGNED-IN EDITOR, which is what the
     * attribute promises. Signed in, not everyone — the mount carries `isSecure`.
     *
     * THE ADDRESS CARRIES THE TRAILING SLASH, for the reason the file entry above records: a
     * mount's own route has the empty name, so the page answers at `<prefix><route>/`, and the
     * slashless form is a method-not-allowed rather than a redirect.
     *
     * @return list<array{entity: string|null, label: string, url: string, groupHeading: string|null}>
     */
    private function pageItems(): array
    {
        $plain   = array();
        $grouped = array();

        foreach ($this->pages->all() as $page) {
            if ($page->permission !== null
                && !PagePermission::parse($page->permission)->isGranted($this->permissions)) {
                continue;
            }

            $item = array(
                'entity'       => null,
                'label'        => $page->label,
                'url'          => $this->manager->getPrefix().$page->route.'/',
                'groupHeading' => null,
            );

            if ($page->group === null) {
                $plain[] = $item;

                continue;
            }

            $grouped[$page->group][] = $item;
        }

        $items = $plain;

        foreach ($grouped as $group => $entries) {
            $entries[0]['groupHeading'] = (string) $group;

            foreach ($entries as $entry) {
                $items[] = $entry;
            }
        }

        return $items;
    }

    /**
     * The URL segment that addresses one entity.
     *
     * The URL segment stays derived from the schema name and is unaffected by any label. Since
     * story 005-005 a friendlier label does have a source — `ui[<entity>]['label']`, read through
     * `EntityView::label()` — but it is a display name and must never reach an address: two
     * projects could configure the same label, and a route derived from it would stop being the
     * entity's own.
     *
     * `tools/harness-admin-pruefen.php` used to require *every* entity name of the schema in the
     * page body. Since story 003-001 that is no longer true for `PIM\File` and `PIM\Folder`:
     * they have no generic entry any more (ADR 0020), and the gate knows about the two
     * exceptions.
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
