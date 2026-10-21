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
use Areanet\ContentflyAdmin\View\TemplateRenderer;
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
     * 002-007-0004, `file`/`filelist` until story 003-004, `permissionmatrix` as the secondary
     * case ADR 0013 describes. A bare text box over a relation would be a control whose value
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
        'entity'           => false,
        'entitylist'       => false,
        'radio'            => false,
        'checkboxgroup'    => false,
        'file'             => false,
        'filelist'         => false,
        'permissionmatrix' => false,
        'readonly'         => false,
    );

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

        if ($request->isMethod('POST')) {
            return $this->save($request, $name, $id, $language, $fields);
        }

        if (!$request->isMethod('GET')) {
            return new Response('', 405, array('Allow' => 'GET, POST'));
        }

        return $this->show($request, $name, $id, $language, $fields);
    }

    /**
     * The form as a GET gets it: the record's values, or an empty form.
     *
     * @param list<array<string,mixed>> $fields
     */
    private function show(Request $request, string $name, ?string $id, ?string $language, array $fields): Response
    {
        $record = null;

        if ($id !== null) {
            $record = $this->load($request, $name, $id, $language);

            if ($record === null) {
                return new Response('', 404);
            }
        }

        return $this->render($request, $name, $id, $fields, self::recordValues($fields, $record), 200, null);
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
    private function save(Request $request, string $name, ?string $id, ?string $language, array $fields): Response
    {
        if (!FormToken::verify($request)) {
            /*
             * NOT A CORE FINDING, SO IT CARRIES NO FIELD. The token is the admin's own
             * mechanism; `null` for the error entry makes `reject()` show this sentence above
             * the form, which is where it belongs — no single field is wrong here.
             */
            return $this->reject($request, $name, $id, $fields, array(
                'ok'       => false,
                'readable' => true,
                'data'     => null,
                'error'    => null,
            ), $language, 'Das Formular ist abgelaufen oder wurde nicht von dieser Seite abgeschickt. Bitte erneut absenden.');
        }

        $payload = array(
            'entity' => $name,
            'data'   => self::payload($fields, $request),
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
            return $this->reject($request, $name, $id, $fields, $envelope, $language);
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
        array $envelope,
        ?string $language,
        ?string $override = null
    ): Response {
        $record = $id === null ? null : $this->load($request, $name, $id, $language);
        $values = self::submittedValues($fields, $request) + self::recordValues($fields, $record);

        $message  = $override ?? FieldError::message($envelope['error'], $envelope['readable']);
        $property = $override === null
            ? FieldError::property($envelope['error'], array_column($fields, 'property'))
            : null;

        return $this->render(
            $request,
            $name,
            $id,
            $fields,
            $values,
            422,
            $property === null ? $message : null,
            $property === null ? array() : array($property => $message)
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
        array $values,
        int $status,
        ?string $error,
        array $fieldErrors = array()
    ): Response {
        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $tabs    = self::tabs($fields, $values, $fieldErrors);
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
            'controllers' => count($tabs) > 1 ? array('admin-tabs' => 'admin/tabs') : array(),
        ));

        $response = new Response($html, $status, array('Content-Type' => 'text/html; charset=UTF-8'));

        if (!FormToken::isIssued($request, $token)) {
            $response->headers->setCookie(FormToken::cookie($request, $token, $manager->getPrefix()));
        }

        return $response;
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
    private static function payload(array $fields, Request $request): array
    {
        $submitted = $request->request->all();
        $data      = array();

        foreach ($fields as $field) {
            if (!self::writable($field['widget'])) {
                continue;
            }

            $property = $field['property'];

            if (($field['widget']['widget'] ?? null) === 'boolean') {
                $data[$property] = array_key_exists($property, $submitted);
                continue;
            }

            if (!array_key_exists($property, $submitted)) {
                continue;
            }

            $value = $submitted[$property];

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
    private static function submittedValues(array $fields, Request $request): array
    {
        $submitted = $request->request->all();
        $values    = array();

        foreach ($fields as $field) {
            if (!self::writable($field['widget'])) {
                continue;
            }

            $property = $field['property'];

            $values[$property] = ($field['widget']['widget'] ?? null) === 'boolean'
                ? array_key_exists($property, $submitted)
                : ($submitted[$property] ?? null);
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
     * the schema. Sorting the tabs by name would reorder a form whenever somebody renames a tab,
     * and `position` — the attribute that *is* about order — belongs to story 002-008.
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
    private static function tabs(array $fields, array $values, array $errors): array
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
            $controls = self::controls($group, $values, $errors);

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
    private static function controls(array $fields, array $values, array $errors): array
    {
        $controls = array();

        foreach ($fields as $field) {
            $widget = $field['widget'];
            $raw    = $values[$field['property']] ?? null;

            $controls[] = array(
                'property'  => $field['property'],
                'label'     => $field['label'],
                'widget'    => self::fragment($widget),
                /*
                 * THE CORE ALIAS GOES ALONG because three of them share one widget key:
                 * `number` has to know whether it is looking at an `integer` or at a `decimal`,
                 * or a browser rejects the decimal's own values.
                 */
                'type'      => $widget['type'] ?? null,
                'required'  => ($widget['required'] ?? false) === true,
                'maxlength' => $widget['maxlength'] ?? null,
                'options'   => self::options($widget),
                'value'     => self::value($raw),
                'checked'   => self::checked($raw),
                'error'     => $errors[$field['property']] ?? null,
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

        $key = (string) ($widget['widget'] ?? '');

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
     * Whether one field is written when the form is submitted.
     *
     * READ OFF THE FRAGMENT, NOT THE WIDGET KEY, so the two can never disagree: whatever reason
     * sent a field to `readonly` — the attribute, a computed value, an optionless select, an
     * unknown key — also takes it out of the save path. `payload()` and `submittedValues()` are
     * the two callers, and they are the reason this is one function and not a condition written
     * twice.
     *
     * @param array<string,mixed> $widget
     */
    private static function writable(array $widget): bool
    {
        return self::FRAGMENTS[self::fragment($widget)];
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
     * `LOCAL`, `LOCAL_TIME` and `ISO8601` (`Types/DatetimeType.php`). `LOCAL` is taken because it
     * is the form the browser's `datetime-local` control expects, and the same reasoning applies
     * to the list's cells.
     *
     * ANYTHING THIS CANNOT FORM BECOMES THE EMPTY STRING, never the placeholder dash. The dash
     * belongs to the display case and the template puts it there; reaching an input it would be
     * a value an editor could save by accident.
     *
     * @param mixed $value
     */
    private static function value($value): string
    {
        if ($value === null || $value === '') {
            return '';
        }

        if (is_bool($value)) {
            return $value ? '1' : '0';
        }

        if (is_array($value)) {
            $local = $value['LOCAL'] ?? null;

            return is_scalar($local) ? (string) $local : '';
        }

        return is_scalar($value) ? (string) $value : '';
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
