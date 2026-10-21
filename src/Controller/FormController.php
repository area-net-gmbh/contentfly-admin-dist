<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Language;
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
 * BOTH ROUTES ARE `match()`, although this task only answers GET. Routes inside one provider
 * are keyed by their own name (`CustomControllerProvider::post/get/match`), so a `post()` added
 * next to a `get()` on the same path would not add a route — it would silently replace the one
 * already there. Registering `match()` now means story 002-006-0004 adds the POST branch inside
 * this action instead of touching the mount, where the mistake would be invisible.
 *
 * THE WHITELIST IS CHECKED HERE, for the reason `ListController` states: `MenuBuilder` decides
 * what is linked, and a boundary that only holds for people who click is none (ADR 0005).
 *
 * THE VALUES COME FROM `Api::getSingle()` AND THE FIELDS FROM `EntityView`. The two are
 * deliberately separate: the schema says which fields exist and how they are edited, the record
 * says what is in them. `formFields()` knows nothing about values and must not — it is the same
 * list for the empty form and the filled one.
 */
final class FormController
{
    use GuardsCoreVersion;


    /**
     * Which HTML control a widget key gets in stage 1.
     *
     * THE ONE PLACE THIS DECISION LIVES, and the one story 002-007 replaces. The widget keys are
     * ADR 0013's sixteen; what they map to here is a small set of plain controls, because this
     * task renders a usable form and not the final element for each of the 21 field types.
     *
     * A KEY THAT IS ABSENT IS SHOWN, NOT OFFERED — see `control()`. That covers `entity`,
     * `entitylist`, `file`, `filelist`, `radio`, `checkboxgroup` and `permissionmatrix`, and it
     * is the same reasoning ADR 0013 used for its `readonly` fallback: those widgets need the
     * records or files they point at, and offering a bare text box for a relation would let
     * story 002-006-0004 write a value this package cannot yet form correctly. Displaying the
     * value risks nothing and is the honest answer until 002-007 builds them.
     */
    private const CONTROLS = array(
        'text'     => 'text',
        'number'   => 'number',
        'datetime' => 'datetime-local',
        'time'     => 'time',
        'textarea' => 'textarea',
        'json'     => 'textarea',
        'boolean'  => 'checkbox',
        'select'   => 'select',
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

        /*
         * WRITING IS STORY 002-006-0004. The route accepts POST so that story does not have to
         * re-register it (see the class note); until then an attempt is refused rather than
         * answered with the unchanged form, which would look like a save that worked.
         */
        if (!$request->isMethod('GET')) {
            return new Response('', 405, array('Allow' => 'GET'));
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

        $record = null;

        if ($id !== null) {
            /*
             * AN UNKNOWN ID IS A 404, NOT AN EMPTY FORM. `getSingle()` answers `null` for a
             * record that is not there (`Api.php`), and rendering the create form instead would
             * invite an editor to retype a record they believe they are correcting — the save
             * would then add a second one. It throws for an entity the schema does not know and
             * for a record the user may not read; both are caught, because a hand-edited URL
             * must not reach the core's debug page.
             */
            try {
                $record = (new Api($this->app, $request))->getSingle($name, $id, null, $language);
            } catch (ContentflyException $denied) {
                return new Response('', 404);
            }

            /*
             * `getSingle()` ANSWERS AN OBJECT, NOT AN ARRAY. With `$returnObject = false` it
             * returns `toValueObject()`, and that is a `stdClass` whose properties are the
             * fields — the same shape `getList()` puts in its `objects`, which is why
             * `ListController` reads its cells with `->`. Checking for an array here passed the
             * empty form and turned every existing record into a 404.
             */
            if (!is_object($record)) {
                return new Response('', 404);
            }
        }

        $fields = $this->app[EntityView::CONTAINER_KEY]->formFields($name);
        $base   = $manager->getPrefix().'/form/'.MenuBuilder::entityToUrl($name);

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
            'fields'     => self::controls($fields, $record),
        ));

        return new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));
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
     * The fields with everything the template needs to render one row each.
     *
     * THE TEMPLATE DECIDES NOTHING HERE. It walks this list and switches on `control`; which
     * control a field gets, whether it is editable at all and what its value prints as are all
     * answered in PHP. That is the same split the list view uses, and for the same reason: a
     * wrong type reaching Twig is a runtime error on the page nobody tested with that field.
     *
     * @param list<array{property: string, label: string, widget: array<string,mixed>}> $fields
     * @param object|null                                                               $record
     *
     * @return list<array<string,mixed>>
     */
    private static function controls(array $fields, ?object $record): array
    {
        $controls = array();

        foreach ($fields as $field) {
            $widget = $field['widget'];
            $raw    = $record === null
                ? ($widget['default'] ?? null)
                : ($record->{$field['property']} ?? null);

            $controls[] = array(
                'property'  => $field['property'],
                'label'     => $field['label'],
                'control'   => self::control($widget),
                'required'  => ($widget['required'] ?? false) === true,
                'maxlength' => $widget['maxlength'] ?? null,
                'options'   => self::options($widget),
                'value'     => self::value($raw),
                'checked'   => self::checked($raw),
            );
        }

        return $controls;
    }

    /**
     * Which control one field is rendered with, or `display` for a value that is only shown.
     *
     * @param array<string,mixed> $widget
     */
    private static function control(array $widget): string
    {
        if (($widget['readonly'] ?? false) === true) {
            return 'display';
        }

        $key = (string) ($widget['widget'] ?? '');

        /* `select` without options would be an empty dropdown — worse than showing the value. */
        if ($key === 'select' && self::options($widget) === array()) {
            return 'display';
        }

        return self::CONTROLS[$key] ?? 'display';
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
