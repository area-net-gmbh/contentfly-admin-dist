<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Auth\PermissionView;
use Areanet\ContentflyAdmin\Page\PagePermission;
use Areanet\ContentflyAdmin\Page\RowActionRegistry;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\View\ColumnBuilder;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\RelationOptions;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Language;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The records of one entity, as a table.
 *
 * ITS OWN MOUNT UNDER `/<prefix>/list`, not a route on the prefix itself. `RouteManager` keys
 * its providers by mount path, so a second `mount($prefix, …)` would replace `AdminController`
 * rather than extend it — the same reason the assets have their own mount. Addressing an entity
 * as `/<prefix>/list/<Entity>` instead of `/<prefix>/<Entity>` is a decision of its own and
 * recorded as ADR 0012: it keeps the prefix free for `login`, `logout` and the custom pages of
 * extension stage 3a, which would otherwise compete with entity names for one namespace.
 *
 * THE WHITELIST IS CHECKED HERE, not only in the menu. `MenuBuilder` decides what is *linked*;
 * without this check an entity left out of a project's `entities` list would still answer to a
 * typed URL, and a configuration boundary that only holds for people who click is none. ADR 0005
 * calls it a configuration rather than a security boundary — that is about what it protects
 * against, not a licence to leave the back door open.
 *
 * THE COLUMNS AND THE QUERY COME FROM ONE LIST. `EntityView` answers which fields are shown —
 * with the rules of `ColumnBuilder` behind it — and the same list goes into `Api::getList()` as
 * `$properties`, which makes the core select exactly those columns. A field that is not a column
 * is therefore never read from the database — which is what makes the `secret` filter in those
 * rules worth anything.
 */
final class ListController
{
    use GuardsCoreVersion;
    use GuardsPermissions;

    /**
     * How many records one page shows.
     *
     * A constant, not an option: zero configuration is the hard design rule of this package. It
     * is stated here rather than left to `Api::getList()`'s own default of 20, so that a change
     * to the core's default cannot silently resize the admin's pages.
     */
    public const ITEMS_PER_PAGE = 25;

    /** What a cell shows when the value is null or empty — never an empty-looking table. */
    private const EMPTY_CELL = '—';

    /**
     * The filterable types whose control is a list of existing records.
     *
     * A subset of `EntityView::FILTERABLE_TYPES` — the ones that need `RelationOptions` to say
     * what can be chosen. `boolean` and `integer` carry their own range and need nothing.
     *
     * @var list<string>
     */
    private const RELATION_TYPES = array(
        'join',
        'multijoin',
        'checkbox',
    );

    /** The relation filter value that asks for the records where the relation is not set. */
    private const RELATION_UNSET = '-1';

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * One entity's records.
     *
     * The route is registered `isSecure = true`, so the core's guard has run and `auth.user` is
     * set by the time `Api::getList()` asks `Permission::isReadable()` about it. Without that the
     * call would dereference a null user.
     *
     * @param string $entity The entity's URL segment, e.g. `PIM-File` (ADR 0011)
     */
    public function listAction(Request $request, string $entity): Response
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
                'Contentfly Admin cannot list this entity',
                'The entity "'.$name.'" is translatable, but no language is configured. '
                .'Set APP_LANGUAGES in the project configuration.'
            );
        }

        $columns = $this->app[EntityView::CONTAINER_KEY]->listColumns($name);
        $sort      = self::sortProperty($request, $columns);
        $direction = self::direction($request);
        $page      = self::page($request);

        $filterDefs = $this->app[EntityView::CONTAINER_KEY]->listFilters($name);
        $selected   = self::selectedFilters($request, $filterDefs);
        $searchable = $this->app[EntityView::CONTAINER_KEY]->isSearchable($name);
        $term       = $searchable ? self::searchTerm($request) : null;
        $where      = self::where($selected, $term);

        $result = (new Api($this->app, $request))->getList(
            $name,
            $where,
            $sort === null ? null : array($sort => $direction),
            null,
            array_column($columns, 'property'),
            null,
            false,
            $page,
            self::ITEMS_PER_PAGE,
            $language
        );

        /*
         * `getList()` ANSWERS `null` FOR AN EMPTY RESULT, not an empty list — it returns before
         * it ever builds the `totalObjects`/`objects` pair (`Api.php:1640`). The core's own
         * `ApiController` normalises that away at its edge and so does this one, because every
         * caller downstream would otherwise have to know about a special case that says nothing.
         */
        $records = is_array($result) ? ($result['objects'] ?? array()) : array();
        $total   = is_array($result) ? (int) ($result['totalObjects'] ?? 0) : 0;

        /*
         * THE PAGE IS CLAMPED THE WAY THE CORE CLAMPS IT. `getList()` quietly moves a request
         * past the end back onto the last page (`Api.php:1503-1505`) and does not say that it
         * did, so a navigation that printed the requested number would label the last page `99`.
         * The same arithmetic here keeps what is shown and what is said about it in step.
         */
        $pages = max(1, (int) ceil($total / self::ITEMS_PER_PAGE));
        $page  = min($page, $pages);

        $base     = $manager->getPrefix().'/list/'.MenuBuilder::entityToUrl($name);
        $formBase = $manager->getPrefix().'/form/'.MenuBuilder::entityToUrl($name);
        $query    = array('lang' => Language::normalise($request->query->get('lang')));

        /*
         * THE FILTER TRAVELS WITH EVERY OTHER ADDRESS OF THIS PAGE. It sits in `$query`, which is
         * the base of the sort links and the pager, so re-sorting or paging keeps the filter and
         * a filtered view stays one sendable link (ADR 0018 §2). The form that sets the filter
         * does the opposite and deliberately drops `page` — see `filterForm()`.
         */
        if ($selected !== array()) {
            $query['filter'] = $selected;
        }

        /* The search term travels with the filter, for the same reason and in the same place. */
        if ($term !== null) {
            $query['q'] = $term;
        }

        /* `dir` only says something next to a `sort`; on its own it would be noise in the URL. */
        $order = $query + array('sort' => $sort, 'dir' => $sort === null ? null : strtolower($direction));

        $queryForm = $this->queryForm($filterDefs, $selected, $term, $searchable, $base, $query, $sort, $direction, $request);
        $rows      = self::rows($records, $columns, $formBase);

        /*
         * THE SELECTION IS OFFERED ONLY WHERE THERE IS SOMETHING TO SELECT. An empty table needs
         * no checkboxes, and a record without a usable id cannot be named in `ids[]` — the same
         * record whose row offers no edit link.
         */
        $token     = FormToken::issue($request);
        $selection = self::selection($rows, $manager->getPrefix(), $name, $base, $order, $page, $token);

        if ($selection !== null) {
            $selection['fields'] = $this->app[EntityView::CONTAINER_KEY]->changeableFields($name);
        }

        /*
         * THE SEARCH BOX IS THE ONLY REASON THIS PAGE NEEDS A MODULE, so it is asked for only
         * where there is one — the same per-page decision the form view makes in
         * `FormController::controllers()`. Without the module the box stays a plain text field in
         * a plain form and searching costs one press of the return key.
         */
        $controllers = array();

        if ($queryForm !== null && $queryForm['search'] !== null) {
            $controllers['admin-list-search'] = 'admin/list-search';
        }

        if ($selection !== null) {
            $controllers['admin-list-select'] = 'admin/list-select';
        }

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('list.html.twig', array(
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
            /*
             * THE WAY BACK TO THE TREE, and `null` for every other entity (ADR 0025). A tree
             * entity's menu entry points at `/tree`, so this list is reachable only through the
             * switch on that page — without the counterpart here the editor who used it would
             * have no way back except the browser's history.
             */
            'treeUrl'    => $this->app[EntityView::CONTAINER_KEY]->isTree($name)
                ? $manager->getPrefix().'/tree/'.MenuBuilder::entityToUrl($name)
                : null,
            'columns'    => self::sortableColumns($columns, $base, $query, $sort, $direction, $page),
            'rows'       => $rows,
            'selection'  => $selection,
            /*
             * NO SELECTION, NO ACTIONS. The buttons live inside the bulk form, and that form is
             * only rendered where `selection` is set — which is exactly where at least one row
             * has an id (`self::selection()`). An action without a record to act on has nothing
             * to offer, so the two conditions are one.
             */
            'rowActions' => $selection === null ? array() : $this->rowActions($name, $manager->getPrefix()),
            'report'     => self::report($request),
            'newUrl'     => $formBase,
            'queryForm'   => $queryForm,
            'controllers' => $controllers,
            'pagination' => array(
                'page'    => $page,
                'pages'   => $pages,
                'total'   => $total,
                'prevUrl' => $page > 1 ? self::url($base, $order + array('page' => $page - 1)) : null,
                'nextUrl' => $page < $pages ? self::url($base, $order + array('page' => $page + 1)) : null,
            ),
        ));

        $response = new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));

        /*
         * The token's cookie is set once per session, exactly as the form view sets it
         * (`FormController::render()`): the form here posts to the bulk actions, and without the
         * cookie `FormToken::verify()` would have nothing to compare the field against.
         */
        if ($selection !== null && !FormToken::isIssued($request, $token)) {
            $response->headers->setCookie(FormToken::cookie($request, $token, $manager->getPrefix()));
        }

        return $response;
    }

    /**
     * The language a translatable entity is listed in.
     *
     * `Language::fromRequest()` CANNOT BE USED HERE. It reads `lang` from the request *body*,
     * which a listing page navigated to with GET does not have, so it would answer `null` for
     * every list and `Api::getList()` throws on an empty language for an i18n entity. The query
     * parameter is read instead, and an absent one falls back to the first configured language
     * — a zero-configuration admin has to show *something* before anyone picks a language. A
     * real language switch belongs with the form view, which is where editing a translation
     * happens.
     *
     * An unknown or unconfigured value falls back rather than failing: it reaches this method
     * from a URL, and a mistyped language is not worth an error page.
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
     * The property the list is sorted by, or `null` for the core's own default.
     *
     * ONLY A COLUMN OF THIS LIST IS ACCEPTED, and that is not politeness towards the user.
     * `Api::getList()` puts the field name into DQL and guards it with `assertProperty()`,
     * which *throws* on an unknown one (`Api.php:1520`) — a deliberate choice there, because a
     * silently dropped sort order is a wrong answer to an API call. Here it would mean that a
     * hand-edited URL answers with an error page instead of a list, so the value is checked
     * against what this page actually offers and anything else falls back to the default.
     *
     * @param list<array{property: string, label: string}> $columns
     */
    private static function sortProperty(Request $request, array $columns): ?string
    {
        $requested = $request->query->get('sort');

        if (!is_string($requested) || $requested === '') {
            return null;
        }

        return in_array($requested, array_column($columns, 'property'), true) ? $requested : null;
    }

    /**
     * `ASC` or `DESC` — anything else is `ASC`.
     *
     * The core rejects a direction it does not know with a 400, so the same reasoning as for the
     * property applies: a mistyped URL should still produce a list.
     */
    private static function direction(Request $request): string
    {
        return strtolower((string) $request->query->get('dir')) === 'desc' ? 'DESC' : 'ASC';
    }

    /**
     * The 1-based page number.
     *
     * NEVER ZERO, AND THAT IS THE IMPORTANT PART. `Api::getList()` applies its limit inside
     * `if($currentPage)` (`Api.php:1507`), so page `0` does not mean "the first page" — it means
     * no limit at all and the whole table in one response. A non-numeric or negative value in
     * the URL therefore has to land on 1, not on 0.
     */
    private static function page(Request $request): int
    {
        $requested = $request->query->get('page');

        return is_numeric($requested) ? max(1, (int) $requested) : 1;
    }

    /**
     * The filter values from the URL, keyed by property — only the ones this list offers.
     *
     * AN UNKNOWN PARAMETER IS DROPPED HERE AND NEVER REACHES THE CORE, and that is the opposite
     * of what the core does with it: `Api::getList()` skips a `where` entry whose field it does
     * not know (`Api.php:1339-1342`) and answers as if nothing had been asked. Passed on, a
     * mistyped `?filter[aktive]=true` would therefore come back as the unfiltered list and look
     * like a filter that found everything. Dropped here, the reader at least gets the same list
     * *without* a filled control claiming otherwise.
     *
     * THE VALUE IS NORMALISED TO WHAT THE CORE EXPECTS, per type (`Api.php:1360-1426`), and a
     * value that cannot be normalised is dropped rather than guessed. `-1` passes every relation
     * type untouched: that is the core's own spelling for "this relation is not set".
     *
     * @param list<array{property: string, label: string, type: string, target: string|null, group: string|null}> $filterDefs
     *
     * @return array<string,string>
     */
    private static function selectedFilters(Request $request, array $filterDefs): array
    {
        /* `get()` throws on an array parameter in Symfony 5; `all()` is the documented way in. */
        $requested = $request->query->all()['filter'] ?? null;

        if (!is_array($requested)) {
            return array();
        }

        $selected = array();

        foreach ($filterDefs as $filter) {
            $value = $requested[$filter['property']] ?? null;

            if (!is_scalar($value) || (string) $value === '') {
                continue;
            }

            $value = (string) $value;

            switch ($filter['type']) {
                case 'boolean':
                    if ($value !== 'true' && $value !== 'false') {
                        continue 2;
                    }

                    break;
                case 'integer':
                    if (!is_numeric($value)) {
                        continue 2;
                    }

                    $value = (string) (int) $value;

                    break;
                default:
                    /* A relation takes an id, and ids are opaque — only "not set" is special. */
                    break;
            }

            $selected[$filter['property']] = $value;
        }

        return $selected;
    }

    /**
     * The search term from the URL, or `null` when nothing was asked.
     *
     * TRIMMED, AND A BLANK TERM IS NOTHING. `where['fulltext']` would otherwise become `%  %`
     * and match every record that contains two spaces — an answer that looks like a search
     * result and is not one. The core escapes `%`, `_` and `\` in the term itself
     * (`Api.php:1455`), so nothing else has to be done to it here.
     */
    private static function searchTerm(Request $request): ?string
    {
        $requested = $request->query->get('q');

        if (!is_string($requested)) {
            return null;
        }

        $requested = trim($requested);

        return $requested === '' ? null : $requested;
    }

    /**
     * Filters and search term as `Api::getList()` takes them, or `null` when neither is set.
     *
     * `null` AND NOT AN EMPTY ARRAY, because `getList()` guards its whole `where` block with
     * `if($where && …)` (`Api.php:1335`) — an empty array would take the same branch as `null`
     * today, but saying `null` keeps the call identical to the one this list made before filters
     * existed, so an unfiltered list cannot change behaviour because of this feature.
     *
     * THE TERM GOES IN UNDER `fulltext`, WHICH IS WHY NO FILTER MAY BE CALLED THAT. The core
     * reads that key as its search (`Api.php:1440`) and not as a field name, so a filterable
     * property of the same name would silently take the search's place —
     * `EntityView::RESERVED_WHERE_KEYS` is what keeps the two apart, and this is the line that
     * depends on it.
     *
     * @param array<string,string> $selected
     *
     * @return array<string,string>|null
     */
    private static function where(array $selected, ?string $term): ?array
    {
        if ($term !== null) {
            $selected['fulltext'] = $term;
        }

        return $selected === array() ? null : $selected;
    }

    /**
     * Everything the query bar needs to render itself, or `null` when this entity offers nothing.
     *
     * SEARCH AND FILTERS ARE ONE FORM, and that is what makes them combine. Two forms would each
     * have to carry the other's state as hidden fields, and the one that was submitted would
     * decide whose state is a keystroke out of date. In one form the browser sends both, every
     * time, and `where` gets them together (ADR 0018 §1 and §2).
     *
     * THE FORM DELIBERATELY DOES NOT CARRY `page`. Sorting and language survive a filter change
     * as hidden fields, because they describe how the list is shown; the page number describes
     * *where* in a result set the reader is, and a changed filter is a different result set.
     * Carrying it would land someone on page 7 of 3 — the core would quietly clamp that
     * (`Api.php:1503-1505`) and the pager would then disagree with the URL. Leaving it out makes
     * every filter change start at page one, which is also what ADR 0018 asks for.
     *
     * A RELATION THAT CANNOT OFFER OPTIONS IS DROPPED, not rendered empty. `RelationOptions`
     * answers `null` for a target it cannot read — unknown to the schema, or not readable by this
     * editor — and an empty dropdown would invite a filter that can only ever return nothing.
     *
     * @param list<array{property: string, label: string, type: string, target: string|null, group: string|null}> $filterDefs
     * @param array<string,string>                                                                                $selected
     * @param array<string,string|array<string,string>|null>                                                      $query
     *
     * @return array{action: string, hidden: array<string,string>, fields: list<array<string,mixed>>, search: string|null, active: bool, resetUrl: string|null, unsetValue: string}|null
     */
    private function queryForm(
        array $filterDefs,
        array $selected,
        ?string $term,
        bool $searchable,
        string $base,
        array $query,
        ?string $sort,
        string $direction,
        Request $request
    ): ?array {
        if ($filterDefs === array() && !$searchable) {
            return null;
        }

        $relations = $this->app[RelationOptions::CONTAINER_KEY];
        $fields    = array();

        foreach ($filterDefs as $filter) {
            $options   = null;
            $truncated = false;

            if (in_array($filter['type'], self::RELATION_TYPES, true)) {
                $offered = $relations->forField(
                    array('target' => $filter['target'], 'group' => $filter['group']),
                    $request
                );

                if ($offered === null) {
                    continue;
                }

                $options   = $offered['options'];
                $truncated = $offered['total'] > count($offered['options']);
            }

            $fields[] = array(
                'property'  => $filter['property'],
                'label'     => $filter['label'],
                'type'      => $filter['type'],
                'value'     => $selected[$filter['property']] ?? '',
                'options'   => $options,
                'truncated' => $truncated,
            );
        }

        if ($fields === array() && !$searchable) {
            return null;
        }

        $hidden = array_filter(
            array(
                'lang' => $query['lang'] ?? null,
                'sort' => $sort,
                'dir'  => $sort === null ? null : strtolower($direction),
            ),
            static function ($value): bool {
                return is_string($value) && $value !== '';
            }
        );

        $set = $selected !== array() || $term !== null;

        return array(
            'action'     => $base,
            'hidden'     => $hidden,
            'fields'     => $fields,
            'search'     => $searchable ? (string) $term : null,
            'active'     => $set,
            'resetUrl'   => $set ? self::url($base, $hidden) : null,
            'unsetValue' => self::RELATION_UNSET,
        );
    }

    /**
     * The columns with what the table head needs to render them as sort controls.
     *
     * A CLICK ON THE ACTIVE COLUMN TURNS THE DIRECTION AROUND, a click on any other sorts it
     * ascending — the behaviour every table has, so nothing has to be learned here.
     *
     * THE PAGE TRAVELS WITH THE SORT. Re-sorting does not send the reader back to page one;
     * every parameter of the view survives every other one, which is what makes any state of
     * this list a link that can be sent to somebody.
     *
     * @param list<array{property: string, label: string}>       $columns
     * @param array<string,string|array<string,string>|null>      $query
     *
     * @return list<array{property: string, label: string, url: string, ariaSort: string}>
     */
    private static function sortableColumns(array $columns, string $base, array $query, ?string $sort, string $direction, int $page): array
    {
        $sortable = array();

        foreach ($columns as $column) {
            $active = $column['property'] === $sort;

            $sortable[] = array(
                'property' => $column['property'],
                'label'    => $column['label'],
                'url'      => self::url($base, $query + array(
                    'sort' => $column['property'],
                    'dir'  => $active && $direction === 'ASC' ? 'desc' : 'asc',
                    'page' => $page,
                )),
                'ariaSort' => $active ? ($direction === 'ASC' ? 'ascending' : 'descending') : 'none',
            );
        }

        return $sortable;
    }

    /**
     * One address of this list, with the empty parameters left out.
     *
     * Dropping `null` keeps the default view at its bare address instead of
     * `?sort=&dir=asc&page=1`, which matters because that bare address is what the menu links
     * to — two addresses for one page would split the browser history for no reason.
     *
     * A nested `filter` array travels the same way: `http_build_query()` spells it
     * `filter%5Baktiv%5D=true`, which is exactly what `selectedFilters()` reads back. An *empty*
     * filter array is dropped like an empty string — otherwise the unfiltered list would get a
     * second address that renders identically.
     *
     * @param array<string,string|int|array<string,string>|null> $params
     */
    private static function url(string $base, array $params): string
    {
        $params = array_filter($params, static function ($value): bool {
            if (is_array($value)) {
                return $value !== array();
            }

            return $value !== null && $value !== '';
        });

        return $params === array() ? $base : $base.'?'.http_build_query($params);
    }

    /**
     * The records turned into printable cells, plus the address of each record's form.
     *
     * THE FORMATTING HAPPENS HERE AND NOT IN TWIG. `toValueObject()` does not answer scalars
     * throughout: a `datetime` arrives as an array of `LOCAL`, `LOCAL_TIME` and `ISO8601`
     * (`Types/DatetimeType.php:43`), and printing that in a template is a runtime error in the
     * one place that is hardest to see coming. The template receives strings only.
     *
     * THE EDIT ADDRESS IS BUILT HERE for the same reason the sort addresses are (002-006-0002):
     * the template builds no URLs. It is `null` for a record without a usable id rather than a
     * link to `/form/<Entity>/`, which is the create route and would offer a blank form where
     * the reader expected the row they clicked.
     *
     * @param list<object>                                 $records
     * @param list<array{property: string, label: string}> $columns
     *
     * @return list<array{cells: array<string,string>, id: string|null, editUrl: string|null}>
     */
    private static function rows(array $records, array $columns, string $formBase): array
    {
        $rows = array();

        foreach ($records as $record) {
            $cells = array();

            foreach ($columns as $column) {
                $cells[$column['property']] = self::cell($record->{$column['property']} ?? null);
            }

            $id = $record->{ColumnBuilder::ID_PROPERTY} ?? null;

            $usable = is_scalar($id) && (string) $id !== '' ? (string) $id : null;

            $rows[] = array(
                'cells'   => $cells,
                'id'      => $usable,
                'editUrl' => $usable === null ? null : $formBase.'/'.rawurlencode($usable),
            );
        }

        return $rows;
    }

    /**
     * What a bulk action left to say, or `null` when this is an ordinary view of the list.
     *
     * THE SENTENCE IS BUILT HERE, OUT OF PARTS THE URL ONLY NAMES. `BulkController` redirects
     * back with an action, two counts, a few ids and at most one error code — never with prose.
     * Everything the reader sees is composed from those parts against the wordings below, so a
     * crafted address cannot put a sentence of its own on this page. It can show a wrong count,
     * which is why the failing ids are named next to it: a number alone would be the whole claim.
     *
     * AN UNKNOWN CODE STILL PRODUCES A REASON. The core's codes are stable identifiers and the
     * two that a selection runs into are the two below, but a third would otherwise leave the
     * report saying that something failed and nothing about why — so the code itself is shown.
     * Twig escapes it; it is an identifier, not markup.
     *
     * @return array{ok: bool, text: string}|null
     */
    private static function report(Request $request): ?array
    {
        $bulk = $request->query->get('bulk');

        if (!is_string($bulk) || $bulk === '') {
            return null;
        }

        /* The refusals that never got as far as the core. */
        $refusals = array(
            'expired' => 'Die Aktion ist abgelaufen oder wurde nicht von dieser Seite abgeschickt. Bitte erneut markieren.',
            'none'    => 'Es war kein Datensatz markiert — es wurde nichts geändert.',
            'field'   => 'Das zu ändernde Feld gibt es an dieser Entität nicht oder es ist schreibgeschützt.',
            'value'   => 'Zu dem gewählten Feld war kein Wert angegeben.',
            'unknown' => 'Die Aktion ist unbekannt — es wurde nichts geändert.',
            'forbidden' => 'Für diese Aktion fehlt dir die Berechtigung — es wurde nichts geändert.',
        );

        if (isset($refusals[$bulk])) {
            return array('ok' => false, 'text' => $refusals[$bulk]);
        }

        if ($bulk !== BulkController::DELETE && $bulk !== BulkController::CHANGE) {
            return null;
        }

        $done   = (int) $request->query->get('done', '0');
        $failed = (int) $request->query->get('failed', '0');

        $verb = $bulk === BulkController::DELETE
            ? array('gelöscht', 'löschen')
            : array('geändert', 'ändern');

        $text = $done === 1
            ? '1 Datensatz '.$verb[0].'.'
            : $done.' Datensätze '.$verb[0].'.';

        if ($failed > 0) {
            $text .= ' '.($failed === 1 ? 'Einen Datensatz' : $failed.' Datensätze')
                .' konnte der Admin nicht '.$verb[1].': '.self::named($request)
                .self::reason($request);
        }

        return array('ok' => $failed === 0, 'text' => $text);
    }

    /**
     * The ids the report names, as a readable enumeration.
     *
     * `BulkController::NAMED_FAILURES` caps how many travel, so this says "und weitere" where the
     * selection had more — a list that silently stopped would read like the complete answer.
     */
    private static function named(Request $request): string
    {
        $ids = array_filter(explode(',', (string) $request->query->get('ids', '')));

        if ($ids === array()) {
            return 'die Markierung ist nicht mehr nachvollziehbar.';
        }

        $named = implode(', ', $ids);
        $failed = (int) $request->query->get('failed', '0');

        return $failed > count($ids) ? $named.' und weitere.' : $named.'.';
    }

    /**
     * The reason for a refusal, as a sentence, or an empty string when the core named none.
     */
    private static function reason(Request $request): string
    {
        $code = $request->query->get('code');

        if (!is_string($code) || $code === '') {
            return '';
        }

        $reasons = array(
            'contentfly_general_access_denied' => ' Grund: fehlende Berechtigung.',
            'contentfly_general_not_found'     => ' Grund: der Datensatz existiert nicht mehr.',
            BulkController::UNREADABLE         => ' Die Antwort des Grundsystems war nicht auswertbar.',
        );

        return $reasons[$code] ?? ' Grund (Meldung des Grundsystems): '.$code.'.';
    }

    /**
     * What the selection form needs, or `null` when this page has nothing to select.
     *
     * THE FORM IS A SECOND FORM, NOT A WRAPPER AROUND THE FIRST. The query bar is a GET form and
     * this one is a POST, and an HTML form cannot contain another — so the bar sits above the
     * table and this one around it. That split is also why the selection survives nothing: it
     * belongs to the page the reader is looking at, which is what ADR 0018 §7 asks for.
     *
     * THE RETURN ADDRESS TRAVELS AS A QUERY STRING, NEVER AS A URL. The bulk actions have to come
     * back to the list the reader started from, filter and page included, and the obvious way —
     * a hidden field with the full address — is an open redirect: whatever a crafted form put in
     * there is where the editor's browser would go. Only the parameters are passed, and the path
     * is rebuilt from the entity on the other side, so nothing a client sends can leave this
     * admin.
     *
     * @param list<array{cells: array<string,string>, id: string|null, editUrl: string|null}> $rows
     * @param array<string,string|array<string,string>|null>                                  $order
     *
     * @return array{action: string, tokenField: string, token: string, query: string, actionField: string, rowField: string, delete: string, change: string}|null
     */
    private static function selection(
        array $rows,
        string $prefix,
        string $name,
        string $base,
        array $order,
        int $page,
        string $token
    ): ?array {
        foreach ($rows as $row) {
            if ($row['id'] !== null) {
                $here = self::url($base, $order + array('page' => $page));

                return array(
                    'action'      => $prefix.'/bulk/'.MenuBuilder::entityToUrl($name),
                    'tokenField'  => FormToken::FIELD,
                    'token'       => $token,
                    'query'       => (string) parse_url($here, PHP_URL_QUERY),
                    'actionField' => BulkController::ACTION_FIELD,
                    'rowField'    => RowActionController::ROW_FIELD,
                    'delete'      => BulkController::DELETE,
                    'change'      => BulkController::CHANGE,
                );
            }
        }

        return null;
    }

    /**
     * One value as the table prints it.
     *
     * Long text is not shortened here. The concept accepts it in so many words — stage 1 is
     * "plain, long texts in the list included" — and a truncation in PHP would throw the value
     * away for every later reader of this row. The stylesheet clips the cell instead.
     *
     * @param mixed $value
     */
    private static function cell($value): string
    {
        if ($value === null || $value === '') {
            return self::EMPTY_CELL;
        }

        if (is_bool($value)) {
            return $value ? 'Ja' : 'Nein';
        }

        if (is_array($value)) {
            $local = $value['LOCAL'] ?? null;

            return is_scalar($local) ? (string) $local : self::EMPTY_CELL;
        }

        return is_scalar($value) ? (string) $value : self::EMPTY_CELL;
    }
    /**
     * The project's record actions for this entity, as the list offers them.
     *
     * AN ACTION APPEARS ONLY WHERE THE EDITOR HOLDS ITS RIGHT, and the answer comes from the same
     * `PagePermission` the dispatcher asks before it calls (ADR 0028 §3). The discipline is the
     * one `View\MenuBuilder::pageItems()` keeps for pages and `Controller\GuardsPermissions` for
     * the entity routes: a button on an action that then refuses itself is a dead button, and the
     * inverse — reachable but hidden — is worse.
     *
     * THE READ RIGHT IS NOT RE-ASKED HERE. Reaching this method means `listAction()` already
     * passed `mayRead()`; an action without a `permission` of its own therefore appears for
     * everybody who can see the list at all, which is exactly what the attribute promises.
     *
     * ONE URL FOR BOTH BUTTONS. The row button and the bulk button of one action post to the
     * same address and differ only in whether they carry a record id — that is the whole of
     * ADR 0029 §4, and keeping one url here is what makes it hard to get wrong in the template.
     *
     * @return list<array{key: string, label: string, icon: string|null, bulk: bool, url: string}>
     */
    private function rowActions(string $entity, string $prefix): array
    {
        $offered = array();

        foreach ($this->app[RowActionRegistry::CONTAINER_KEY]->forEntity($entity) as $action) {
            if ($action->permission !== null
                && !PagePermission::parse($action->permission)->isGranted($this->app[PermissionView::CONTAINER_KEY])) {
                continue;
            }

            $offered[] = array(
                'key'   => $action->key,
                'label' => $action->label,
                'icon'  => $action->icon,
                'bulk'  => $action->bulk,
                'url'   => $prefix.'/'.RowActionRegistry::SEGMENT.'/'.MenuBuilder::entityToUrl($entity).'/'.$action->key,
            );
        }

        return $offered;
    }

}
