<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Schema\Envelope;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\FieldError;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\RelationOptions;
use Areanet\ContentflyAdmin\View\RichText;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Areanet\ContentflyAdmin\View\WidgetRegistry;
use Areanet\ContentflyAdmin\View\WidgetResolver;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Language;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * One record of one entity, as a form — new or existing.
 *
 * TWO ROUTES, NOT ONE WITH AN OPTIONAL SEGMENT. The work item asked for
 * `/<prefix>/form/{entity}/{id?}`, and this core cannot express it: `mount()` returns the
 * provider for chaining and never the `RouteEntry`, so there is no `assert()` or default to
 * declare a placeholder optional (`Kernel/Routing/RouteEntry.php`). `{entity}` and
 * `{entity}/{id}` are therefore two registrations pointing at this one action, which is the
 * same shape `AssetController` already uses for its three depths. `$id` simply stays `null` on
 * the shorter one.
 *
 * BOTH ROUTES ARE `match()`, and both answer GET and POST. Routes inside one provider are keyed
 * by their own name (`CustomControllerProvider::post/get/match`), so a `post()` written next to
 * a `get()` on the same path would not add a route — it would silently replace the one already
 * there. The branch therefore sits in `formAction()`, which is also where the login route puts
 * it.
 *
 * THE WHITELIST IS CHECKED HERE, for the reason `ListController` states: `MenuBuilder` decides
 * what is linked, and a boundary that only holds for people who click is none (ADR 0005).
 *
 * THE VALUES COME FROM `Api::getSingle()` AND THE FIELDS FROM `EntityView`. The two are
 * deliberately separate: the schema says which fields exist and how they are edited, the record
 * says what is in them. `formFields()` knows nothing about values and must not — it is the same
 * list for the empty form and the filled one. There is a third source of values, and keeping it
 * interchangeable with the other two is the whole point of passing a plain map: a rejected
 * submission renders the input the editor just typed, not the record on disk.
 *
 * WRITING GOES THROUGH A SUB-REQUEST AND ANSWERS TURBO'S CONTRACT — 303 on success, 422 with
 * the re-rendered form on a rejection. Both are settled in ADR 0014, together with the reason
 * `replace` is not used and why the field assignment of a validation error has to wait for
 * story 002-006-0005. The CSRF token is `Auth\FormToken`, checked before the core is spoken
 * to; ADR 0010 assigned that promise to this story.
 */
final class FormController
{
    use GuardsCoreVersion;
    use GuardsPermissions;
    use ResolvesTree;


    /**
     * The tab that holds every field whose entity states no `tab` (002-006-0003).
     *
     * `Attribute\Admin` originally described such a field as staying "outside the tab
     * structure" — rendered above the bar and always visible. Collected into one tab instead,
     * because on a real entity the untabbed fields are the *majority*: `Harness\Feldtyp` states
     * a tab on three of fifteen, and a bar holding the minority above twelve loose fields reads
     * as a broken layout rather than a deliberate one. The attribute's own wording was corrected
     * with this task.
     */
    private const DEFAULT_TAB = 'Allgemein';

    /**
     * ADR 0013's sixteen widget keys, and whether this package offers an input for each.
     *
     * TWO FACTS IN ONE PLACE, ON PURPOSE. The key set is what a fragment may be named
     * (`templates/widget/<key>.html.twig`, ADR 0016 §1), and the flag is whether the field is
     * written on submit. They belong together because separating them is how a form grows an
     * input whose value the save path ignores, or — far worse — a field that is only displayed
     * and still written. A later task flips one `false` and adds the matching fragment; nothing
     * else has to move.
     *
     * `false` IS NOT A GAP, it is the honest answer while the element does not exist:
     * `readonly` by definition, `entity`/`entitylist`/`radio`/`checkboxgroup` until
     * 002-007-0004, `permissionmatrix` as the secondary case ADR 0013 describes. A bare text box over a relation would be a control whose value
     * this package cannot form correctly; displaying it risks nothing.
     *
     * A KEY THAT IS NOT LISTED AT ALL — a 22nd core type, a project override naming something
     * unknown — is `readonly` too (`fragment()`), which is also what keeps the template name
     * built from this value inside `templates/widget/`.
     *
     * @var array<string,bool>
     */
    private const FRAGMENTS = array(
        'text'             => true,
        'textarea'         => true,
        'number'           => true,
        'boolean'          => true,
        'datetime'         => true,
        'time'             => true,
        'json'             => true,
        'select'           => true,
        'rte'              => true,
        'entity'           => true,
        'entitylist'       => true,
        'radio'            => true,
        'checkboxgroup'    => true,
        'file'             => true,
        'filelist'         => true,
        'permissionmatrix' => false,
        'readonly'         => false,
        /*
         * `search` IS A RENDERING VARIANT, NOT A SEVENTEENTH WIDGET KEY. The four relation keys
         * all collapse onto it above `RelationOptions::LIMIT` target records, because none of
         * them can be drawn as a list there — one file with a search box beats the same `if`
         * written into four fragments (002-007-0007).
         */
        'search'           => true,
    );

    /**
     * The fragments whose options are records of another entity, fetched rather than read.
     *
     * They are the ones `RelationOptions` is asked about, and the ones that can still end up on
     * `readonly` after the asking: an empty target table, a target the editor may not read, or
     * more target records than a list may offer.
     */
    private const RELATION_FRAGMENTS = array('entity', 'entitylist', 'radio', 'checkboxgroup');

    /**
     * The fragments whose value is the id of another record.
     *
     * A DIFFERENT QUESTION FROM `RELATION_FRAGMENTS`, AND THEY STOPPED HAVING THE SAME ANSWER
     * WITH THE IMAGE FIELDS. That list is "whose options have to be fetched before the control
     * can be drawn"; this one is "whose value has to be read out of a relation entry". `file`
     * carries an id exactly like `entity` does, but it has no option list to fetch — its picker
     * asks for entries when it opens (ADR 0023), which is the whole reason it is not an
     * optionless `select`. Answering both questions from one list would mean either fetching
     * options nobody draws, or handing a value object to `datetime()` because it happens to be
     * an array.
     *
     * `search` IS HERE FOR THE SAME REASON, AND IT WAS MISSING FOR THE SAME REASON. It is the
     * variant the four relation keys collapse onto above `RelationOptions::LIMIT` (`FRAGMENTS`),
     * so its value is a relation entry exactly like theirs — but it is not one of them, and
     * reading the id for `RELATION_FRAGMENTS` alone left the single-valued case empty. The list
     * fragments never noticed, because the template reads `selected()` for them and that knows
     * both shapes; `entity` above the limit rendered a writable field with no value, and the
     * next save wrote that emptiness back over the relation (000-000-0002).
     */
    private const ID_FRAGMENTS = array('entity', 'entitylist', 'radio', 'checkboxgroup', 'file', 'search');

    /** The image fragments, which share a picker and the question above. */
    private const IMAGE_FRAGMENTS = array('file', 'filelist');

    /**
     * The fragments that carry several values under one property.
     *
     * THE POSTING SHAPE IS THE FRAGMENT'S OWN, which is why this says "carry" and not `<name>[]`
     * any more. `entitylist` and `checkboxgroup` post `<name>[]`, because a box per option is
     * what they draw. `filelist` posts **one** text field with the ids separated by commas — the
     * same no-JavaScript form the search fragment uses, because its entries are thumbnails and
     * not boxes, and a hidden input per entry would leave nothing to edit when the module does
     * not load. `payload()` reads both shapes.
     */
    private const LIST_FRAGMENTS = array('entitylist', 'checkboxgroup', 'filelist');

    /**
     * Core aliases that are shown and never offered, whatever widget key they carry.
     *
     * BOTH SHARE A KEY WITH A WRITABLE ALIAS, which is why this list exists at all. `onejoin`
     * sits on `entity` next to `join`, and `joinbidirectional` on `entitylist` next to
     * `multijoin` — so the widget key alone cannot answer whether a field may be written.
     *
     * `onejoin` IS AN OWNED SUB-RECORD, NOT A CHOICE. `OnejoinType::toDatabase()` hands the
     * submitted value to `doInsert`/`doUpdate` on the *target* entity: it creates or edits that
     * record. An id picked from a list would not be understood as a reference, it would be read
     * as the sub-record's field data. Offering a select would therefore be wrong, not merely
     * unsupported.
     *
     * `joinbidirectional` IS NOT WRITABLE AT ALL. `JoinBidirectionalType::toDatabase()` is an
     * empty method body — the core silently drops anything sent for it. A control over that
     * would accept input, report success and change nothing, which is the worst of the three
     * possible answers.
     */
    private const SHOWN_ONLY_TYPES = array('onejoin', 'joinbidirectional');

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The form for one record, or an empty one for a new record.
     *
     * The route is registered `isSecure = true`, so the core's guard has run and `auth.user` is
     * set by the time `Api::getSingle()` asks `Permission::isReadable()` about it — without that
     * the call would dereference a null user. It is also the precondition ADR 0014 names for the
     * sub-request that story 002-006-0004 hangs off this same action.
     *
     * @param string      $entity The entity's URL segment, e.g. `PIM-File` (ADR 0011)
     * @param string|null $id     Absent on the create route
     */
    public function formAction(Request $request, string $entity, ?string $id = null): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $name    = MenuBuilder::urlToEntity($entity);

        if (!in_array($name, $manager->getEntities(), true)) {
            return new Response('', 404);
        }

        if (!$this->mayRead($name)) {
            return $this->accessDeniedPage($name);
        }

        $schema = $this->app[SchemaClient::CONTAINER_KEY]->getSchema();

        $isTranslatable = !empty($schema[$name]['settings']['i18n']);
        $language       = $isTranslatable ? self::language($request) : null;

        if ($isTranslatable && $language === null) {
            return self::refusal(
                'Contentfly Admin cannot edit this entity',
                'The entity "'.$name.'" is translatable, but no language is configured. '
                .'Set APP_LANGUAGES in the project configuration.'
            );
        }

        $fields = $this->app[EntityView::CONTAINER_KEY]->formFields($name);

        if (!$request->isMethod('POST') && !$request->isMethod('GET')) {
            return new Response('', 405, array('Allow' => 'GET, POST'));
        }

        /*
         * DECIDED ONCE, FOR BOTH PATHS. A relation field has to be asked about before either
         * path can act: the renderer needs its options, and the save path needs to know whether
         * it may be written at all. The method check sits above this so a HEAD or PUT does not
         * pay for the queries.
         */
        /*
         * THE WRITE RIGHT IS ONE ANSWER OUT OF TWO QUESTIONS (ADR 0019). The entity has to be
         * writable at all, and where it is translatable the language has to be writable too —
         * a group limited to `{"en": "readable"}` may change German and not English.
         */
        $mayWrite = $this->mayWrite($name) && $this->mayEditLanguage($name, $language);

        if (!$mayWrite) {
            /*
             * A POST WITHOUT THE RIGHT IS REFUSED, NOT SILENTLY DROPPED. The submit button is
             * not rendered below, so the usual way here is a form that was open while the right
             * was withdrawn — and that editor needs to be told their input did not land.
             */
            if ($request->isMethod('POST')) {
                return $this->writeDeniedPage($name, $language);
            }

            $fields = self::withoutWriteAccess($fields);
        }

        $prepared = $this->prepare($request, $name, $fields, $id);

        if ($request->isMethod('POST')) {
            return $this->save($request, $name, $id, $language, $fields, $prepared);
        }

        return $this->show($request, $name, $id, $language, $fields, $prepared, $mayWrite);
    }

    /**
     * Every field write-protected, on top of whatever already protected it.
     *
     * IT ONLY EVER ADDS, exactly as the `readonly` attribute does in `EntityView::formFields()`.
     * Overwriting the flag instead of setting it would let a missing permission *lift* the
     * protection that a `virtualjoin`, an unknown type or an `#[Admin(readonly: true)]` put
     * there — a right that is absent must never widen what a field allows (ADR 0015).
     *
     * `fragment()` reads this flag, so the field renders through `widget/readonly.html.twig` and
     * is not submitted at all. That is the masking ADR 0010 asks for instead of a bare rejection.
     *
     * @param list<array<string,mixed>> $fields
     *
     * @return list<array<string,mixed>>
     */
    private static function withoutWriteAccess(array $fields): array
    {
        foreach ($fields as $index => $field) {
            $fields[$index]['widget']['readonly'] = true;
        }

        return $fields;
    }

    /**
     * The form as a GET gets it: the record's values, or an empty form.
     *
     * @param list<array<string,mixed>> $fields
     */
    private function show(
        Request $request,
        string $name,
        ?string $id,
        ?string $language,
        array $fields,
        array $prepared,
        bool $mayWrite
    ): Response {
        $record = null;

        if ($id !== null) {
            $record = $this->load($request, $name, $id, $language);

            if ($record === null) {
                return new Response('', 404);
            }
        }

        return $this->render(
            $request,
            $name,
            $id,
            $fields,
            $prepared,
            $this->presetParent($request, $name, $id) + self::recordValues($fields, $record),
            200,
            null,
            array(),
            $mayWrite
        );
    }

    /**
     * The parent a new node is created under, when the tree asked for one.
     *
     * THE PLACE COMES FROM THE ADDRESS AND IS CHECKED AGAINST THE TREE. `TreeController` links
     * every node's "Unterknoten anlegen" to this form with `?treeParent=<id>` (ADR 0025 §3), and
     * an id that is not in *this editor's* tree prefills nothing. That is one check for three
     * different wrongs — not a record of this entity, not readable under `OWN`/`GROUP`, or
     * `isIntern` — and they must all fail the same way: distinguishing them would say something
     * about records the editor cannot see. It also closes the one way around the rule that a node
     * cannot be created under an invisible parent, namely typing the id into the URL.
     *
     * ONLY FOR A NEW RECORD. On an existing one the record's own parent is the truth, and a
     * query parameter that silently re-parented it on mere opening of the form would be a write
     * nobody asked for.
     *
     * @return array<string,string>
     */
    private function presetParent(Request $request, string $name, ?string $id): array
    {
        if ($id !== null || !$this->app[EntityView::CONTAINER_KEY]->isTree($name)) {
            return array();
        }

        $wanted = $request->query->get(self::PARENT_PROPERTY);

        if (!is_string($wanted) || $wanted === '') {
            return array();
        }

        $node = self::findInTree($this->treeRows($request, $name), $wanted);

        return $node === null ? array() : array(self::PARENT_PROPERTY => $wanted);
    }

    /**
     * One record, or `null` when it is not there or not readable.
     *
     * AN UNKNOWN ID IS A 404, NOT AN EMPTY FORM. `getSingle()` answers `null` for a record that
     * is not there, and rendering the create form instead would invite an editor to retype a
     * record they believe they are correcting — the save would then add a second one. It throws
     * for an entity the schema does not know and for a record the user may not read; both are
     * caught, because a hand-edited URL must not reach the core's debug page.
     *
     * `getSingle()` ANSWERS AN OBJECT, NOT AN ARRAY. With `$returnObject = false` it returns
     * `toValueObject()`, and that is a `stdClass` whose properties are the fields — the same
     * shape `getList()` puts in its `objects`, which is why `ListController` reads its cells
     * with `->`.
     */
    private function load(Request $request, string $name, string $id, ?string $language): ?object
    {
        try {
            $record = (new Api($this->app, $request))->getSingle($name, $id, null, $language);
        } catch (ContentflyException $denied) {
            return null;
        }

        return is_object($record) ? $record : null;
    }

    /**
     * Saving, as ADR 0014 settled it.
     *
     * THE TOKEN IS CHECKED BEFORE ANYTHING ELSE HAPPENS, and before the core is spoken to at
     * all. ADR 0010 assigned the CSRF protection of writing actions to this story, and the
     * order matters for a second reason the login path already learned: a forged post that
     * reached the core would drive its throttle and could lock somebody out of their own admin.
     *
     * `insert` AND `update`, NOT `replace`. ADR 0014 records why: `replaceAction` checks the
     * permissions before the lookup and so demands read *and* write rights, and it decides for
     * itself whether it inserts or updates — a stricter requirement with less control, for a
     * mask that already knows which of the two it is doing.
     *
     * THE CORE IS REACHED BY SUB-REQUEST because `insert` and `update` exist only as actions of
     * `ApiController`; the directly callable `Api::doInsert()`/`doUpdate()` answer with an
     * object or an array and no envelope, and the envelope is what an error message has to be
     * read out of. The data goes into the `request` bag and not into a JSON body: the core's
     * global `before` hooks do not run for a sub-request, so nothing would unpack it.
     *
     * @param list<array<string,mixed>> $fields
     */
    private function save(
        Request $request,
        string $name,
        ?string $id,
        ?string $language,
        array $fields,
        array $prepared
    ): Response {
        if (!FormToken::verify($request)) {
            /*
             * NOT A CORE FINDING, SO IT CARRIES NO FIELD. The token is the admin's own
             * mechanism; `null` for the error entry makes `reject()` show this sentence above
             * the form, which is where it belongs — no single field is wrong here.
             */
            return $this->reject($request, $name, $id, $fields, $prepared, array(
                'ok'       => false,
                'readable' => true,
                'data'     => null,
                'error'    => null,
            ), $language, 'Das Formular ist abgelaufen oder wurde nicht von dieser Seite abgeschickt. Bitte erneut absenden.');
        }

        $payload = array(
            'entity' => $name,
            'data'   => self::payload($fields, $prepared, $request),
        );

        if ($id !== null) {
            $payload['id'] = $id;
        }

        if ($language !== null) {
            /* `Language::fromRequest()` reads the body, which is why this travels as a field. */
            $payload['lang'] = $language;
        }

        $sub = Request::create(
            $id === null ? '/api/insert' : '/api/update',
            'POST',
            $payload,
            array(),
            array(),
            $request->server->all()
        );

        $response = $this->app->handle($sub, HttpKernelInterface::SUB_REQUEST);
        $envelope = Envelope::read($response->getContent(), $response->getStatusCode());

        if (!$envelope['ok']) {
            return $this->reject($request, $name, $id, $fields, $prepared, $envelope, $language);
        }

        /*
         * 303 AND NOT 200, because that is Turbo's contract: it treats a 2xx answer to a form
         * submission as a navigation it cannot apply and would leave the browser on the old
         * page. The redirect goes to the saved record's own form, so an insert lands on the
         * record it just created rather than on an empty mask that invites a second one.
         */
        $saved = $id ?? Envelope::id($envelope['data']);
        $base  = $this->app[AdminManager::CONTAINER_KEY]->getPrefix().'/form/'.MenuBuilder::entityToUrl($name);

        if ($saved === null) {
            /*
             * The core answered success without an id. Nothing is wrong with the record, so this
             * must not look like a failure — but there is no record page to go to either, and
             * the list is the honest destination.
             */
            return new Response('', 303, array(
                'Location' => $this->app[AdminManager::CONTAINER_KEY]->getPrefix().'/list/'.MenuBuilder::entityToUrl($name),
            ));
        }

        return new Response('', 303, array('Location' => $base.'/'.rawurlencode($saved)));
    }

    /**
     * The form again, with the submitted input still in it and the finding where it belongs.
     *
     * 422 IS THE OTHER HALF OF TURBO'S CONTRACT: a rejected submission answers a non-2xx with
     * the re-rendered form, and Turbo then swaps it in place. A 200 here would be discarded and
     * the editor would be left looking at the form they submitted, with no message and no clue.
     *
     * WHERE THE MESSAGE GOES IS DECIDED HERE AND NOWHERE ELSE. `FieldError::property()` reads
     * the `Entity::field` prefix out of the core's free text and is gated on the fields this
     * form offers; on a hit the message sits at that field, otherwise above the form. The
     * message never disappears — that is the whole shape of the fallback ADR 0014 settled on.
     *
     * ONE FINDING AT A TIME, AND THE FORM DOES NOT PRETEND OTHERWISE. The core fills `errors`
     * with exactly one entry (gap L20), so a form with twelve fields needs one save per
     * problem. The template says so in as many words rather than leaving the editor to assume
     * everything else passed.
     *
     * THE RECORD IS LOADED AGAIN ON THIS PATH, and only on it. The display-only fields — a
     * relation, a file, anything `readonly` — are not submitted, so without the record they
     * would come back empty and the rejected form would look as if it had lost data it never
     * carried.
     *
     * @param list<array<string,mixed>>                                              $fields
     * @param array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null} $envelope
     * @param string|null                                                            $override A
     *        message the admin itself produced, used instead of reading the envelope
     */
    private function reject(
        Request $request,
        string $name,
        ?string $id,
        array $fields,
        array $prepared,
        array $envelope,
        ?string $language,
        ?string $override = null
    ): Response {
        $record = $id === null ? null : $this->load($request, $name, $id, $language);
        $values = self::submittedValues($fields, $prepared, $request) + self::recordValues($fields, $record);

        $message  = $override ?? FieldError::message($envelope['error'], $envelope['readable']);
        $property = $override === null
            ? FieldError::property($envelope['error'], array_column($fields, 'property'))
            : null;

        return $this->render(
            $request,
            $name,
            $id,
            $fields,
            $prepared,
            $values,
            422,
            $property === null ? $message : null,
            $property === null ? array() : array($property => $message),
            /*
             * Reached only through `save()`, and `formAction()` refuses a write without the
             * right before it gets there — so the form that reports a validation error is by
             * definition one the editor may write.
             */
            true
        );
    }

    /**
     * The one place the form is turned into a response.
     *
     * @param list<array<string,mixed>> $fields
     * @param array<string,mixed>       $values
     * @param array<string,string>      $fieldErrors Property → message, at most one entry
     */
    private function render(
        Request $request,
        string $name,
        ?string $id,
        array $fields,
        array $prepared,
        array $values,
        int $status,
        ?string $error,
        array $fieldErrors,
        bool $mayWrite
    ): Response {
        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $tabs    = self::tabs($fields, $prepared, $values, $fieldErrors);
        $base    = $manager->getPrefix().'/form/'.MenuBuilder::entityToUrl($name);
        $token   = FormToken::issue($request);

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('form.html.twig', array(
            'title'      => $name.' — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'entity'     => $name,
            'isNew'      => $id === null,
            'listUrl'    => $manager->getPrefix().'/list/'.MenuBuilder::entityToUrl($name),
            'action'     => $id === null ? $base : $base.'/'.rawurlencode($id),
            'tokenField' => FormToken::FIELD,
            'token'      => $token,
            'error'      => $error,
            'mayWrite'   => $mayWrite,
            'tabs'       => $tabs,
            /*
             * The editor is told that one finding is all the core reports (gap L20), so the
             * absence of further messages is not read as "everything else is fine".
             */
            'partial'    => $fieldErrors !== array() || $error !== null,
            /*
             * THE LAYOUT LOADS STIMULUS ONLY WHERE A VIEW ASKS FOR IT, and this view only needs
             * it when there is something to switch between. Deciding that here rather than in
             * the template keeps the template free of logic, the same split the field controls
             * already follow.
             */
            'controllers' => self::controllers($tabs, $prepared, $manager),
        ));

        $response = new Response($html, $status, array('Content-Type' => 'text/html; charset=UTF-8'));

        if (!FormToken::isIssued($request, $token)) {
            $response->headers->setCookie(FormToken::cookie($request, $token, $manager->getPrefix()));
        }

        return $response;
    }

    /**
     * The Stimulus controllers this particular form needs, as identifier → module specifier.
     *
     * THE LAYOUT LOADS STIMULUS ONLY WHERE A VIEW ASKS FOR IT, and asking is per page and not
     * per package: a form with one field group needs no tab controller, and a form without a
     * rich text field needs no editor. Deciding it here rather than in the template keeps the
     * template free of logic, the same split the field fragments already follow.
     *
     * @param list<array<string,mixed>>                        $tabs
     * @param array<string,array{fragment: string, options: mixed}> $prepared
     *
     * @return array<string,string> Stimulus identifier → module specifier or URL
     */
    private static function controllers(array $tabs, array $prepared, AdminManager $manager): array
    {
        $controllers = array();

        if (count($tabs) > 1) {
            $controllers['admin-tabs'] = 'admin/tabs';
        }

        foreach ($prepared as $field) {
            /*
             * A PROJECT WIDGET BRINGS ITS OWN CONTROLLER, AND IT NEEDS NO IMPORT-MAP ENTRY. The
             * layout registers each controller with `await import(<specifier>)` — a dynamic
             * import, which takes a URL just as happily as a bare name. So the address goes in
             * here directly and `Importmap::MODULES` stays the package's own list, with no
             * extension point to maintain.
             *
             * THE IDENTIFIER IS THE WIDGET KEY ITSELF, not `admin-<key>`: the project writes the
             * fragment and therefore the `data-controller` attribute, so the two have to agree on
             * one rule, and `custom-farbwaehler` is the one name already in play (ADR 0027 §2).
             * It also keeps the project out of the package's `admin-*` identifiers.
             */
            if (str_starts_with($field['fragment'], WidgetRegistry::PROJECT_PREFIX)) {
                $controllers[$field['fragment']] = $manager->projectAssetUrl(
                    'controllers/'.$field['fragment'].'_controller.js'
                );
            }

            if ($field['fragment'] === RichText::WIDGET) {
                $controllers['admin-rte'] = 'admin/rte';
            }

            if ($field['fragment'] === 'search') {
                $controllers['admin-search'] = 'admin/search';
            }

            if (in_array($field['fragment'], self::IMAGE_FRAGMENTS, true)) {
                $controllers['admin-file-picker'] = 'admin/file-picker';
            }

            /*
             * THE SORTER HAS TO BE REGISTERED HERE ALTHOUGH ITS ELEMENT IS BUILT IN THE BROWSER.
             * Stimulus connects a controller to a late element happily — but only if the
             * identifier was registered with the application, and this page registers exactly
             * what this method answers. Without this the picker would render a list with handles
             * that do nothing at all; caught by the probe of 003-004-0005, not by reading.
             *
             * ONLY WHERE AN ORDER CAN BE STORED, like the handle itself (gap L12).
             */
            if (($field['sortable'] ?? false) === true) {
                $controllers['admin-sortable'] = 'admin/sortable';
            }
        }

        return $controllers;
    }

    /**
     * The fields this form is allowed to write, as the core's `data` map.
     *
     * A FIELD THAT IS ONLY SHOWN IS NEVER WRITTEN, whatever arrives in the body — `writable()`
     * answers that off the fragment. The list comes from the field definitions and not from the
     * request, so a hand-crafted post cannot add a property the form does not offer, which covers
     * every half at once: a field the entity marks `readonly` or `hide`, a value the core
     * computes, and a relation whose fragment 002-007-0004 has yet to build.
     *
     * AN UNCHECKED BOX SENDS NOTHING AT ALL. A boolean therefore has to be derived from presence
     * rather than read from the body, or unticking one could never be saved.
     *
     * THE EMPTY STRING BECOMES `null`, because that is what clearing a field means to a nullable
     * column. For a column that is not nullable the core rejects it, which is the correct answer
     * and now arrives as a message on the field's own form.
     *
     * @param list<array<string,mixed>> $fields
     *
     * @return array<string,mixed>
     */
    private static function payload(array $fields, array $prepared, Request $request): array
    {
        $submitted = $request->request->all();
        $data      = array();

        foreach ($fields as $field) {
            $property = $field['property'];
            $fragment = $prepared[$property]['fragment'] ?? 'readonly';

            if (!self::writableFragment($fragment)) {
                continue;
            }

            if ($fragment === 'boolean') {
                $data[$property] = array_key_exists($property, $submitted);
                continue;
            }

            /*
             * A LIST THAT LOST ITS LAST ENTRY SENDS NOTHING, exactly like an unticked box, so a
             * list field is derived from presence too. Reading it from the body alone would make
             * emptying a relation impossible: the absent key would be taken for "unchanged" and
             * the last choice could never be removed.
             */
            if ($prepared[$property]['list'] ?? false) {
                $value = $submitted[$property] ?? array();

                /*
                 * A COMMA-SEPARATED STRING IS THE NO-JAVASCRIPT FORM. The search fragment falls
                 * back to one plain text field when its module does not load, and one field
                 * cannot post an array — so it posts the ids separated by commas, and this is
                 * where that becomes a list again.
                 */
                if (is_string($value)) {
                    $value = $value === '' ? array() : explode(',', $value);
                }

                $data[$property] = is_array($value)
                    ? array_values(array_filter(array_map('trim', array_filter($value, 'is_string'))))
                    : array();
                continue;
            }

            if (!array_key_exists($property, $submitted)) {
                continue;
            }

            $value = $submitted[$property];

            /*
             * RICH TEXT IS FILTERED HERE, ON THE WAY IN, AND NOWHERE ELSE. The Stimulus
             * controller is a convenience that one line of `curl` goes around; a guarantee that
             * lives in the browser is not a guarantee. `RichText::clean()` is therefore on the
             * path every submission takes, whether a controller was ever loaded or not.
             */
            if ($fragment === RichText::WIDGET && is_string($value)) {
                $value = RichText::clean($value);
            }

            $data[$property] = $value === '' ? null : $value;
        }

        return $data;
    }

    /**
     * The values a GET renders: the record's, or the schema defaults for a new record.
     *
     * @param list<array<string,mixed>> $fields
     *
     * @return array<string,mixed>
     */
    private static function recordValues(array $fields, ?object $record): array
    {
        $values = array();

        foreach ($fields as $field) {
            $property = $field['property'];

            $values[$property] = $record === null
                ? ($field['widget']['default'] ?? null)
                : ($record->{$property} ?? null);
        }

        return $values;
    }

    /**
     * The values a rejected submission renders — only the fields the form actually offers.
     *
     * The fields that are only shown are left out on purpose, so the record's own values fill
     * them in (`reject()`); they were never submitted and inventing an empty value for them would
     * make the rejected form look as though it had dropped data.
     *
     * @param list<array<string,mixed>> $fields
     *
     * @return array<string,mixed>
     */
    private static function submittedValues(array $fields, array $prepared, Request $request): array
    {
        $submitted = $request->request->all();
        $values    = array();

        foreach ($fields as $field) {
            $property = $field['property'];
            $fragment = $prepared[$property]['fragment'] ?? 'readonly';

            if (!self::writableFragment($fragment)) {
                continue;
            }

            if ($fragment === 'boolean') {
                $values[$property] = array_key_exists($property, $submitted);
                continue;
            }

            if ($prepared[$property]['list'] ?? false) {
                $value = $submitted[$property] ?? array();

                if (is_string($value)) {
                    $value = $value === '' ? array() : explode(',', $value);
                }

                $values[$property] = is_array($value) ? array_values($value) : array();
                continue;
            }

            $values[$property] = $submitted[$property] ?? null;
        }

        return $values;
    }

    /**
     * The language a translatable record is edited in.
     *
     * The same reasoning as `ListController::language()`: `Language::fromRequest()` reads `lang`
     * from the request *body* and answers `null` for a page navigated to with GET, so the query
     * parameter is read instead and an absent one falls back to the first configured language.
     * A language *switch* in the form is not part of this task — it needs the save path to carry
     * the language, which is story 002-006-0004.
     */
    private static function language(Request $request): ?string
    {
        $requested = Language::normalise($request->query->get('lang'));

        if ($requested !== null && Language::isConfigured($requested)) {
            return $requested;
        }

        $configured = Language::configured();

        return $configured[0] ?? null;
    }

    /**
     * The fields grouped into tabs, in the order the form shows them.
     *
     * THE DEFAULT TAB COMES FIRST, then every stated tab in the order its first field appears in
     * the schema. Sorting the tabs by name would reorder a form whenever somebody renames a tab.
     * `position` — the attribute that *is* about order — orders the fields *inside* a tab and is
     * applied before this method sees them (`EntityView::byPosition()`, ADR 0017); it deliberately
     * does not reach the tab order, so nothing here had to change for it.
     *
     * ONE TAB MEANS NO TABS. An entity that states none, which is every entity until a project
     * writes the attribute, comes out as a single group and the template then renders no bar at
     * all — so this task changes nothing for an unshaped entity.
     *
     * @param list<array{property: string, label: string, tab: string|null, widget: array<string,mixed>}> $fields
     * @param array<string,mixed>                                                                                 $values
     * @param array<string,string>      $errors Property → message
     *
     * @return list<array{name: string, index: int, fields: list<array<string,mixed>>}>
     */
    private static function tabs(array $fields, array $prepared, array $values, array $errors): array
    {
        $grouped = array();

        foreach ($fields as $field) {
            $grouped[$field['tab'] ?? self::DEFAULT_TAB][] = $field;
        }

        /*
         * The default group goes first if it exists at all. Written as a two-step merge rather
         * than a sort, because `+` on arrays keeps the right-hand side's order for every other
         * key — which is the schema order the grouping above produced.
         */
        $ordered = array();

        if (isset($grouped[self::DEFAULT_TAB])) {
            $ordered[self::DEFAULT_TAB] = $grouped[self::DEFAULT_TAB];
            unset($grouped[self::DEFAULT_TAB]);
        }

        $tabs  = array();
        $index = 0;

        foreach ($ordered + $grouped as $name => $group) {
            $controls = self::controls($group, $prepared, $values, $errors);

            $tabs[] = array(
                'name'   => (string) $name,
                /* Only has to be unique inside this page — it feeds the `id`/`aria-controls` pair. */
                'index'  => $index++,
                'fields' => $controls,
                /*
                 * A FINDING IN A HIDDEN GROUP HAS TO BRING THAT GROUP FORWARD, or the form
                 * announces a problem about something the editor cannot see. The template
                 * passes this to the tabs controller, which opens the first marked group
                 * instead of the first one.
                 */
                'hasError' => array_filter(array_column($controls, 'error')) !== array(),
            );
        }

        return $tabs;
    }

    /**
     * The fields with everything the template needs to render one row each.
     *
     * THE TEMPLATE DECIDES NOTHING HERE. It walks this list and includes the fragment that
     * `widget` names; which fragment a field gets, whether it is editable at all and what its
     * value prints as are all answered in PHP. That is the same split the list view uses, and for
     * the same reason: a wrong type reaching Twig is a runtime error on the page nobody tested
     * with that field.
     *
     * @param list<array{property: string, label: string, widget: array<string,mixed>}> $fields
     * @param array<string,mixed>                                                               $values
     * @param array<string,string>      $errors Property → message
     *
     * @return list<array<string,mixed>>
     */
    private static function controls(array $fields, array $prepared, array $values, array $errors): array
    {
        $controls = array();

        foreach ($fields as $field) {
            $widget   = $field['widget'];
            $property = $field['property'];
            $raw      = $values[$property] ?? null;
            $fragment = $prepared[$property]['fragment'] ?? 'readonly';

            $controls[] = array(
                'property'  => $property,
                'label'     => $field['label'],
                'widget'    => $fragment,
                'templates' => self::templates($fragment),
                /*
                 * THE CORE ALIAS GOES ALONG because three of them share one widget key:
                 * `number` has to know whether it is looking at an `integer` or at a `decimal`,
                 * or a browser rejects the decimal's own values.
                 */
                'type'      => $widget['type'] ?? null,
                'required'  => ($widget['required'] ?? false) === true,
                'maxlength' => $widget['maxlength'] ?? null,
                'options'   => $prepared[$property]['options'] ?? array(),
                'value'     => self::value($raw, $fragment),
                /* Only the list fragments read this; for every other one the template ignores it. */
                'values'    => self::selected($raw, $widget['through'] ?? null),
                'list'      => $prepared[$property]['list'] ?? false,
                'searchUrl' => $prepared[$property]['search'] ?? null,
                /* Only the image fragments read this; for every other one the template ignores it. */
                'pickerUrl' => $prepared[$property]['picker'] ?? null,
                'alias'     => $prepared[$property]['alias'] ?? null,
                /*
                 * READ OFF THE WIDGET AND NOT THE FRAGMENT, because this is a fact about the
                 * field's storage: only one of the two `multifile` forms can hold an order
                 * (gap L12, `WidgetResolver`). A field whose fragment is not `filelist` has no
                 * such key, and `false` is then the honest answer.
                 */
                'sortable'  => ($widget['sortable'] ?? null) === true,
                'checked'   => self::checked($raw),
                'error'     => $errors[$property] ?? null,
            );
        }

        return $controls;
    }

    /**
     * Which fragment under `templates/widget/` renders one field.
     *
     * `readonly` IS AN ANSWER AND NOT AN ERROR CASE — it is one of ADR 0013's sixteen keys and
     * the fallback for the other three ways a field can end up shown instead of offered: the
     * entity marks it `readonly`, the core computes it, or its key is unknown here.
     *
     * @param array<string,mixed> $widget
     */
    private static function fragment(array $widget): string
    {
        if (($widget['readonly'] ?? false) === true) {
            return 'readonly';
        }

        if (in_array($widget['type'] ?? null, self::SHOWN_ONLY_TYPES, true)) {
            return 'readonly';
        }

        $key = (string) ($widget['widget'] ?? '');

        /*
         * A PROJECT KEY PASSES, AND IT HAS TO PASS HERE rather than by being added to the
         * allowlist below: the package cannot know the names, so the prefix is the only thing it
         * can recognise (ADR 0027 §3). Until 005-002 this branch did not exist, which is where
         * the whole seam ended in practice — the registration worked, the resolver answered with
         * the project's key, and the form still rendered a plain value, because every unknown key
         * fell through to `readonly` one line down. The checks above stay in front of this on
         * purpose: `readonly` from the attribute or from a computed value is write protection,
         * and a registered widget does not argue with it.
         */
        if (str_starts_with($key, WidgetRegistry::PROJECT_PREFIX)) {
            return $key;
        }

        if (!isset(self::FRAGMENTS[$key])) {
            return 'readonly';
        }

        /* `select` without options would be an empty dropdown — worse than showing the value. */
        if ($key === 'select' && self::options($widget) === array()) {
            return 'readonly';
        }

        return $key;
    }

    /**
     * The template candidates for one fragment, most specific first.
     *
     * TWO NAMESPACES, AND THE DIFFERENCE IS THE FALLBACK. A package key keeps the second
     * candidate `widget/readonly.html.twig`, because a package key without a fragment is a
     * DECIDED outcome and not an accident — `permissionmatrix` is exactly that and renders as a
     * plain value (ADR 0016 §2). A project key gets no second candidate: a registered widget
     * whose fragment is missing is a mistake in the project, and Twig naming the template it
     * looked for is the message. The same trade as `strict_variables` in `TemplateRenderer` —
     * the loud failure is the cheaper one, and the silent one looks like a working page.
     *
     * Deciding it here keeps `form.html.twig` free of the naming rule, the same split the
     * fragments and the controller map already follow.
     *
     * @return list<string>
     */
    private static function templates(string $fragment): array
    {
        if (str_starts_with($fragment, WidgetRegistry::PROJECT_PREFIX)) {
            return array('@'.TemplateRenderer::PROJECT_NAMESPACE.'/'.$fragment.'.html.twig');
        }

        return array('widget/'.$fragment.'.html.twig', 'widget/readonly.html.twig');
    }

    /**
     * Whether a field rendered with this fragment is written when the form is submitted.
     *
     * READ OFF THE FRAGMENT, NOT THE WIDGET KEY, so the two can never disagree: whatever reason
     * sent a field to `readonly` — the attribute, a computed value, an optionless select, an
     * alias the core never writes, a target table too large to offer — also takes it out of the
     * save path. That is why the fragment is decided once per request in `prepare()` and handed
     * to the renderer and to the save path alike, instead of being worked out twice from
     * different information.
     */
    private static function writableFragment(string $fragment): bool
    {
        /*
         * A PROJECT FRAGMENT IS WRITTEN. It stands where the package's input element stood, for a
         * field the resolver called editable — answering `false` here would render the control,
         * take the editor's input and drop it on save. That is the shape of the data loss found
         * in the `search` fragment (task 000-000-0002), and it is worth not building a second
         * time.
         */
        if (str_starts_with($fragment, WidgetRegistry::PROJECT_PREFIX)) {
            return true;
        }

        return self::FRAGMENTS[$fragment] ?? false;
    }

    /**
     * The fragment and the options of every field, decided once per request.
     *
     * THE ONE PLACE THAT ASKS ABOUT RELATIONS. A relation field needs a query to know whether it
     * can be offered at all, and the answer has to reach two different readers: the renderer,
     * which draws the control, and the save path, which decides what may be written. Working it
     * out separately in both is how a form grows a field that is displayed and still saved.
     *
     * @param list<array<string,mixed>> $fields
     *
     * @return array<string,array{fragment: string, options: list<array{value: string, label: string}>}>
     */
    private function prepare(Request $request, string $name, array $fields, ?string $id = null): array
    {
        $provider = $this->app[RelationOptions::CONTAINER_KEY];

        /*
         * THE SUBTREE A NODE MAY NOT BE MOVED INTO, read once for this request and empty for
         * everything that is not a tree being edited. The measurement behind it (gap L31): the
         * core accepts a cycle silently, writes it, and `Api::getTree2()` then answers an empty
         * tree — the whole tree disappears from the admin with no error anywhere. One choice in
         * one dropdown is enough, so the choice must not be offered.
         */
        $forbidden = array();

        if ($id !== null && $this->app[EntityView::CONTAINER_KEY]->isTree($name)) {
            $node = self::findInTree($this->treeRows($request, $name), $id);

            if ($node !== null) {
                $forbidden = self::subtreeIds($node);
            }
        }

        /*
         * THE ADDRESS IS BUILT HERE AND NOT IN THE TEMPLATE, like every other address of this
         * package: the prefix belongs to the project and `MenuBuilder` owns the spelling of an
         * entity in a URL (ADR 0011).
         */
        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $prefix  = $manager->getPrefix();
        $options = $prefix.'/options/'.MenuBuilder::entityToUrl($name);

        /*
         * THE PICKER'S ADDRESS IS THE SAME FOR EVERY IMAGE FIELD — it carries no entity and no
         * property, because the files it offers do not depend on either (ADR 0023). The trailing
         * slash belongs to it: the route is `mount($prefix.'/filepicker')` plus `get('')`, which
         * is `…/filepicker/`, and without it the router answers 500 like every sibling mount.
         */
        $picker = $prefix.'/filepicker/';

        $prepared = array();

        foreach ($fields as $field) {
            $widget   = $field['widget'];
            $fragment = self::fragment($widget);
            $choices  = self::options($widget);
            $search   = null;

            /*
             * THE LIST-NESS IS DECIDED BEFORE THE FRAGMENT CAN CHANGE. A field that posts
             * several values keeps doing so when it turns into a search box, and the save path
             * reads this flag rather than the fragment name — otherwise the switch below would
             * quietly turn a multi-value field into a single-value one.
             */
            $list = in_array($fragment, self::LIST_FRAGMENTS, true);

            /*
             * A LIST THE EDITOR CANNOT READ MUST NOT BE WRITABLE, and that is not caution but a
             * measured consequence. `Types/MultifileType::fromDatabase()` answers `null` — not a
             * list of blocked entries the way `FileType` does — as soon as `PIM\File` is
             * unreadable, so the form would render an empty list; and its `toDatabase()` answers
             * an empty value by clearing the collection. Writable, this field would therefore
             * delete every image a record has the moment somebody without the file right saves
             * the form, having never seen them. `readonly` takes it out of the save path entirely
             * (`writableFragment()`), which is what ADR 0019 asks for: a missing right takes an
             * answer away, it never produces a different one — and it certainly never destroys.
             *
             * THE SINGLE `file` FIELD DOES NOT NEED THIS. There the core answers
             * `['id' => …, 'pim_blocked' => true]`, so the id travels through the form and is
             * written back unchanged (measured in 003-004-0003).
             */
            if ($fragment === 'filelist' && !$this->mayRead(WidgetResolver::FILE_ENTITY)) {
                $fragment = 'readonly';
            }

            if (in_array($fragment, self::RELATION_FRAGMENTS, true)) {
                $relation = $provider->forField($widget, $request);

                if ($relation === null) {
                    /* No target, no such entity, nothing in it, or not readable — all the same. */
                    $fragment = 'readonly';
                } elseif ($relation['total'] > RelationOptions::LIMIT) {
                    /*
                     * MORE TARGET RECORDS THAN A LIST MAY OFFER IS SEARCHED, NOT TRUNCATED. A
                     * list cut off at the limit would hide records without saying so, and an
                     * editor would conclude the missing one does not exist (ADR 0016 §2).
                     */
                    $fragment = 'search';
                    $choices  = array();
                    $search   = $options.'/'.rawurlencode($field['property']);
                } else {
                    $choices = $relation['options'];
                }

                /*
                 * THE NODE ITSELF AND ITS OWN SUBTREE ARE TAKEN OUT OF THE PARENT CHOICES. The
                 * narrowing to the own entity happens one layer down, in
                 * `WidgetResolver::target()` — what cannot be decided there is which *record* is
                 * being edited, and that is exactly what a cycle depends on.
                 *
                 * `isIntern` NEEDS NO FILTER HERE: the options come from `Api::getList()`, which
                 * hard-wires `isIntern = false` (gap L25), so an internal node is never offered
                 * as a parent in the first place. Measured in 004-001-0001.
                 */
                if ($forbidden !== array() && $field['property'] === self::PARENT_PROPERTY) {
                    $choices = array_values(array_filter(
                        $choices,
                        static function (array $option) use ($forbidden): bool {
                            return !in_array((string) ($option['value'] ?? ''), $forbidden, true);
                        }
                    ));

                    /*
                     * AND THE SEARCH ROUTE HEARS ABOUT IT TOO. Above `RelationOptions::LIMIT`
                     * the choices are not rendered at all and the editor searches instead — a
                     * large tree is precisely where a cycle is most likely, so the exclusion
                     * must travel with the address rather than stop at the inline list.
                     */
                    if ($search !== null) {
                        $search .= '?self='.rawurlencode($id);
                    }
                }
            }

            $prepared[$field['property']] = array(
                'fragment' => $fragment,
                'options'  => $choices,
                'list'     => $list,
                'search'   => $search,
                'picker'   => in_array($fragment, self::IMAGE_FRAGMENTS, true) ? $picker : null,
                /*
                 * THE ALIAS IS CONFIGURATION AND COSTS NOTHING TO PASS, which is the whole
                 * reason the chosen image can show a small version at all: the form does not
                 * look the file up (one query per image field per render), so it cannot know the
                 * file's type and cannot decide whether a derivative exists. The browser tries
                 * the small version and falls back to the stored file — the same division of
                 * labour ADR 0020 chose for the listing, for the same unanswerable question
                 * (Schema-Vertrag L25).
                 */
                'alias'    => in_array($fragment, self::IMAGE_FRAGMENTS, true)
                    ? $manager->getThumbnailAlias()
                    : null,
                'sortable' => $fragment === 'filelist' && ($widget['sortable'] ?? null) === true,
            );
        }

        return $prepared;
    }

    /**
     * A `select`'s options as `[{value, label}]`, empty for every other widget.
     *
     * The resolver passes `options` through as the core's `[{id, name}]` (ADR 0013); renaming the
     * two keys here keeps the template from knowing the core's spelling.
     *
     * @param array<string,mixed> $widget
     *
     * @return list<array{value: string, label: string}>
     */
    private static function options(array $widget): array
    {
        $options = $widget['options'] ?? null;

        if (!is_array($options)) {
            return array();
        }

        $mapped = array();

        foreach ($options as $option) {
            if (!is_array($option) || !isset($option['id'])) {
                continue;
            }

            $label = $option['name'] ?? $option['id'];

            $mapped[] = array(
                'value' => (string) $option['id'],
                'label' => is_scalar($label) ? (string) $label : (string) $option['id'],
            );
        }

        return $mapped;
    }

    /**
     * One value as the form prints it.
     *
     * `toValueObject()` DOES NOT ANSWER SCALARS THROUGHOUT — a `datetime` arrives as an array of
     * `LOCAL`, `LOCAL_TIME`, `ISO8601` and `TIMESTAMP` (`Types/DatetimeType.php`), and which of
     * them is right depends on where the value is going. `datetime()` answers that; the fragment
     * is passed in for no other reason.
     *
     * ANYTHING THIS CANNOT FORM BECOMES THE EMPTY STRING, never the placeholder dash. The dash
     * belongs to the display case and the template puts it there; reaching an input it would be
     * a value an editor could save by accident.
     *
     * @param mixed $value
     */
    private static function value($value, string $fragment): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        /*
         * A RELATION ARRIVES AS A VALUE OBJECT, A DATETIME AS AN ARRAY, and the fragment decides
         * which of the two is being looked at — the keys alone would be a guess.
         *
         * THE RELATION IS AN OBJECT ON ONE PATH AND AN ARRAY ON THE OTHER. `JoinType` answers
         * the target's own value object, a `stdClass`, when the editor may read it, and the bare
         * array `['id' => …, 'pim_blocked' => true]` when they may not. Both have to work, or a
         * relation the editor cannot read would lose its selection on save.
         */
        if (in_array($fragment, self::ID_FRAGMENTS, true)) {
            return self::relationId($value) ?? '';
        }

        if (is_array($value)) {
            return self::datetime($value, $fragment);
        }

        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * The id out of one relation entry, or `null` when there is none to read.
     *
     * @param mixed $entry
     */
    private static function relationId($entry): ?string
    {
        if (is_object($entry)) {
            $entry = $entry->id ?? null;
        } elseif (is_array($entry)) {
            $entry = $entry['id'] ?? null;
        }

        return is_scalar($entry) && (string) $entry !== '' ? (string) $entry : null;
    }

    /**
     * The ids a list fragment has ticked.
     *
     * TWO SHAPES REACH THIS, and both have to work. A record read from the core answers a list of
     * `['id' => …]` entries; a rejected submission answers the plain list of ids that was posted
     * (`submittedValues()`), because that is what the browser sent. Handling only the first would
     * make a rejected form come back with every choice cleared — the editor would lose the
     * selection along with the error message.
     *
     * @param mixed $value
     *
     * @return list<string>
     */
    private static function selected($value, ?string $through = null): array
    {
        if (!is_array($value)) {
            return array();
        }

        if ($through !== null) {
            $value = self::ordered($value);
        }

        $ids = array();

        foreach ($value as $entry) {
            /*
             * THE IN-BETWEEN RECORD IS STEPPED THROUGH WHERE THERE IS ONE. A sortable
             * `multifile` answers with `BaseSortable` rows whose `id` belongs to the row and not
             * to the file (`WidgetResolver`, key `through`) — so the file is read from that
             * property first. The fallback is not a safety net but the second shape this method
             * has always had to serve: a rejected submission answers plain ids, and those have no
             * property to step through.
             */
            $id = $through === null ? null : self::relationId(self::property($entry, $through));

            if ($id === null) {
                $id = self::relationId($entry);
            }

            if ($id !== null) {
                $ids[] = $id;
            }
        }

        return $ids;
    }

    /**
     * One property out of a value object or an array, or `null`.
     *
     * @param mixed $entry
     *
     * @return mixed
     */
    private static function property($entry, string $name)
    {
        if (is_object($entry)) {
            return $entry->{$name} ?? null;
        }

        return is_array($entry) ? ($entry[$name] ?? null) : null;
    }

    /**
     * The entries of a sortable list in their stored order.
     *
     * THE CORE DOES NOT ORDER THEM, measured against the running harness: rows written with
     * `sorting` 10, 9 and 8 come back 10, 8, 9. `MultifileType::fromDatabase()` walks the
     * collection as Doctrine hands it over, and nothing in that path mentions `sorting` — so the
     * sequence an editor dragged into place would be shown in an arbitrary order on the next
     * open, and saved in that arbitrary order afterwards.
     *
     * AN ENTRY WITHOUT `sorting` KEEPS ITS PLACE. The sort is stable and treats a missing value
     * as "after everything that has one", so a shape this method does not know cannot be
     * reordered into nonsense — it only cannot be sorted.
     *
     * @param list<mixed> $entries
     *
     * @return list<mixed>
     */
    private static function ordered(array $entries): array
    {
        $withSorting = array();
        $without     = array();

        foreach ($entries as $entry) {
            $sorting = self::property($entry, 'sorting');

            if (is_numeric($sorting)) {
                $withSorting[] = array('sorting' => (float) $sorting, 'entry' => $entry);
                continue;
            }

            $without[] = $entry;
        }

        /* `usort` is not stable before PHP 8.0; this package requires 8.3, so it is. */
        usort($withSorting, static function (array $a, array $b): int {
            return $a['sorting'] <=> $b['sorting'];
        });

        return array_merge(array_column($withSorting, 'entry'), $without);
    }

    /**
     * A `datetime` in the form the destination needs — and the two forms are not the same one.
     *
     * `LOCAL` IS `d.m.Y`, WHICH IS NEITHER. That is the finding this method exists for: the core
     * answers `LOCAL` as a German date *without a time* (`Types/DatetimeType.php:52`), and the
     * previous version of this code put it straight into the `value` of a `datetime-local`
     * input. Such a value is invalid per the HTML specification, so the browser rendered the
     * field EMPTY — and saving that form wrote the empty value back. The date was deleted by
     * opening and saving a record, which is as quiet as data loss gets. Nobody saw it because
     * `created` and `modified` are the only `datetime` fields on an inherited entity and both
     * are filtered out of the form; the harness had no editable one until 002-007-0003 added it.
     *
     * AN INPUT THEREFORE GETS `ISO8601`, CUT TO THE MINUTE. `datetime-local` wants
     * `YYYY-MM-DDTHH:MM`, and ISO 8601 is fixed width up to exactly that point, so the first 16
     * characters are it — no parsing, no locale, no second format to keep in step. Writing back
     * is already safe: the core hands the submitted string to `new \DateTime()`, which reads
     * that form natively.
     *
     * A DISPLAY GETS THE GERMAN FORM, and `LOCAL_TIME` rather than `LOCAL`, because a record
     * carries a time and dropping it from the display makes two different timestamps look
     * identical.
     *
     * @param array<string,mixed> $value
     */
    private static function datetime(array $value, string $fragment): string
    {
        if ($fragment === 'datetime') {
            $iso = $value['ISO8601'] ?? null;

            return is_string($iso) && strlen($iso) >= 16 ? substr($iso, 0, 16) : '';
        }

        $human = $value['LOCAL_TIME'] ?? $value['LOCAL'] ?? null;

        return is_scalar($human) ? (string) $human : '';
    }

    /**
     * Whether a checkbox is ticked.
     *
     * Only `boolean` uses this; for everything else the template ignores it.
     *
     * @param mixed $value
     */
    private static function checked($value): bool
    {
        return $value === true || $value === 1 || $value === '1';
    }
}
