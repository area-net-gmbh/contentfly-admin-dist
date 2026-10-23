<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Schema\Envelope;
use Areanet\ContentflyAdmin\View\FieldError;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Symfony\Component\HttpFoundation\File\UploadedFile;
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

    /** The write actions this view offers. Files only — folders are navigation (ADR 0020). */
    public const RENAME = 'rename';
    public const MOVE   = 'move';
    public const DELETE = 'delete';

    /**
     * Uploading one file.
     *
     * ONE FILE PER REQUEST, not a batch (ADR 0021). A batch POST that exceeds `post_max_size`
     * arrives with an empty `$_POST` — the token field included —, so a size problem would be
     * reported as an expired form and the editor would look in the wrong place. A per-file
     * progress bar is not derivable from a batch either: `upload.onprogress` only knows the
     * total.
     */
    public const UPLOAD = 'upload';

    /**
     * The action the metadata form on a file's own page submits (003-003-0004).
     *
     * ITS OWN ACTION AND NOT A FOURTH BRANCH OF `write()`. The three write paths of the listing
     * all return to a folder; this one returns to the file's page and keeps the editor's input
     * when the core refuses, which a 303 cannot do.
     */
    public const METADATA = 'metadata';

    /**
     * The three fields the metadata form writes, and nothing else.
     *
     * `description` IS LEFT OUT ON PURPOSE (ADR 0022). It exists on `PIM\File`, story 003-003
     * does not ask for it, and a field nobody asked for is one more thing to keep.
     */
    public const METADATA_FIELDS = array('alias', 'title', 'altText');

    /**
     * The column length all three share.
     *
     * MEASURED IN THE RUNNING DATABASE, not assumed: `pim_file.alias`, `.title` and `.altText`
     * are each `varchar(255) NULL`. The 20 characters of `ThumbnailSetting.alias` are a
     * different column of a different table — that one names a derivative size, this one is the
     * file's own short name.
     *
     * CHECKED HERE RATHER THAN LEFT TO THE DATABASE. The harness runs with
     * `STRICT_TRANS_TABLES`, so an over-long value is an error and not a silent truncation — but
     * it surfaces as an unreadable 500, and a sentence at the field is the better answer than
     * that.
     */
    public const METADATA_MAX_LENGTH = 255;

    /**
     * The field that asks for a machine-readable answer instead of the redirect.
     *
     * AN EXPLICIT FIELD AND NOT `Accept` (ADR 0021). The uploading controller builds its own
     * `FormData`, so it can state what it wants; a header would leave the answer's shape to a
     * browser default, and the gate would have to forge headers to test either branch.
     */
    public const FORMAT_FIELD = '_format';

    /** The one value `FORMAT_FIELD` understands. Anything else is the ordinary redirect. */
    public const FORMAT_JSON = 'json';

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
     * The core's upload route, and the field it reads the file from.
     *
     * Fixed for the same reason as `CORE_FILE_ROUTE`: `file` is a prefix the core mounts itself.
     * The field name is the core's too — `uploadAction` asks `$request->files->get('file')`
     * (`Controller/FileController.php:100`), so the form's input has to be called that and
     * nothing else.
     */
    private const CORE_UPLOAD_ROUTE = '/file/upload';
    private const CORE_UPLOAD_FIELD = 'file';

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

        $files = self::rows($records, $manager->getThumbnailAlias(), $base);

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

        /*
         * THE UPLOAD MODULE IS REGISTERED EXACTLY WHEN THE ZONE IS RENDERED, which is the write
         * permission and nothing else. Registering it for a read-only editor would load a module
         * whose element never exists.
         */
        if ($this->mayWrite(self::ENTITY_FILE)) {
            $controllers['admin-upload'] = 'admin/upload';
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
            'actions'     => array(
                'rename' => self::RENAME,
                'move'   => self::MOVE,
                'delete' => self::DELETE,
                'upload' => self::UPLOAD,
            ),
            'folderField' => self::PARAM_FOLDER,
            /*
             * The file input has to carry the core's own field name, and the `accept` value is
             * whatever the project stated — empty means the template writes no attribute at all
             * (ADR 0021).
             */
            'uploadField'  => self::CORE_UPLOAD_FIELD,
            'uploadAccept' => $manager->getUploadAccept(),
            /*
             * The upload module adds this field to ask for the machine-readable answer. It is
             * deliberately **not** a hidden input: in the markup it would travel with the
             * no-JavaScript submit too, and that path needs the redirect.
             */
            'formatField' => self::FORMAT_FIELD,
            'formatJson'  => self::FORMAT_JSON,
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
     * One file on a page of its own: its facts, its preview and its public address.
     *
     * A SECOND ROUTE ON THE EXISTING MOUNT, never a mount of its own (ADR 0022). The reasoning
     * sits in `AdminManager::mountRoutes()`, where the route is declared.
     *
     * A NON-NUMERIC OR UNKNOWN ID IS A 404, the stance `FormController::load()` takes for the
     * same situation: a hand-typed address must not reach the core's debug page, and an empty
     * page would read like a file that exists and holds nothing.
     *
     * THE FOLDER IS RESOLVED THROUGH THE TREE and not through the record's nested folder object.
     * The tree is already the one place in this controller that knows a folder's title, and it
     * is asked for only when it may be read — without that right the folder line is left out
     * and the rest of the page stays, the additive masking of ADR 0019. The listing above drops
     * its whole column on the same condition.
     */
    public function detailAction(Request $request, string $id): Response
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
         * A POST IS THE METADATA FORM, A GET IS THE PAGE — the split `filesAction()` makes for
         * the same reason: the mount is `match()`, so the method is decided here and not by the
         * router. A GET is never an error on this route.
         */
        if ($request->isMethod('POST')) {
            return $this->saveMetadata($request, $manager, $id);
        }

        return $this->renderDetail($request, $manager, $id);
    }

    /**
     * Saving the three metadata fields of one file (003-003-0004).
     *
     * `/api/update` BY SUB-REQUEST, NOT `/file` (ADR 0022). `alias`, `title` and `altText` are
     * fields of `PIM\File`, and for fields of an entity `/api/update` is the core's write path —
     * the same one renaming, moving and deleting take. `/file` accepts uploads and nothing else.
     *
     * A REFUSAL RE-RENDERS, A SUCCESS REDIRECTS, and the asymmetry is the whole point. The three
     * write paths of the listing answer every outcome with a 303 because each carries one field;
     * here three fields are in play, and a redirect would hand the editor back an empty form with
     * a sentence about what they just lost. So a refusal comes back as the page with the typed
     * values still in it, and only the success leaves with a 303 — which also keeps a reload from
     * repeating the write.
     *
     * NO `_format=json` BRANCH. The two-sided answer of ADR 0021 exists for the upload, where an
     * XHR reports per file; this form is submitted by the browser and has no second caller.
     */
    private function saveMetadata(Request $request, AdminManager $manager, string $id): Response
    {
        $values = array();

        foreach (self::METADATA_FIELDS as $property) {
            $submitted = $request->request->get($property);
            $values[$property] = is_string($submitted) ? trim($submitted) : '';
        }

        if (!FormToken::verify($request)) {
            /*
             * NOT A CORE FINDING, SO IT CARRIES NO FIELD — `FormController::save()` takes the
             * same stance. No single field is wrong here, so the sentence goes above the form.
             */
            return $this->renderDetail($request, $manager, $id, $values, array(),
                'Das Formular ist abgelaufen oder wurde nicht von dieser Seite abgeschickt. Bitte erneut absenden.');
        }

        if (!$this->mayWrite(self::ENTITY_FILE)) {
            /*
             * THE FORM IS NOT RENDERED WITHOUT THIS RIGHT, so getting here means the post did not
             * come from the page. It is still answered as a page and not as a blank 403, the
             * stance `write()` takes: the read permission above already said these files are the
             * editor's business.
             */
            return $this->renderDetail($request, $manager, $id, null, array(),
                'Für diese Änderung fehlt dir die Berechtigung — es wurde nichts geändert.');
        }

        $tooLong = array();

        foreach ($values as $property => $value) {
            /*
             * COUNTED IN CHARACTERS, NOT BYTES. The column counts characters, and `strlen()` on
             * a German title would refuse a value the database would have taken.
             */
            if (mb_strlen($value) > self::METADATA_MAX_LENGTH) {
                $tooLong[$property] = sprintf(
                    'Diese Angabe ist zu lang — höchstens %d Zeichen.',
                    self::METADATA_MAX_LENGTH
                );
            }
        }

        if ($tooLong !== array()) {
            return $this->renderDetail($request, $manager, $id, $values, $tooLong);
        }

        /*
         * AN EMPTY FIELD IS SENT AS THE EMPTY STRING, AND NOT AS `null` — measured, after `null`
         * was written here first.
         *
         * All three columns are nullable, so SQL NULL would be writable in principle. The core
         * does not pass it on: `Classes/Types/StringType::toDatabase()` answers every empty value
         * with `$object->setX('')`, so a cleared field becomes `''` whichever of the two is sent.
         * Sending `null` would therefore claim a distinction the core does not keep, and the next
         * reader of this method would believe it holds.
         *
         * The consequence is worth stating rather than hiding: a project reading these fields
         * through the API sees `''` for "cleared", never NULL. Only the three fields of this form
         * are sent, so `description` keeps whatever it had (ADR 0022).
         */
        $envelope = $this->call($request, '/api/update', array(
            'entity' => self::ENTITY_FILE,
            'id'     => $id,
            'data'   => $values,
        ));

        if (!$envelope['ok']) {
            $property = FieldError::property($envelope['error'], self::METADATA_FIELDS);
            $message  = FieldError::message($envelope['error'], $envelope['readable']);

            /*
             * THE FINDING GOES TO ITS FIELD WHEN THE FIELD IS KNOWN, otherwise above the form —
             * the decision `FormController::reject()` makes, with the same single guess in
             * `FieldError` and the same fallback. The core names no field of its own (gap L19).
             */
            return $property === null
                ? $this->renderDetail($request, $manager, $id, $values, array(), $message)
                : $this->renderDetail($request, $manager, $id, $values, array($property => $message));
        }

        /*
         * 303 TO THIS FILE'S OWN PAGE, with the report in the query — `back()` builds exactly
         * that when it is handed an address and no folder. A reload of the destination is then a
         * GET and cannot write again.
         */
        return self::back($manager->getPrefix().'/files/'.rawurlencode($id), null, array('done' => self::METADATA));
    }

    /**
     * The file's page itself — optionally carrying what a refused save had typed into it.
     *
     * SPLIT OUT OF `detailAction()` SO THERE IS ONE PAGE, not one for reading and one for a
     * rejection. `FormController` learned the same thing: two render paths for the same page
     * drift, and the one that drifts is the rejection path, which is the one nobody looks at.
     *
     * @param array<string,string>|null $values      Submitted values to keep, or `null` for the record's own
     * @param array<string,string>      $fieldErrors Message per property
     */
    private function renderDetail(
        Request $request,
        AdminManager $manager,
        string $id,
        ?array $values = null,
        array $fieldErrors = array(),
        ?string $error = null
    ): Response {
        /*
         * THE ID IS NOT CHECKED FOR A FORMAT, and that is deliberate — measured, after the
         * opposite was written here first.
         *
         * `Base::$id` is mapped with `APPCMS_ID_TYPE`/`APPCMS_ID_STRATEGY`, so the form of an id
         * is the installation's decision: the harness runs on UUIDs, a project on `AUTO` gets
         * integers. A `ctype_digit()` guard would have answered 404 for every real file here,
         * and a UUID pattern would do the same the other way round. `FormController` passes its
         * `{id}` through for the same reason.
         *
         * What is left is the lookup itself: an id that matches no record is `null`, one the
         * editor may not read throws — both become the 404 below.
         */

        $api = new Api($this->app, $request);

        try {
            $record = $api->getSingle(self::ENTITY_FILE, $id);
        } catch (ContentflyException $denied) {
            /*
             * `getSingle()` THROWS FOR A RECORD THE EDITOR MAY NOT READ, and that is caught here
             * rather than turned into a 403: the read permission on the entity was answered
             * above, so what is left is the core's per-record rule (ADR 0019) — for the reader
             * the file is simply not there.
             */
            return new Response('', 404);
        }

        if (!is_object($record)) {
            return new Response('', 404);
        }

        $base = $manager->getPrefix().'/files/';

        $name = self::text($record->name ?? null);
        $type = self::text($record->type ?? null);

        $mayReadFolders = $this->mayRead(self::ENTITY_FOLDER);
        $tree           = $mayReadFolders ? $api->getTree2(self::ENTITY_FOLDER) : null;

        $folderId = self::folderId($record);
        $node     = ($tree !== null && $folderId !== null) ? self::find($tree, $folderId) : null;

        $preview = self::preview($id, $name, $type, $manager->getThumbnailAlias());

        $aliases  = $manager->getThumbnailAliases();
        $variants = self::variants($id, $name, $type, $aliases);

        $controllers = array();

        if ($preview !== null) {
            $controllers['admin-file-preview'] = 'admin/file-preview';
        }

        /*
         * THE VARIANT MODULE IS REGISTERED EXACTLY WHEN THERE IS A LIST, the rule the listing
         * follows for its own two modules: registering it for a page without entries would load
         * a module whose elements never exist.
         */
        if ($variants !== array()) {
            $controllers['admin-file-variant'] = 'admin/file-variant';
        }

        $mayWrite = $this->mayWrite(self::ENTITY_FILE);

        /*
         * THE FORM'S VALUES COME FROM THE SUBMISSION WHEN THERE WAS ONE, otherwise from the
         * record. That is what keeps a refused save from handing back an empty mask — and it is
         * the only reason this method takes `$values` at all.
         */
        $labels = array('alias' => 'Kurzname', 'title' => 'Titel', 'altText' => 'Alternativtext');

        $fields = array();

        foreach (self::METADATA_FIELDS as $property) {
            $fields[] = array(
                'property'  => $property,
                'label'     => $labels[$property],
                'value'     => $values === null ? self::text($record->{$property} ?? null) : ($values[$property] ?? ''),
                'error'     => $fieldErrors[$property] ?? null,
                'maxlength' => self::METADATA_MAX_LENGTH,
            );
        }

        $token = FormToken::issue($request);

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('file.html.twig', array(
            'title'      => $name.' — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'controllers' => $controllers,
            'rootUrl'     => $base,
            'name'        => $name === '' ? self::EMPTY_CELL : $name,
            'type'        => $type === '' ? self::EMPTY_CELL : $type,
            'size'        => self::humanSize($record->size ?? null),
            'created'     => self::moment($record->created ?? null),
            'preview'     => $preview,
            'badge'       => self::badge($type),
            'variants'    => $variants,
            /*
             * THE TWO EMPTY CASES ARE TOLD APART. No aliases configured and a file that cannot
             * have derivatives are different facts, and only here is it known which applies —
             * the template says the fitting sentence or nothing at all.
             */
            'hasAliases'      => $aliases !== array(),
            'isThumbnailable' => in_array($type, self::THUMBNAILABLE, true),
            'original'    => self::original($id, $name),
            /*
             * `null` FOR BOTH MEANS "SAY NOTHING ABOUT THE FOLDER". It is the same value for two
             * different reasons — the file lies outside the folders, or the editor may not read
             * them — and the template says which, because only here is the difference known.
             */
            'folderTitle' => $node === null ? null : (($node['title'] ?? '') === '' ? self::EMPTY_CELL : (string) $node['title']),
            'folderUrl'   => $node === null ? null : self::url($base, (string) $node['id'], 1),
            'mayReadFolders' => $mayReadFolders,
            /*
             * `isRoot` IS ONLY CLAIMED WHEN THE FOLDERS MAY BE READ, and that is measured rather
             * than assumed: without the right, `toValueObject()` leaves the masked join empty, so
             * a file **inside** a folder arrives with no folder at all. Derived from `$folderId`
             * alone the page stated „Ausserhalb der Ordner" for it — an untruth, and the one
             * reading a missing right must never produce.
             *
             * THIS CONDITION CARRIES THE RULE ON ITS OWN. The template asks `isRoot` in the
             * ordinary order and does not re-check the right; the sabotage run of 003-003-0005
             * is why it is that way round — with the check in both places neither could be shown
             * to work, and the data being true is the better of the two to keep.
             */
            'isRoot'         => $mayReadFolders && $folderId === null,
            'mayWrite'    => $mayWrite,
            'fields'      => $fields,
            'error'       => $error,
            'formUrl'     => $base.rawurlencode($id),
            'token'       => $token,
            'tokenField'  => FormToken::FIELD,
            'actionField' => self::ACTION_FIELD,
            'metadata'    => self::METADATA,
            /*
             * THE REPORT IS READ FROM THE QUERY, the way the listing reads it: the successful
             * save left with a 303, so the sentence it wants to show travels in the address and
             * not in a session.
             */
            'report'      => self::report($request),
        ));

        $response = new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));

        /*
         * THE TOKEN'S COOKIE IS SET THE WAY THE LISTING SETS IT. Without it
         * `FormToken::verify()` has nothing to compare the field against, and the metadata form
         * would be refused as expired on a browser that reached this page first.
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

        /*
         * THE ACTION IS READ BEFORE THE TOKEN IS CHECKED, and only because the *shape* of the
         * answer depends on it: a refused upload still has to refuse in the format its caller
         * asked for. Nothing acts on the value before the check — the token gates every write
         * below exactly as before, and `_format` is a form field, never a permission.
         */
        $action = $request->request->get(self::ACTION_FIELD);

        if (!FormToken::verify($request)) {
            return self::answer($request, $base, $from, array('done' => 'expired'));
        }

        /*
         * THE UPLOAD IS HANDLED BEFORE THE `id` CHECK BECAUSE IT HAS NO `id` — it creates a
         * record instead of addressing one. Forwarding one would not be a harmless extra either:
         * with an `id`, the core's `uploadAction` is the overwrite path and *replaces* that file
         * (`Controller/FileController.php:143`, `:157`).
         */
        if ($action === self::UPLOAD) {
            return $this->upload($request, $base, $from);
        }

        $id = $request->request->get('id');

        if (!is_string($id) || $id === '') {
            return self::answer($request, $base, $from, array('done' => 'missing'));
        }

        if ($action === self::DELETE) {
            if (!$this->mayDelete(self::ENTITY_FILE)) {
                return self::answer($request, $base, $from, array('done' => 'forbidden'));
            }

            $envelope = $this->call($request, '/api/delete', array(
                'entity' => self::ENTITY_FILE,
                'id'     => $id,
            ));

            return self::answer($request, $base, $from, self::outcome(self::DELETE, $envelope));
        }

        if ($action === self::RENAME || $action === self::MOVE) {
            if (!$this->mayWrite(self::ENTITY_FILE)) {
                return self::answer($request, $base, $from, array('done' => 'forbidden'));
            }

            if ($action === self::RENAME) {
                $name = $request->request->get('name');

                if (!is_string($name) || trim($name) === '') {
                    return self::answer($request, $base, $from, array('done' => 'name'));
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

            return self::answer($request, $base, $from, self::outcome($action, $envelope));
        }

        return self::answer($request, $base, $from, array('done' => 'unknown'));
    }

    /**
     * Uploading one file into the folder the editor is looking at.
     *
     * THE UPLOADED FILE IS PASSED THROUGH UNTOUCHED, never rebuilt. The core stores it with
     * `move_uploaded_file()` (`Controller/FileController.php:271`, `:331`), which accepts only a
     * genuine PHP upload and fails **silently** for anything else — a freshly constructed
     * `UploadedFile` would look correct here and store nothing.
     *
     * THE TARGET IS THE FOLDER THE EDITOR CAME FROM, and for this one action that is also the
     * return address: the drop zone belongs to the folder currently open, and that is where the
     * editor stays. An absent folder is the root, which the core reaches by being told nothing.
     *
     * NO TYPE CHECK OF ITS OWN. `uploadAccept` is the browser's pre-check (ADR 0021); what is
     * really permitted sits in the core's `FILE_ALLOWED_TYPES` and the extension blocklist of
     * `Classes/File/UploadValidator.php`, and the validator's verdict travels back as the code
     * `outcome()` keeps.
     */
    private function upload(Request $request, string $base, ?string $folder): Response
    {
        if (!$this->mayWrite(self::ENTITY_FILE)) {
            return self::answer($request, $base, $folder, array('done' => 'forbidden'));
        }

        $file = $request->files->get(self::CORE_UPLOAD_FIELD);

        if (!$file instanceof UploadedFile) {
            return self::answer($request, $base, $folder, array('done' => 'missing'));
        }

        $status   = null;
        $envelope = $this->call(
            $request,
            self::CORE_UPLOAD_ROUTE,
            $folder === null ? array() : array(self::PARAM_FOLDER => $folder),
            array(self::CORE_UPLOAD_FIELD => $file),
            $status
        );

        return self::answer($request, $base, $folder, self::outcome(self::UPLOAD, $envelope, $status));
    }

    /**
     * The answer to a write, in the shape the caller asked for.
     *
     * TWO SHAPES, ONE VERDICT (ADR 0021). The redirect is what a plain form post needs, and the
     * drop zone *is* a plain form when the browser runs no JavaScript. An `XMLHttpRequest` cannot
     * use a redirect: it needs the verdict for this one file, and it needs to tell a size problem
     * from a rejected extension so the right row can say the right thing.
     *
     * THE SENTENCE COMES FROM THE SAME TABLE EITHER WAY. The JSON carries the finished text
     * instead of a code to be translated in the browser, so the wording lives in one place and
     * the uploading controller cannot drift from what the page itself would have said.
     *
     * @param array<string,string> $report
     */
    private static function answer(Request $request, string $base, ?string $folder, array $report): Response
    {
        if (!self::wantsJson($request)) {
            return self::back($base, $folder, $report);
        }

        $sentence = self::sentence($report);

        /*
         * 200 EVEN FOR A REFUSAL, exactly as the redirect answers 303 for one: this route handled
         * the request, and what the core made of the write is the payload. The caller reads `ok`
         * and has one place to look instead of two.
         */
        return new Response(
            (string) json_encode(array(
                'ok'   => $sentence !== null && $sentence['ok'],
                'done' => $report['done'] ?? '',
                'code' => $report['code'] ?? '',
                'text' => $sentence === null ? '' : $sentence['text'],
            ), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            200,
            array('Content-Type' => 'application/json; charset=UTF-8')
        );
    }

    /** Whether this submission asked for the machine-readable answer rather than the redirect. */
    private static function wantsJson(Request $request): bool
    {
        return ($request->request->all()[self::FORMAT_FIELD] ?? null) === self::FORMAT_JSON;
    }

    /**
     * One sub-request to the core, with its answer read as an envelope (ADR 0014).
     *
     * THE PAYLOAD GOES IN THE REQUEST BAG and the server parameters are carried over, so the
     * guarded main request's `auth.user` is the one the core checks its own per-record permission
     * against — the same shape the form and the bulk actions use.
     *
     * FILES TRAVEL IN THE FIFTH ARGUMENT, and the instance has to be the main request's own — see
     * `upload()` for why rebuilding one fails without saying so.
     *
     * @param array<string,mixed>        $payload
     * @param array<string,UploadedFile> $files
     * @param int|null                   $status Filled with the core's HTTP status, for the one
     *                                          caller that needs it when there is no envelope
     *
     * @return array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null}
     */
    private function call(Request $request, string $endpoint, array $payload, array $files = array(), ?int &$status = null): array
    {
        $sub = Request::create($endpoint, 'POST', $payload, array(), $files, $request->server->all());

        $response = $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST);
        $status   = $response->getStatusCode();

        return Envelope::read($response->getContent(), $status);
    }

    /**
     * What the report carries after the core has answered.
     *
     * A FAILURE KEEPS THE CORE'S CODE rather than being flattened into "did not work". The code is
     * what distinguishes a name that is already taken from a record somebody else deleted in the
     * meantime, and the editor can act on the difference.
     *
     * WITHOUT AN ENVELOPE THE HTTP STATUS IS WHAT IS LEFT, and for the upload that is the whole
     * point. `/file/upload` does not answer an envelope when it refuses: the validator throws a
     * `ContentflyException`, and the core's handler only builds the envelope for a request whose
     * content type it recognises — `AcceptHeader::fromString()` does not match
     * `multipart/form-data` once the mandatory `boundary` parameter is attached, so with
     * `APP_DEBUG` on an upload refusal comes back as the debug page (gap L26, measured). Reading
     * a code out of that page would be the text-guessing `Schema\Envelope` refuses to do, so the
     * status takes over — 413 and 415 are exactly the two cases that have to stay apart.
     *
     * The other three write paths pass no status and keep their previous behaviour. They reach
     * `/api/*` and share the same gap; widening their reports belongs to a task of its own, not
     * to this branch.
     *
     * @param array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null} $envelope
     *
     * @return array<string,string>
     */
    private static function outcome(string $action, array $envelope, ?int $status = null): array
    {
        if ($envelope['ok']) {
            return array('done' => $action);
        }

        $code = is_array($envelope['error']) ? ($envelope['error']['code'] ?? null) : null;

        if (!is_string($code) || $code === '') {
            $code = !$envelope['readable'] && $status !== null ? 'HTTP '.$status : '';
        }

        return array('done' => 'failed', 'code' => $code);
    }

    /**
     * Back to where the action came from, as a redirect, with the report in the query.
     *
     * 303 AND NOT 302, so a reload of the destination is a GET and cannot repeat the action.
     *
     * THE ADDRESS IS A PARAMETER, not always the listing: handed a file's own page and no folder,
     * this returns the editor there — which is where a saved metadata form belongs.
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

        $code = $request->query->get('code');

        return self::sentence(array(
            'done' => $done,
            'code' => is_string($code) ? $code : '',
        ));
    }

    /**
     * The sentence one report deserves — the page's and the JSON answer's single source.
     *
     * SPLIT OUT OF `report()` SO THERE IS ONE WORDING, not one per output. The uploading
     * controller shows these texts next to its rows; drafting them again in JavaScript would
     * have let the two drift apart, and the drift would only ever show up in the failure cases
     * nobody looks at twice.
     *
     * @param array<string,string> $report
     *
     * @return array{ok: bool, text: string}|null
     */
    private static function sentence(array $report): ?array
    {
        $done = $report['done'] ?? '';

        if ($done === '') {
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
            $code = $report['code'] ?? '';

            return array(
                'ok'   => false,
                'text' => 'Die Aktion ist fehlgeschlagen'
                    .($code !== '' ? ' ('.$code.')' : '').' — es wurde nichts geändert.',
            );
        }

        $erfolge = array(
            self::RENAME   => 'Die Datei ist umbenannt.',
            self::MOVE     => 'Die Datei ist verschoben.',
            self::DELETE   => 'Die Datei ist gelöscht.',
            self::UPLOAD   => 'Die Datei ist hochgeladen.',
            self::METADATA => 'Die Angaben sind gespeichert.',
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
    private static function rows(array $records, string $alias, string $base): array
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
                /*
                 * THE DETAIL ADDRESS IS BUILT HERE, like every other address of this view: a
                 * template that concatenates URLs is a second place that has to know the prefix.
                 * A row without a usable id gets `null` and the template leaves the name
                 * unlinked rather than pointing at the listing itself.
                 */
                'detailUrl' => $id === '' ? null : $base.rawurlencode($id),
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
     * The stored file's own address, whatever its type.
     *
     * UNLIKE `preview()` THIS DOES NOT GATE ON THE TYPE, and the difference is the whole point.
     * A size request for a non-image answers 500 (Schema-Vertrag 8.2), which is why the
     * derivative path asks about the type first — but the plain route serves every stored file
     * (8.1), so the detail page can state the address of a PDF exactly as it does of a JPEG.
     *
     * THE TRAILING SEGMENT IS COSMETIC, as it is for the derivative: the record is found by
     * `{id}` alone, the name only makes the address readable.
     */
    private static function original(string $id, string $name): string
    {
        $tail = $name === '' ? 'datei' : rawurlencode($name);

        return self::CORE_FILE_ROUTE.'/'.rawurlencode($id).'/'.$tail;
    }

    /**
     * One entry per configured alias, or an empty list when there can be none.
     *
     * NOTHING AT ALL FOR A FILE THAT IS NOT ONE OF THE THREE DERIVABLE TYPES, and the gate for
     * that is `preview()` and only `preview()`. The core answers a size request for a PDF with a
     * `FileNotFoundException` and so a 500 (Schema-Vertrag 8.2) — not asking is the whole point,
     * exactly as it is for the listing's thumbnail (ADR 0020).
     *
     * AN EARLIER VERSION REPEATED THE TYPE CHECK HERE, and the sabotage run of 003-003-0005
     * found it out: removing it changed nothing, because `preview()` answers `null` for such a
     * file anyway and the loop below skips that. Two guards for one rule mean neither can be
     * shown to work, so the second one is gone.
     *
     * NO `1x`/`2x` SEGMENT, AND THAT IS MEASURED RATHER THAN CHOSEN FOR TIDINESS. Both default
     * `ThumbnailSetting` rows carry `isResponsive = 0`, so no variant derivative is ever
     * produced for them: measured against the running harness, `s-pim_list/1x/…` **and**
     * `s-pim_list/2x/…` both answer `contentfly_general_filesize_not_found` with a 500. Whether
     * an installation has a responsive row cannot be found out from here (Schema-Vertrag L25), so
     * offering the two addresses would have put two reliably broken entries under every alias.
     * The form without the segment is the one ADR 0020 picked for the preview, for this reason.
     *
     * @param list<string> $aliases
     *
     * @return list<array{alias: string, url: string}>
     */
    private static function variants(string $id, string $name, string $type, array $aliases): array
    {
        $variants = array();

        foreach ($aliases as $alias) {
            $url = self::preview($id, $name, $type, $alias);

            if ($url !== null) {
                $variants[] = array('alias' => $alias, 'url' => $url);
            }
        }

        return $variants;
    }

    /** One value of a record as a plain string, or `''` for anything that is not one. */
    private static function text($value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A point in time as something a person reads.
     *
     * A `datetime` DOES NOT ARRIVE AS A SCALAR. `toValueObject()` hands it over as an array of
     * `LOCAL`, `LOCAL_TIME` and `ISO8601` (`Types/DatetimeType.php`), and printing that in a
     * template is a runtime error in the place hardest to see coming — `ListController::cell()`
     * normalises the same shape at its own edge.
     */
    private static function moment($value): string
    {
        if (is_array($value)) {
            $local = $value['LOCAL'] ?? null;

            return is_scalar($local) ? (string) $local : self::EMPTY_CELL;
        }

        return is_scalar($value) && (string) $value !== '' ? (string) $value : self::EMPTY_CELL;
    }

    /**
     * The id of the folder a record lies in, or `null` when it lies outside the folders.
     *
     * IT TAKES BOTH SHAPES ON PURPOSE. A `ManyToOne` comes back from `toValueObject()` as a
     * nested object, but the nesting stops at `DB_NESTED_LEVELS` and then collapses to the id —
     * reading only one of the two would work until a project raises or lowers that setting.
     */
    private static function folderId(object $record): ?string
    {
        $folder = $record->folder ?? null;

        $id = is_object($folder) ? ($folder->id ?? null) : $folder;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
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
