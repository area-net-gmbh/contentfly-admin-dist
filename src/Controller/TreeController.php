<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Schema\Envelope;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

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

        $base = $manager->getPrefix().'/tree/'.MenuBuilder::entityToUrl($name);

        if ($request->isMethod('POST')) {
            return $this->delete($request, $name, $base);
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

        $label = self::labelProperty($schema, $name);
        $nodes = self::nodes($rows, $label, $formBase);

        /*
         * THE CONFIRMATION IS A PAGE OF ITS OWN STATE, not a dialog the browser puts up. A
         * `confirm()` is the only barrier JavaScript can offer, and a delete whose only barrier
         * is JavaScript has none without it — so the link asks for this page, the page asks the
         * question, and only its form deletes (story 004-001-0005).
         */
        $confirm = null;
        $wanted  = $request->query->get('delete');

        if (is_string($wanted) && $wanted !== '' && $this->mayDelete($name)) {
            $node = self::findInTree($rows, $wanted);

            if ($node !== null) {
                $confirm = array(
                    'id'          => $wanted,
                    'label'       => self::nodeLabel($node, $label),
                    'descendants' => self::descendantCount($node),
                    'action'      => $base,
                );
            }
        }

        $token = FormToken::issue($request);

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
            'mayDelete'  => $this->mayDelete($name),
            /*
             * NO HANDLE WITHOUT BOTH (ADR 0026 §5): the schema has to say the entity carries an
             * order, and the editor has to be allowed to write. A handle whose write cannot
             * arrive is worse than no handle. The server-side check in 004-002-0003 does not
             * hang on this flag — it stands on its own, the way the delete does.
             */
            'maySort'    => $this->mayWrite($name) && self::isSortable($schema, $name),
            'confirm'    => $confirm,
            'report'     => self::report($request),
            'token'      => $token,
            'controllers' => array('admin-tree' => 'admin/tree'),
        ));

        $response = new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));

        /*
         * The token's cookie is set once per session, exactly as the list and the form view set
         * it: this page posts the delete, and without the cookie `FormToken::verify()` would have
         * nothing to compare the field against.
         */
        if (!FormToken::isIssued($request, $token)) {
            $response->headers->setCookie(FormToken::cookie($request, $token, $manager->getPrefix()));
        }

        return $response;
    }

    /**
     * Deleting one node — and, unavoidably, everything below it.
     *
     * THE COUNT IS NAMED BECAUSE THE CORE IS RECURSIVE, and that is measured, not assumed:
     * `Api::doDelete()` collects a tree entity's children over `treeParent` and calls itself per
     * child, so the children are *deleted* and never become root nodes — the `onDelete: SET NULL`
     * on `BaseTree::$treeParent` never fires, because the ORM removes the children first. A
     * confirmation that only said "has children" would promise the opposite of what happens.
     * Whoever shortens this to a cheaper sentence should read gap L29 first.
     *
     * A REJECTION IS REPORTED AS A PARTIAL CHANGE, NOT AS "NOTHING HAPPENED" (ADR 0025 §5). There
     * is no transaction around the recursion and every step flushes on its own, so a mixed
     * subtree can lose part of itself before the first refusal — measured, and measured as
     * *nondeterministic*: the children come from `findBy()` without `orderBy`, so with UUID keys
     * two identical trees can end differently. This method therefore does not guess. It counts
     * the subtree before the write and counts again afterwards, and reports the difference it
     * actually finds.
     */
    private function delete(Request $request, string $name, string $base): Response
    {
        if (!FormToken::verify($request)) {
            return self::back($base, array('done' => 'expired'));
        }

        $id = $request->request->get('id');

        if (!is_string($id) || $id === '') {
            return self::back($base, array('done' => 'missing'));
        }

        /*
         * THE RIGHT IS CHECKED HERE AND NOT ONLY WHERE THE BUTTON IS DRAWN. The tree hides the
         * action without the delete permission, but hiding a control secures nothing — this is
         * the half that closes the data (ADR 0019).
         */
        if (!$this->mayDelete($name)) {
            return self::back($base, array('done' => 'forbidden'));
        }

        $before = self::findInTree($this->treeRows($request, $name), $id);

        if ($before === null) {
            return self::back($base, array('done' => 'missing'));
        }

        $ids      = self::subtreeIds($before);
        $envelope = $this->call($request, '/api/delete', array('entity' => $name, 'id' => $id));

        /*
         * COUNTED AGAIN AFTER THE WRITE, whatever the core answered. On success this confirms the
         * number the confirmation promised; on a refusal it is the only way to tell the editor
         * whether anything went, and the measurement says it sometimes does.
         */
        $after    = $this->treeRows($request, $name);
        $survived = 0;

        foreach ($ids as $one) {
            if (self::findInTree($after, $one) !== null) {
                ++$survived;
            }
        }

        $removed = max(0, count($ids) - $survived);

        if ($envelope['ok'] && $survived === 0) {
            return self::back($base, array('done' => 'deleted', 'count' => (string) $removed));
        }

        $code = is_array($envelope['error']) ? ($envelope['error']['code'] ?? '') : '';

        return self::back($base, array(
            'done'    => $removed === 0 ? 'failed' : 'partial',
            'count'   => (string) $removed,
            'total'   => (string) count($ids),
            'code'    => is_string($code) ? $code : '',
        ));
    }

    /**
     * One sub-request to the core, with its answer read as an envelope (ADR 0014).
     *
     * Its own helper rather than a shared one, as in `FileController` and for the reason the dev
     * guide records: the payload shape is the caller's business, and the server parameters are
     * carried over so the core checks its per-record permission against the guarded main
     * request's `auth.user`.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null}
     */
    private function call(Request $request, string $endpoint, array $payload): array
    {
        $sub      = Request::create($endpoint, 'POST', $payload, array(), array(), $request->server->all());
        $response = $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST);

        return Envelope::read($response->getContent(), $response->getStatusCode());
    }

    /**
     * Back to the tree, as a redirect, with the report in the query.
     *
     * 303 AND NOT 302, so a reload of the destination is a GET and cannot repeat the delete. The
     * redirect is also what re-reads the tree: after a delete the page shows what is stored, never
     * a list this controller edited in passing (ADR 0025 §5).
     *
     * @param array<string,string> $report
     */
    private static function back(string $base, array $report): Response
    {
        return new Response('', 303, array(
            'Location' => $report === array() ? $base : $base.'?'.http_build_query($report),
        ));
    }

    /**
     * The sentence the page shows about the delete that just ran, or `null` when none did.
     *
     * THE PARTIAL CASE GETS ITS OWN SENTENCE, and it is the reason this method exists rather than
     * a one-line "worked / did not work". An editor who is told nothing happened, while two of
     * four nodes are gone, will go looking for the records — not for the rejection.
     *
     * @return array{ok: bool, text: string}|null
     */
    private static function report(Request $request): ?array
    {
        $done  = $request->query->get('done');
        $count = (int) $request->query->get('count', '0');
        $total = (int) $request->query->get('total', '0');
        $code  = (string) $request->query->get('code', '');

        if (!is_string($done) || $done === '') {
            return null;
        }

        switch ($done) {
            case 'deleted':
                return array('ok' => true, 'text' => $count === 1
                    ? 'Der Knoten wurde gelöscht.'
                    : 'Der Knoten und '.($count - 1).' Unterknoten wurden gelöscht.');
            case 'partial':
                return array('ok' => false, 'text' =>
                    'Das Löschen wurde abgelehnt, aber nicht ohne Wirkung: '.$count.' von '
                    .$total.' Knoten dieses Teilbaums sind entfernt. Der Baum unten zeigt den '
                    .'gespeicherten Stand.'.($code === '' ? '' : ' Meldung des Systems: '.$code));
            case 'failed':
                return array('ok' => false, 'text' =>
                    'Das Löschen wurde abgelehnt, es wurde nichts entfernt.'
                    .($code === '' ? '' : ' Meldung des Systems: '.$code));
            case 'forbidden':
                return array('ok' => false, 'text' => 'Für das Löschen fehlt dir die Berechtigung.');
            case 'expired':
                return array('ok' => false, 'text' =>
                    'Das Formular war abgelaufen — es wurde nichts gelöscht. Bitte erneut versuchen.');
            case 'missing':
                return array('ok' => false, 'text' =>
                    'Der Knoten ist nicht mehr da — möglicherweise hat ihn jemand anderes gelöscht.');
            default:
                return null;
        }
    }

    /**
     * The name of one raw tree row, with the id as the fallback — the rule of `nodes()`, for the
     * one row that is not rendered as a node but named in a question.
     *
     * @param array<string,mixed> $node
     */
    private static function nodeLabel(array $node, ?string $label): string
    {
        $text = $label === null ? null : ($node[$label] ?? null);

        return is_string($text) && trim($text) !== '' ? $text : (string) ($node['id'] ?? '');
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
    /**
     * May this entity's nodes be reordered at all — read from the schema, never guessed.
     *
     * `settings.isSortable` IS THE FLAG, NOT `properties.sorting.sortable`. The two are named
     * almost alike and mean different things, measured on the same schema: `settings.isSortable`
     * is `true` and says "this entity has an order of its own" (every `BaseSortable`), while
     * `properties.sorting.sortable` is `false` and says "the list may be sorted by this column".
     * A consumer reading the wrong one offers drag handles either never or always (gap `L34`,
     * ADR 0026 §5).
     */
    private static function isSortable(array $schema, string $entity): bool
    {
        return ($schema[$entity]['settings']['isSortable'] ?? null) === true;
    }

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
     * @return list<array{id: string, label: string, sorting: int, parentId: string|null, url: string, newChildUrl: string, childs: list<array<string,mixed>>}>
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

            /*
             * `sorting` AND THE PARENT TRAVEL BECAUSE THE DRAG NEEDS BOTH (story 004-002-0002),
             * and both come out of the rows `getTree2()` already delivered — no second query.
             * Measured shape (schema contract 9.1 and 10.1): `sorting` is an `integer`, `parent`
             * is `array('id' => …)` with a `null` id at a root, and `parent_id` is not in the row
             * at all.
             *
             * EVERY NODE STARTS ON `sorting = 0`, so this value is not an order — it is the
             * absence of one (gap `L33`). Three freshly created siblings all carry `0` and come
             * back in an order the database picked. It is printed anyway, because a move has to
             * state what it believed the order was.
             */
            $parent   = $row['parent'] ?? null;
            $parentId = is_array($parent) ? ($parent['id'] ?? null) : null;

            $nodes[] = array(
                'id'       => $id,
                'label'    => $text,
                'sorting'  => (int) ($row['sorting'] ?? 0),
                'parentId' => is_string($parentId) || is_int($parentId) ? (string) $parentId : null,
                'url'      => $formBase.'/'.rawurlencode($id),
                /*
                 * THE PLACE IS IN THE ADDRESS, not in a hidden field of the tree page. Opening
                 * the form is a plain link — the same way every other navigation in this admin
                 * works — and the parent travels as a query parameter, which `FormController`
                 * checks against the tree before it prefills anything (ADR 0025 §3).
                 */
                'newChildUrl' => $formBase.'?treeParent='.rawurlencode($id),
                'childs'       => self::nodes(is_array($childs) ? $childs : array(), $label, $formBase),
            );
        }

        return $nodes;
    }
}
