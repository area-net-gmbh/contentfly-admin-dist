<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\FormToken;
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
    use CallsCore;
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
     * The field that asks for a machine-readable answer instead of the redirect.
     *
     * AN EXPLICIT FIELD AND NOT `Accept`, and its own constant rather than `FileController`'s
     * (ADR 0021, and the same reasoning as `call()` below): the module builds its own body, so
     * it can state what it wants, and the gate can test either branch without forging headers.
     */
    public const FORMAT_FIELD = '_format';

    /** The one value `FORMAT_FIELD` understands. Anything else is the ordinary redirect. */
    public const FORMAT_JSON = 'json';

    /**
     * The code a report carries when the core answered nothing an envelope could be read from.
     *
     * THE SAME MARKER `BulkController` ALREADY USES, and for the same reason it documents: a
     * failure inside a sub-request does not always come back as an envelope — with devmode on the
     * core answers its debug page and `Envelope::read()` has nothing to decode. Until story
     * 007-001 this controller read only `ok` and `error`, so that case arrived here as an empty
     * code and the editor was told the delete was "refused" with no reason at all, which reads as
     * if the core had given none.
     *
     * NOT AN INVENTED ERROR CODE but a marker of this package, which is why it carries no
     * `contentfly_` prefix and why `systemNote()` turns it into a sentence instead of printing it.
     */
    public const UNREADABLE = 'unreadable';

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
            /*
             * THE DISCRIMINATOR SITS ON THE NEW PATH, NOT ON THE OLD ONE. The delete form posts
             * `id` and the CSRF field and nothing else, and it has done so since 004-001-0005 — adding
             * a required `action` to it would have changed a contract the gate already holds.
             * So a move says that it is one, and everything else on this route is still a
             * delete.
             */
            if ($request->request->get('action') === 'move') {
                return $this->move($request, $name, $base);
            }

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
            /*
             * THE HEADING TAKES THE LABEL, THE REST KEEPS THE NAME. `entity` is also an
             * address and a data attribute, so a configured label must not replace it --
             * it is a display name, and two entities may carry the same one
             * (005-005-0004).
             */
            'entityLabel' => $this->app[EntityView::CONTAINER_KEY]->label($name),
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
            'tokenField' => FormToken::FIELD,
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

        /*
         * AND THE LANGUAGE, WHERE THE ENTITY HAS ONE. A tree entity may be translatable, and then
         * the entity right is only half the question (ADR 0019 §5). The language comes from the
         * query the page carried into this post, the same source the bulk bar uses — the action's
         * own address carries none. For an entity without `settings.i18n` this is always true,
         * so it costs a non-translatable tree nothing.
         */
        if (!$this->mayEditLanguage($name, self::carriedLanguage($request))) {
            return self::back($base, array('done' => 'forbidden'));
        }

        $before = self::findInTree($this->treeRows($request, $name), $id);

        if ($before === null) {
            return self::back($base, array('done' => 'missing'));
        }

        $ids      = self::subtreeIds($before);
        $envelope = $this->callCore($request, '/api/delete', array('entity' => $name, 'id' => $id));

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

        $code = self::reportCode($envelope);

        return self::back($base, array(
            'done'    => $removed === 0 ? 'failed' : 'partial',
            'count'   => (string) $removed,
            'total'   => (string) count($ids),
            'code'    => is_string($code) ? $code : '',
        ));
    }

    /**
     * Moving one node: a new position among its siblings, and possibly a new parent.
     *
     * THE WHOLE TARGET LEVEL IS REWRITTEN, NOT THE ONE NODE THAT MOVED (ADR 0026 §3), and the
     * reason is measured rather than cautious: `BaseSortable::$sorting` is pre-set to `0` and the
     * core sets nothing on create, so three freshly created siblings all carry `0` and come back
     * in an order the database picked — created `a`, `b`, `c`, answered `a`, `c`, `b` (gap
     * `L33`, schema contract 10.1). There is no existing sequence to insert a single value into.
     *
     * THE SOURCE LEVEL IS DELIBERATELY *NOT* RENUMBERED, and ADR 0026 §3 was corrected on
     * 26.10.2026 to say so. Its original reasoning — "a gap left behind becomes the next tie" —
     * is simply wrong: `1, 3` has a gap but is still a total order, while a tie needs two *equal*
     * values. Renumbering it was therefore cosmetic, and measured it was not free: with
     * `writable = OWN` the source level is usually full of other editors' records, every one of
     * them would be written, and `multiupdate` refuses the whole move for them — so an editor
     * could not move their own node *out* of a shared level at all. Measured both ways: the same
     * call without the source level succeeds (`200`, parent set), with it the core refuses. Gaps
     * also heal themselves, because the next node moved *into* that level renumbers it `1..n`.
     *
     * ONE `multiupdate`, NOT ONE `update` PER NODE. `ApiController::multiupdateAction()` wraps
     * the whole loop in `beginTransaction()` … `commit()` — measured: one valid object plus one
     * invented id answers `404` and leaves the valid change **rolled back**. That is exactly the
     * "jump back" this story asks for, so it comes from the core instead of being rebuilt here.
     * A loop of single calls would commit each on its own and leave a half-renumbered level.
     *
     * ONLY THE MOVED NODE CARRIES `treeParent`. Measured: `treeParent: ''` and `treeParent: null`
     * both set `parent_id` to NULL and make the node a root, while *omitting* the key leaves the
     * parent untouched. The renumbered siblings therefore must not carry it — sending it for them
     * would re-assert a parent the editor never touched, and gap `L32` makes that unsafe: a
     * parent the editor cannot read is silently written as NULL and still answered with success.
     *
     * A SUCCESS ANSWER IS NOT PROOF, for that same reason. The tree is re-read afterwards and the
     * node's actual parent compared against the requested one; only then is the move reported as
     * done (ADR 0025 §5 and ADR 0026 §4).
     */
    private function move(Request $request, string $name, string $base): Response
    {
        if (!FormToken::verify($request)) {
            return self::moveAnswer($request, $base, 'expired');
        }

        /*
         * THE RIGHT AND THE SCHEMA ARE CHECKED HERE, NOT ONLY WHERE THE HANDLE IS DRAWN. The
         * template renders no drag attributes without both (004-002-0002), but a hidden control
         * secures nothing — this is the half that closes the data (ADR 0019, ADR 0026 §5).
         */
        if (!$this->mayWrite($name)) {
            return self::moveAnswer($request, $base, 'forbidden');
        }

        /* The language half of the same question — see the delete path above. */
        if (!$this->mayEditLanguage($name, self::carriedLanguage($request))) {
            return self::moveAnswer($request, $base, 'forbidden');
        }

        if (!self::isSortable($this->app[SchemaClient::CONTAINER_KEY]->getSchema(), $name)) {
            return self::moveAnswer($request, $base, 'unsortable');
        }

        $id = $request->request->get('id');

        if (!is_string($id) || $id === '') {
            return self::moveAnswer($request, $base, 'missing');
        }

        $rows = $this->treeRows($request, $name);
        $node = self::findInTree($rows, $id);

        if ($node === null) {
            return self::moveAnswer($request, $base, 'missing');
        }

        $parent = $request->request->get('parent');
        $parent = is_string($parent) ? $parent : '';

        /*
         * ONE CHECK FOR THREE FAILURES, as `FormController` does it for the preset parent: an id
         * that is not in *this* editor's tree is either not a record of this entity, or not
         * readable, or `isIntern` — and all three have to fail the same way. Telling them apart
         * would say something about records the editor cannot see.
         */
        if ($parent !== '' && self::findInTree($rows, $parent) === null) {
            return self::moveAnswer($request, $base, 'missing');
        }

        /*
         * THE SUBTREE REFUSAL IS THE ONE THAT CANNOT BE SKIPPED (gap `L31`). The core accepts a
         * cycle without a word, writes it, and because `treeSort()` only descends from rows with
         * an empty `parent_id` the whole connected component of the cycle is unreachable
         * afterwards — measured: `multiupdate` answers `200`, the cycle really is in `pim_tree`,
         * and ten nodes became seven with no error anywhere (schema contract 10.4).
         */
        if ($parent !== '' && in_array($parent, self::subtreeIds($node), true)) {
            return self::moveAnswer($request, $base, 'cycle');
        }

        $target = array_values(array_diff(self::siblingIds($rows, $parent), array($id)));

        $position = (int) $request->request->get('position', '0');
        $position = max(0, min($position, count($target)));

        array_splice($target, $position, 0, array($id));

        $objects = array();

        foreach ($target as $index => $one) {
            $data = array('sorting' => $index + 1);

            if ($one === $id) {
                $data[self::PARENT_PROPERTY] = $parent;
            }

            $objects[] = array('entity' => $name, 'id' => $one, 'data' => $data);
        }

        $envelope = $this->callCore($request, '/api/multiupdate', array('objects' => $objects));

        /*
         * RE-READ, THEN JUDGE. `$envelope['ok']` says the core committed; it does not say the
         * node is where it was asked to go (gap `L32`). The difference is a whole report: a move
         * the core accepted but whose parent it dropped leaves the editor with a root node and
         * no complaint anywhere.
         */
        $after  = $this->treeRows($request, $name);
        $landed = self::parentIdOf($after, $id);
        $wanted = $parent === '' ? null : $parent;

        $code = self::reportCode($envelope);

        if ($envelope['ok'] && $landed === $wanted) {
            $outcome = 'ok';
        } elseif ($envelope['ok']) {
            $outcome = 'dropped';
        } else {
            $outcome = 'failed';
        }

        return self::moveAnswer(
            $request,
            $base,
            $outcome,
            $code,
            $outcome === 'ok' ? self::siblingIds($after, $parent) : array(),
            $parent
        );
    }

    /**
     * One answer to a move, in the shape the caller asked for (ADR 0021's two-sided answer).
     *
     * THE JSON BRANCH HANDS BACK THE ORDER THE SERVER JUST RE-READ, not an acknowledgement. That
     * is what keeps ADR 0026 §4 intact without a full page load per drag: the module does not
     * keep the arrangement it dropped, it applies the one that is stored. The two differ whenever
     * somebody else changed the tree in between — rare, and silently wrong if the module were
     * trusted instead.
     *
     * EVERY EXIT COMES THROUGH HERE, the refusals included. A module that posted JSON and got a
     * 303 back for "no permission" would read the HTML of the tree page as its answer and
     * conclude nothing at all.
     *
     * @param list<string> $order
     */
    private static function moveAnswer(
        Request $request,
        string $base,
        string $outcome,
        string $code = '',
        array $order = array(),
        string $parent = ''
    ): Response {
        if (self::wantsJson($request)) {
            $report = self::moveReport($outcome, $code);

            return new Response(
                (string) json_encode(array(
                    'ok'     => $outcome === 'ok',
                    'text'   => $report === null ? '' : $report['text'],
                    'parent' => $parent,
                    'order'  => $order,
                )),
                200,
                array('Content-Type' => 'application/json; charset=UTF-8')
            );
        }

        return self::back($base, $code === ''
            ? array('moved' => $outcome)
            : array('moved' => $outcome, 'code' => $code));
    }

    /** Did the caller ask for JSON instead of the redirect? */
    private static function wantsJson(Request $request): bool
    {
        return ($request->request->all()[self::FORMAT_FIELD] ?? null) === self::FORMAT_JSON;
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
    /**
     * The code a report carries for one envelope: the core's own, or the marker for no envelope.
     *
     * `readable` IS ASKED FIRST because it answers a different question than `error`. An
     * unreadable answer has no `errors` array to look in, so reading `error` alone cannot tell
     * "the core refused and said why" from "the core never answered in a form we can read" —
     * both arrive as an empty code.
     *
     * @param array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null} $envelope
     */
    private static function reportCode(array $envelope): string
    {
        if (!$envelope['readable']) {
            return self::UNREADABLE;
        }

        $code = is_array($envelope['error']) ? ($envelope['error']['code'] ?? '') : '';

        return is_string($code) ? $code : '';
    }

    /**
     * What a report appends about the system's own answer, if it has anything to append.
     *
     * THE MARKER BECOMES A SENTENCE HERE, in one place, because it is not a message key the core
     * sent — printing it as one would show the editor the word "unreadable" and call it the
     * system's message.
     */
    private static function systemNote(string $code): string
    {
        if ($code === '') {
            return '';
        }

        if ($code === self::UNREADABLE) {
            return ' Die Antwort des Grundsystems war nicht auswertbar.';
        }

        return ' Meldung des Systems: '.$code;
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
        $moved = $request->query->get('moved');

        if (is_string($moved) && $moved !== '') {
            return self::moveReport($moved, $code);
        }

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
                    .'gespeicherten Stand.'.self::systemNote($code));
            case 'failed':
                return array('ok' => false, 'text' =>
                    'Das Löschen wurde abgelehnt, es wurde nichts entfernt.'
                    .self::systemNote($code));
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
     * The sentence the page shows about the move that just ran.
     *
     * NO SENTENCE CLAIMS MORE THAN WAS CHECKED, and three of these seven exist only because the
     * core makes them reachable. `cycle` says what would have happened, because the damage it
     * prevents is invisible — the branch would simply be gone from the admin (gap `L31`).
     * `dropped` is the case the core answers with success while silently writing no parent at
     * all (gap `L32`): the move "worked" and the node is somewhere else than asked, so the
     * editor is told exactly that instead of being congratulated. `failed` says plainly that
     * nothing changed, and it may say so because `multiupdate` runs in one transaction — that
     * is measured (schema contract 10.2), not hoped for.
     *
     * @return array{ok: bool, text: string}|null
     */
    private static function moveReport(string $moved, string $code): ?array
    {
        $system = self::systemNote($code);

        switch ($moved) {
            case 'ok':
                return array('ok' => true, 'text' =>
                    'Der Knoten wurde verschoben. Die Reihenfolge dieser Ebene ist damit '
                    .'festgeschrieben — vorher stand sie nicht fest.');
            case 'cycle':
                return array('ok' => false, 'text' =>
                    'Ein Knoten kann nicht unter sich selbst oder unter einen seiner '
                    .'Unterknoten gehängt werden: der ganze Ast wäre danach aus der Oberfläche '
                    .'verschwunden. Es wurde nichts geändert.');
            case 'dropped':
                return array('ok' => false, 'text' =>
                    'Der Vorgang wurde angenommen, der Zielknoten aber nicht übernommen — der '
                    .'Knoten steht jetzt woanders als angefordert. Der Baum unten zeigt den '
                    .'gespeicherten Stand.'.$system);
            case 'failed':
                return array('ok' => false, 'text' =>
                    'Das Verschieben wurde abgelehnt. Es wurde nichts geändert — der Baum unten '
                    .'zeigt den gespeicherten Stand.'.$system);
            case 'forbidden':
                return array('ok' => false, 'text' =>
                    'Für das Verschieben fehlt dir die Berechtigung.');
            case 'unsortable':
                return array('ok' => false, 'text' =>
                    'Diese Datenart führt keine eigene Reihenfolge — ihre Knoten lassen sich '
                    .'nicht umsortieren.');
            case 'expired':
                return array('ok' => false, 'text' =>
                    'Das Formular war abgelaufen — es wurde nichts verschoben. Bitte erneut '
                    .'versuchen.');
            case 'missing':
                return array('ok' => false, 'text' =>
                    'Der Knoten oder die Zielstelle ist nicht mehr da — möglicherweise hat '
                    .'jemand anderes den Baum geändert. Es wurde nichts verschoben.');
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
