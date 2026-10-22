<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Schema\Envelope;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Areanet\PIM\Classes\Api;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The file manager: folders, files, preview, and the three write paths.
 *
 * ITS OWN MOUNT UNDER `/<prefix>/files`, and it has to be one — `RouteManager` keys its
 * providers by mount path, so a second `mount($prefix, …)` would replace `AdminController`
 * instead of extending it. That the address is a function area of its own rather than
 * `/<prefix>/PIM-File` is ADR 0012, applied here by ADR 0020.
 *
 * IT IS NOT A SCHEMA-DRIVEN VIEW, and that is what makes it different from the four entry
 * points before it. The list, the form, the options search and the bulk actions all take an
 * entity from the URL and work out everything else from the schema. This one addresses two
 * fixed entities — `PIM\File` and `PIM\Folder` — because a file manager is a function, not a
 * table. The entity names are therefore constants here instead of a route placeholder.
 *
 * THE FOLDER TRAVELS IN THE QUERY STRING, never as a path segment. A placeholder in this core
 * matches a single segment and nothing reachable from a package can widen it (ADR 0007), so no
 * route could address a folder tree of arbitrary depth. The list keeps its own state in the
 * query string for the same reason (ADR 0018). ADR 0020 records the decision.
 *
 * BOTH GUARDS ARE HERE BECAUSE IT IS THE FIFTH ENTRY POINT. `GuardsPermissions` says it plainly:
 * whoever adds one adds both checks or neither. The whitelist answers 404 for an entity the
 * project did not release, the permission answers 403 as a page — and both run before the core
 * is asked anything.
 */
final class FileController
{
    use GuardsCoreVersion;
    use GuardsPermissions;

    /**
     * The entity whose records this view manages.
     *
     * A CONSTANT AND NOT A ROUTE PLACEHOLDER. The file manager is built around the fields of
     * `PIM\File` — folder, preview, name — and would not work against any other entity, so
     * taking the name from the URL would offer a freedom that does not exist. It is still
     * checked against the project's whitelist below, because a project that does not release
     * `PIM\File` does not get a file manager either (ADR 0005).
     */
    public const ENTITY_FILE = 'PIM\\File';

    /** The tree entity that gives the files their folders. */
    public const ENTITY_FOLDER = 'PIM\\Folder';

    /**
     * The query parameter that carries the open folder.
     *
     * A PARAMETER AND NOT A PATH SEGMENT (ADR 0020). A placeholder matches one segment
     * (ADR 0007), so no route could address a folder tree of arbitrary depth.
     */
    public const PARAM_FOLDER = 'folder';

    /**
     * The `where` value that asks `Api::getList()` for the records whose relation is unset.
     *
     * THE ROOT IS "NO FOLDER", not "all files". `ListController` uses the same literal for the
     * same core convention — verified against the running harness: `folder => '-1'` returns the
     * one file that sits outside every folder, while an unknown id returns none.
     */
    private const FOLDER_UNSET = '-1';

    /** How many files one page of the listing shows. */
    public const ITEMS_PER_PAGE = 50;

    /** The form field that names the action a POST asks for. */
    public const ACTION_FIELD = 'action';

    /** The three write actions this view offers. Files only — folders are navigation (ADR 0020). */
    public const RENAME = 'rename';
    public const MOVE   = 'move';
    public const DELETE = 'delete';

    /**
     * The parameter names the report owns.
     *
     * Stripped out of a carried folder address so a stale copy cannot describe an action that did
     * not happen in this request — the same rule `BulkController` follows.
     *
     * @var list<string>
     */
    public const REPORT_KEYS = array('done', 'code');

    /** What a cell shows when the value is null or empty — never an empty-looking table. */
    private const EMPTY_CELL = '—';

    /**
     * The core's own file route. Fixed, not configurable: `file` is one of the prefixes the core
     * mounts itself and the admin refuses to be mounted on (`AdminManager::RESERVED_PREFIXES`).
     */
    private const CORE_FILE_ROUTE = '/file/get';

    /**
     * The types a derivative can be made from.
     *
     * THE LIST IS THE CORE'S, NOT A GUESS. `Processing\Image::getMimeTypes()` registers exactly
     * these three; for anything else `Processing::getInstance()` hands back the `Standard`
     * processor, and `FileController::getAction` then raises `FileNotFoundException`
     * (Schema-Vertrag 8.3). Asking for a size on a fourth type is therefore not a degraded
     * preview, it is a 500 per row — which is why `image/svg+xml` and `image/webp` belong on the
     * icon side even though their names say image.
     *
     * @var list<string>
     */
    private const THUMBNAILABLE = array('image/jpeg', 'image/gif', 'image/png');

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The file manager's page.
     *
     * The route is registered `isSecure = true`: reading files goes through `Api::getList()`,
     * which asks `Permission::isReadable()` about `auth.user`, and only a guarded main request
     * sets it.
     *
     * THE THREE GATES RUN IN THIS ORDER, the same order all four earlier entry points use:
     * core version, whitelist, permission. The version guard comes first because a mismatched
     * core makes every answer below it meaningless; the whitelist comes before the permission
     * because "not released" and "not allowed" are different statements and the whitelist is
     * the outer one (ADR 0005).
     */
    public function filesAction(Request $request): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];

        if (!in_array(self::ENTITY_FILE, $manager->getEntities(), true)) {
            return new Response('', 404);
        }

        if (!$this->mayRead(self::ENTITY_FILE)) {
            return $this->accessDeniedPage(self::ENTITY_FILE);
        }

        /*
         * A POST IS AN ACTION, A GET IS THE PAGE. The mount is `match()` for exactly this reason:
         * a `post()` written beside a `get()` on the same path would have replaced it instead of
         * adding to it, so the method is decided here rather than by the router.
         *
         * A GET is never an error on this route — someone reloading after an action or typing the
         * address wants the listing, which is what follows.
         */
        if ($request->isMethod('POST')) {
            return $this->write($request, $manager->getPrefix().'/files/');
        }

        $api = new Api($this->app, $request);

        /*
         * THE TREE IS ASKED FOR ONLY WHEN IT MAY BE READ, and that is not a nicety —
         * `Api::getTree2()` raises a `ContentflyException` for a folder entity the editor may not
         * read (`Api.php:2430`). Asking first and leaving the column out is the additive masking
         * ADR 0019 settled on for the form: a missing right takes something away, it never turns
         * the whole page into a refusal. The files themselves are a different entity and stay
         * listed.
         */
        $mayReadFolders = $this->mayRead(self::ENTITY_FOLDER);
        $tree           = $mayReadFolders ? $api->getTree2(self::ENTITY_FOLDER) : null;

        $folder = self::requestedFolder($request, $tree, $mayReadFolders);
        $page   = self::page($request);

        /*
         * `getList()` ANSWERS `null` FOR AN EMPTY RESULT, not an empty list, and
         * `ListController` normalises the same special case away at the same edge.
         */
        $result = $api->getList(
            self::ENTITY_FILE,
            array(self::PARAM_FOLDER => $folder ?? self::FOLDER_UNSET),
            array('name' => 'ASC'),
            null,
            array('id', 'name', 'type', 'size', 'width', 'height', 'folder'),
            null,
            false,
            $page,
            self::ITEMS_PER_PAGE
        );

        $records = is_array($result) ? ($result['objects'] ?? array()) : array();
        $total   = is_array($result) ? (int) ($result['totalObjects'] ?? 0) : 0;

        /* Clamped the way the core clamps it, so the pager and the address cannot disagree. */
        $pages = max(1, (int) ceil($total / self::ITEMS_PER_PAGE));
        $page  = min($page, $pages);

        $base = $manager->getPrefix().'/files/';

        $files = self::rows($records, $manager->getThumbnailAlias());

        /*
         * THE FALLBACK IS THE BROWSER'S, AND IT HAS TO BE (Schema-Vertrag L25).
         *
         * Whether `thumbnailAlias` names an existing row cannot be found out from here. The rows
         * are `PIM\ThumbnailSetting`, the installer marks both of its defaults `isIntern`, and
         * `Api::getList()` hard-codes `isIntern = false` (`Api.php:1292`) — so the one read path
         * this package uses can never see them, whatever the editor's permissions. Measured, not
         * assumed: a list call for `pim_list` comes back empty on an installation that has it.
         *
         * Reaching past `Api` into the entity manager would answer the question and would also be
         * the first place in this package to do it; "pure API consumer" is what the project is.
         * So the question is left where it is answerable: the derivative either arrives or it
         * does not, and the browser is what finds out. `admin/file-preview` swaps such an image
         * for the original — which also covers the cases a lookup would have missed, a row that
         * exists but cannot be applied to this particular file among them.
         */
        $controllers = array();

        foreach ($files as $file) {
            if ($file['preview'] !== null) {
                $controllers['admin-file-preview'] = 'admin/file-preview';
                break;
            }
        }

        $token = FormToken::issue($request);

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('files.html.twig', array(
            'title'      => 'Dateien — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'tree'       => $tree === null ? null : self::treeLinks($tree, $base, $folder),
            'trail'      => $tree === null ? array() : self::trail($tree, $folder, $base),
            'rootUrl'    => $base,
            'isRoot'     => $folder === null,
            'files'       => $files,
            'controllers' => $controllers,
            'report'      => self::report($request),
            'token'       => $token,
            'tokenField'  => FormToken::FIELD,
            'actionField' => self::ACTION_FIELD,
            'actions'     => array('rename' => self::RENAME, 'move' => self::MOVE, 'delete' => self::DELETE),
            'folderField' => self::PARAM_FOLDER,
            'mayWrite'    => $this->mayWrite(self::ENTITY_FILE),
            'mayDelete'   => $this->mayDelete(self::ENTITY_FILE),
            'moveTargets' => $tree === null ? null : self::moveTargets($tree),
            'folder'      => $folder,
            'pagination' => array(
                'page'    => $page,
                'pages'   => $pages,
                'total'   => $total,
                'prevUrl' => $page > 1 ? self::url($base, $folder, $page - 1) : null,
                'nextUrl' => $page < $pages ? self::url($base, $folder, $page + 1) : null,
            ),
        ));

        $response = new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));

        /*
         * The token's cookie is set once per session, exactly as the list and the form view set
         * it: the row forms post back to this route, and without the cookie `FormToken::verify()`
         * would have nothing to compare the field against.
         */
        if (!FormToken::isIssued($request, $token)) {
            $response->headers->setCookie(FormToken::cookie($request, $token, $manager->getPrefix()));
        }

        return $response;
    }

    /**
     * Renaming, moving and deleting one file.
     *
     * FILES ONLY, NEVER FOLDERS (ADR 0020). The tree is navigation in this story; deleting a
     * folder that still holds files is a decision of its own — refuse it, or take the contents
     * with it — and this story does not carry it.
     *
     * IT REPORTS RATHER THAN 403s, the stance `BulkController` takes for the same situation: the
     * read permission above already answered whether these files are the editor's business, and
     * a missing write or delete right is an ordinary outcome of a form post. A blank 403 would
     * leave the editor on a page they have to navigate away from.
     *
     * THE RETURN ADDRESS IS WHERE THE EDITOR CAME FROM, carried as a field rather than taken from
     * the referrer. It is deliberately the *origin* folder and not the move target: after moving a
     * file out of a folder the editor is still working in that folder, and the report says where
     * the file went.
     */
    private function write(Request $request, string $base): Response
    {
        $from = $request->request->get(self::PARAM_FOLDER);
        $from = is_string($from) && $from !== '' ? $from : null;

        if (!FormToken::verify($request)) {
            return self::back($base, $from, array('done' => 'expired'));
        }

        $id = $request->request->get('id');

        if (!is_string($id) || $id === '') {
            return self::back($base, $from, array('done' => 'missing'));
        }

        $action = $request->request->get(self::ACTION_FIELD);

        if ($action === self::DELETE) {
            if (!$this->mayDelete(self::ENTITY_FILE)) {
                return self::back($base, $from, array('done' => 'forbidden'));
            }

            $envelope = $this->call($request, '/api/delete', array(
                'entity' => self::ENTITY_FILE,
                'id'     => $id,
            ));

            return self::back($base, $from, self::outcome(self::DELETE, $envelope));
        }

        if ($action === self::RENAME || $action === self::MOVE) {
            if (!$this->mayWrite(self::ENTITY_FILE)) {
                return self::back($base, $from, array('done' => 'forbidden'));
            }

            if ($action === self::RENAME) {
                $name = $request->request->get('name');

                if (!is_string($name) || trim($name) === '') {
                    return self::back($base, $from, array('done' => 'name'));
                }

                $data = array('name' => trim($name));
            } else {
                $target = $request->request->get('target');

                /*
                 * THE EMPTY TARGET IS THE ROOT, and it has to travel as `null` rather than as the
                 * `-1` the *filter* uses: `-1` is the core's convention for "where the relation is
                 * unset" in a `where`, not a value one can write into the relation.
                 */
                $data = array('folder' => is_string($target) && $target !== '' ? $target : null);
            }

            $envelope = $this->call($request, '/api/update', array(
                'entity' => self::ENTITY_FILE,
                'id'     => $id,
                'data'   => $data,
            ));

            return self::back($base, $from, self::outcome($action, $envelope));
        }

        return self::back($base, $from, array('done' => 'unknown'));
    }

    /**
     * One sub-request to the core, with its answer read as an envelope (ADR 0014).
     *
     * THE PAYLOAD GOES IN THE REQUEST BAG and the server parameters are carried over, so the
     * guarded main request's `auth.user` is the one the core checks its own per-record permission
     * against — the same shape the form and the bulk actions use.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null}
     */
    private function call(Request $request, string $endpoint, array $payload): array
    {
        $sub = Request::create($endpoint, 'POST', $payload, array(), array(), $request->server->all());

        $response = $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST);

        return Envelope::read($response->getContent(), $response->getStatusCode());
    }

    /**
     * What the report carries after the core has answered.
     *
     * A FAILURE KEEPS THE CORE'S CODE rather than being flattened into "did not work". The code is
     * what distinguishes a name that is already taken from a record somebody else deleted in the
     * meantime, and the editor can act on the difference.
     *
     * @param array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null} $envelope
     *
     * @return array<string,string>
     */
    private static function outcome(string $action, array $envelope): array
    {
        if ($envelope['ok']) {
            return array('done' => $action);
        }

        $code = is_array($envelope['error']) ? ($envelope['error']['code'] ?? null) : null;

        return array('done' => 'failed', 'code' => is_string($code) ? $code : '');
    }

    /**
     * Back to the folder the action came from, as a redirect.
     *
     * 303 AND NOT 302, so a reload of the destination is a GET and cannot repeat the action.
     */
    private static function back(string $base, ?string $folder, array $report): Response
    {
        $params = $report;

        if ($folder !== null) {
            $params[self::PARAM_FOLDER] = $folder;
        }

        return new Response('', 303, array(
            'Location' => $params === array() ? $base : $base.'?'.http_build_query($params),
        ));
    }

    /**
     * The sentence the page shows about the action that just ran, or `null` when none did.
     *
     * @return array{ok: bool, text: string}|null
     */
    private static function report(Request $request): ?array
    {
        $done = $request->query->get('done');

        if (!is_string($done) || $done === '') {
            return null;
        }

        $refusals = array(
            'expired'   => 'Die Aktion ist abgelaufen oder wurde nicht von dieser Seite abgeschickt. Bitte erneut absenden.',
            'missing'   => 'Es war keine Datei angegeben — es wurde nichts geändert.',
            'forbidden' => 'Für diese Aktion fehlt dir die Berechtigung — es wurde nichts geändert.',
            'name'      => 'Ohne Namen lässt sich die Datei nicht umbenennen — es wurde nichts geändert.',
            'unknown'   => 'Die Aktion ist unbekannt — es wurde nichts geändert.',
        );

        if (isset($refusals[$done])) {
            return array('ok' => false, 'text' => $refusals[$done]);
        }

        if ($done === 'failed') {
            $code = $request->query->get('code');

            return array(
                'ok'   => false,
                'text' => 'Die Aktion ist fehlgeschlagen'
                    .(is_string($code) && $code !== '' ? ' ('.$code.')' : '').' — es wurde nichts geändert.',
            );
        }

        $erfolge = array(
            self::RENAME => 'Die Datei ist umbenannt.',
            self::MOVE   => 'Die Datei ist verschoben.',
            self::DELETE => 'Die Datei ist gelöscht.',
        );

        return isset($erfolge[$done]) ? array('ok' => true, 'text' => $erfolge[$done]) : null;
    }

    /**
     * The folders a file can be moved into, flattened into one selectable list.
     *
     * A FLAT LIST WITH INDENTED LABELS, not a nested control: a `select` cannot nest, and the
     * depth is what tells two folders of the same name apart. The root is offered as an empty
     * value so a file can be moved out of every folder.
     *
     * @return list<array{id: string, label: string}>
     */
    private static function moveTargets(array $nodes, int $depth = 0): array
    {
        $targets = array();

        foreach ($nodes as $node) {
            $title = ($node['title'] ?? '') === '' ? self::EMPTY_CELL : (string) $node['title'];

            $targets[] = array(
                'id'    => (string) ($node['id'] ?? ''),
                'label' => str_repeat('— ', $depth).$title,
            );

            foreach (self::moveTargets($node['childs'] ?? array(), $depth + 1) as $child) {
                $targets[] = $child;
            }
        }

        return $targets;
    }

    /**
     * The folder the address asks for, or `null` for the root.
     *
     * AN UNKNOWN FOLDER FALLS BACK TO THE ROOT RATHER THAN ANSWERING 404. The folder is view
     * state in the query string, like the list's sort column and page number, and the list
     * clamps invalid state instead of refusing it (ADR 0018). A folder that was deleted while
     * someone had the page open is the ordinary way to get here, and dropping them at the root
     * is a better answer than a page that reads like a broken link.
     *
     * WITHOUT THE RIGHT TO READ FOLDERS IT CANNOT BE CHECKED, so it is passed through as an
     * opaque filter value — an unknown id then simply matches no file (verified against the
     * running harness). That asymmetry is deliberate: the alternative would be to deny a folder
     * view to an editor who may read the files in it.
     */
    private static function requestedFolder(Request $request, ?array $tree, bool $mayReadFolders): ?string
    {
        $wanted = $request->query->get(self::PARAM_FOLDER);

        if (!is_string($wanted) || $wanted === '') {
            return null;
        }

        if (!$mayReadFolders) {
            return $wanted;
        }

        return self::find($tree ?? array(), $wanted) === null ? null : $wanted;
    }

    /** The node with this id, anywhere in the tree, or `null`. */
    private static function find(array $nodes, string $id): ?array
    {
        foreach ($nodes as $node) {
            if (($node['id'] ?? null) === $id) {
                return $node;
            }

            if (($found = self::find($node['childs'] ?? array(), $id)) !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * The tree, with a URL and an open/current marker on every node.
     *
     * THE ADDRESSES ARE BUILT HERE AND NOT IN TWIG, the rule the list view follows for the same
     * reason: a template that concatenates URLs is a second place that has to know about the
     * prefix and the parameter name.
     *
     * @return list<array<string, mixed>>
     */
    private static function treeLinks(array $nodes, string $base, ?string $current): array
    {
        $items = array();

        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');

            $items[] = array(
                'id'      => $id,
                'title'   => ($node['title'] ?? '') === '' ? self::EMPTY_CELL : $node['title'],
                'url'     => self::url($base, $id, 1),
                'current' => $id === $current,
                'childs'  => self::treeLinks($node['childs'] ?? array(), $base, $current),
            );
        }

        return $items;
    }

    /**
     * The path from the root down to the open folder, as links.
     *
     * IT IS THE WAY BACK UP, and it has to be derived rather than remembered: the editor may
     * have arrived from a bookmark, where there is no history to walk back through. In the root
     * it is empty, so the template renders no trail at all instead of a link that leads where
     * the reader already is.
     *
     * @return list<array{title: string, url: string, current: bool}>
     */
    private static function trail(array $nodes, ?string $folder, string $base): array
    {
        if ($folder === null) {
            return array();
        }

        $path = self::pathTo($nodes, $folder);
        $trail = array();

        foreach ($path as $i => $node) {
            $trail[] = array(
                'title'   => ($node['title'] ?? '') === '' ? self::EMPTY_CELL : (string) $node['title'],
                'url'     => self::url($base, (string) $node['id'], 1),
                'current' => $i === count($path) - 1,
            );
        }

        return $trail;
    }

    /**
     * The chain of nodes from the root to this id, or an empty list if it is not in the tree.
     *
     * @return list<array<string, mixed>>
     */
    private static function pathTo(array $nodes, string $id): array
    {
        foreach ($nodes as $node) {
            if (($node['id'] ?? null) === $id) {
                return array($node);
            }

            $below = self::pathTo($node['childs'] ?? array(), $id);

            if ($below !== array()) {
                return array_merge(array($node), $below);
            }
        }

        return array();
    }

    /** One row per file, formatted — the template receives no raw values to format itself. */
    private static function rows(array $records, string $alias): array
    {
        $rows = array();

        foreach ($records as $record) {
            $values = is_array($record) ? $record : (array) $record;

            $id   = (string) ($values['id'] ?? '');
            $name = (string) ($values['name'] ?? '');
            $type = (string) ($values['type'] ?? '');

            $rows[] = array(
                'id'      => $id,
                'name'    => $name === '' ? self::EMPTY_CELL : $name,
                'type'    => $type === '' ? self::EMPTY_CELL : $type,
                'size'    => self::humanSize($values['size'] ?? null),
                'preview'  => self::preview($id, $name, $type, $alias),
                'original' => self::preview($id, $name, $type, null),
                'badge'    => self::badge($type),
            );
        }

        return $rows;
    }

    /**
     * One address of a file: the derivative with an alias, the stored original without one.
     *
     * `null` MEANS "SHOW THE BADGE INSTEAD" and happens in exactly one case: the file is not one
     * of the three types a derivative can be made from. For those the core answers a size request
     * with a 500, so not asking is the whole point (ADR 0020).
     *
     * THE TRAILING SEGMENT IS COSMETIC. The record is found by `{id}` alone (Schema-Vertrag 8.1);
     * the name only makes the address readable, so it is encoded and never trusted to route.
     */
    private static function preview(string $id, string $name, string $type, ?string $alias): ?string
    {
        if ($id === '' || !in_array($type, self::THUMBNAILABLE, true)) {
            return null;
        }

        $tail = $name === '' ? 'datei' : rawurlencode($name);

        if ($alias === null) {
            return self::CORE_FILE_ROUTE.'/'.rawurlencode($id).'/'.$tail;
        }

        return self::CORE_FILE_ROUTE.'/'.rawurlencode($id).'/s-'.rawurlencode($alias).'/'.$tail;
    }

    /**
     * The short label a file without a preview shows in the preview box.
     *
     * A LABEL AND NOT AN ICON FILE, because the set of types is open: a project can store
     * anything, and an icon set would either need a file per type or a generic image that says
     * less than the four letters it replaces. The subtype is what distinguishes them.
     *
     * AN UNKNOWN OR EMPTY TYPE STILL GETS SOMETHING. An empty box is indistinguishable from a
     * preview that failed to load, and that is the one reading this must not have.
     */
    private static function badge(string $type): string
    {
        $subtype = strtolower(substr($type, (int) strpos($type, '/') + 1));
        /* `svg+xml` and `vnd.openxmlformats-…` carry their family after a separator. */
        $subtype = preg_replace('#[^a-z0-9].*$#', '', $subtype) ?? '';

        return $subtype === '' ? '?' : strtoupper(substr($subtype, 0, 4));
    }

    /**
     * A byte count as something a person reads.
     *
     * Kept here rather than in a helper: it has one caller, and the package's rule is no
     * abstraction for single-use code.
     */
    private static function humanSize($bytes): string
    {
        if (!is_numeric($bytes)) {
            return self::EMPTY_CELL;
        }

        $bytes = (float) $bytes;

        foreach (array('B', 'KB', 'MB', 'GB') as $i => $unit) {
            if ($bytes < 1024 || $unit === 'GB') {
                return ($i === 0 ? (string) (int) $bytes : number_format($bytes, 1, ',', '.')).' '.$unit;
            }

            $bytes /= 1024;
        }

        return self::EMPTY_CELL;
    }

    /** The page number the address asks for, never below one. */
    private static function page(Request $request): int
    {
        $page = $request->query->get('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }

    /**
     * One address of this view.
     *
     * ONLY THE PARAMETERS THAT CARRY STATE ARE WRITTEN, and the defaults are left out — a root
     * address stays `…/files/` instead of `…/files/?folder=&page=1`, so the plain address and
     * the one a link produces are the same string.
     */
    private static function url(string $base, ?string $folder, int $page): string
    {
        $query = array();

        if ($folder !== null && $folder !== '') {
            $query[self::PARAM_FOLDER] = $folder;
        }

        if ($page > 1) {
            $query['page'] = $page;
        }

        return $query === array() ? $base : $base.'?'.http_build_query($query);
    }
}
