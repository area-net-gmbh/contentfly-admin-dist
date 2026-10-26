<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The records of one `BaseTree` entity, as a tree.
 *
 * ITS OWN MOUNT UNDER `/<prefix>/tree`, the ninth — one mount binds one controller class, and
 * the segment under the prefix is ADR 0012, exactly as for the list. The address is
 * `/<prefix>/tree/<Entity>` with the entity as a hyphen segment (ADR 0011).
 *
 * ONE ENTITY IS EITHER A TREE OR A TABLE, AND THE MENU PICKS ONE. `MenuBuilder` links an entity
 * whose `settings.type` is `tree` here instead of to `/list`, and this page links back to the
 * list (ADR 0025). Both routes keep working for whoever types them — the switch is a
 * convenience, not a boundary, and the boundary is checked on both.
 *
 * THE PERMISSION IS CHECKED BEFORE THE CORE IS ASKED, and here that is not tidiness.
 * `Api::getTree2()` *raises* a `ContentflyException` for an entity the editor may not read, so
 * without the check a withdrawn permission would surface as a 500 instead of the 403 page ADR
 * 0019 §6 asks for. The file manager made that mistake once and the dev guide records it.
 *
 * AN ENTITY THAT IS NOT A TREE ANSWERS 404. This route is not a second address for an ordinary
 * entity: it would render an empty tree for one, which says "no records" where the truth is
 * "wrong view". 404 is the same answer the whitelist gives, and for the same kind of reason —
 * for the admin, a tree of a non-tree entity does not exist.
 */
final class TreeController
{
    use GuardsCoreVersion;
    use GuardsPermissions;
    use ResolvesTree;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * One tree entity's records.
     *
     * The route is registered `isSecure = true`, so `auth.user` is set by the time the core is
     * asked — `Api::getTree2()` would otherwise dereference a null user inside
     * `Permission::isReadable()`.
     *
     * @param string $entity The entity's URL segment, e.g. `Harness-FeldtypBaum` (ADR 0011)
     */
    public function treeAction(Request $request, string $entity): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $name    = MenuBuilder::urlToEntity($entity);

        /*
         * THE THREE GATES IN THE ORDER THE OTHER CONTROLLERS USE THEM: the core version first
         * (above), then the whitelist, then the permission. The tree check is a fourth and sits
         * last of the four on purpose — it answers a question about the entity, and an entity
         * the project did not release or the editor may not read should not be distinguishable
         * by its shape.
         */
        if (!in_array($name, $manager->getEntities(), true)) {
            return new Response('', 404);
        }

        if (!$this->mayRead($name)) {
            return $this->accessDeniedPage($name);
        }

        if (!$this->app[EntityView::CONTAINER_KEY]->isTree($name)) {
            return new Response('', 404);
        }

        $listUrl = $manager->getPrefix().'/list/'.MenuBuilder::entityToUrl($name);
        $formBase = $manager->getPrefix().'/form/'.MenuBuilder::entityToUrl($name);

        $schema = $this->app[SchemaClient::CONTAINER_KEY]->getSchema();

        /*
         * READ IN-PROCESS, NEVER OVER HTTP (ADR 0006), and `getTree2()` rather than `getTree()`:
         * the first reads the whole tree in one query, the second calls itself once per node
         * (ADR 0025). The permission was checked above, which is what keeps this call from
         * raising instead of answering.
         */
        $rows = $this->treeRows($request, $name);

        $nodes = self::nodes($rows, self::labelProperty($schema, $name), $formBase);

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('tree.html.twig', array(
            'title'      => $name.' — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'entity'     => $name,
            'listUrl'    => $listUrl,
            'newUrl'     => $formBase,
            'nodes'      => $nodes,
            'controllers' => array('admin-tree' => 'admin/tree'),
        ));

        return new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));
    }

    /**
     * The property that names a record, or `null` when the entity does not say.
     *
     * THE SAME RULE THE RELATION SELECT USES, and deliberately not a better one. `labelProperty`
     * defaults to `null` (gap L6) and `RelationOptions::describe()` answers that case with the
     * record's id — a guessed first `string` field was considered for the select and rejected in
     * ADR 0016, because a guessed label is indistinguishable from a maintained one and hides the
     * fact that nobody maintained it. A tree of ids is ugly on purpose.
     *
     * A name pointing at a property the entity does not have counts as not set, exactly as it
     * does there: the value would never arrive in the row.
     *
     * @param array<string,mixed> $schema
     */
    private static function labelProperty(array $schema, string $entity): ?string
    {
        $label = $schema[$entity]['settings']['labelProperty'] ?? null;

        if (!is_string($label) || $label === '') {
            return null;
        }

        return isset($schema[$entity]['properties'][$label]) ? $label : null;
    }

    /**
     * The rows of `Api::getTree2()`, turned into what the template prints.
     *
     * THE TEMPLATE NEVER SEES A CORE ROW. `getTree2()` answers raw database columns — every
     * scalar property of the entity, the accounting fields included, and `join`/`file` as
     * `array('id' => …)` (schema contract section 9.1). Handing that to Twig would put the
     * decision which of them is printable into the template.
     *
     * `isIntern` IS FILTERED HERE, AND THE SUBTREE GOES WITH IT (ADR 0025 §4). `getTree2()` does
     * not filter it although `getTree()` and `Api::getList()` do (gap L28) — unfiltered, the tree
     * would show records the list of the same entity never shows, and no editor can tell that
     * apart from a bug. Dropping the subtree with the node is not a second rule: it is what the
     * core itself does with `OWN`/`GROUP`, where `treeSort()` descends from the roots and a child
     * under a dropped parent is dropped too.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<array{id: string, label: string, url: string, newChildUrl: string, childs: list<array<string,mixed>>}>
     */
    private static function nodes(array $rows, ?string $label, string $formBase): array
    {
        $nodes = array();

        foreach ($rows as $row) {
            if (!is_array($row) || !empty($row['isIntern'])) {
                continue;
            }

            $id = $row['id'] ?? null;

            if (!is_string($id) && !is_int($id)) {
                continue;
            }

            $id   = (string) $id;
            $text = $label === null ? null : ($row[$label] ?? null);
            $text = is_string($text) && trim($text) !== '' ? $text : $id;

            $childs = $row['childs'] ?? array();

            $nodes[] = array(
                'id'    => $id,
                'label' => $text,
                'url'   => $formBase.'/'.rawurlencode($id),
                /*
                 * THE PLACE IS IN THE ADDRESS, not in a hidden field of the tree page. Opening
                 * the form is a plain link — the same way every other navigation in this admin
                 * works — and the parent travels as a query parameter, which `FormController`
                 * checks against the tree before it prefills anything (ADR 0025 §3).
                 */
                'newChildUrl' => $formBase.'?treeParent='.rawurlencode($id),
                'childs'      => self::nodes(is_array($childs) ? $childs : array(), $label, $formBase),
            );
        }

        return $nodes;
    }
}
