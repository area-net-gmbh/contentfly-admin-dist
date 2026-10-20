<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\View\ColumnBuilder;
use Areanet\ContentflyAdmin\View\MenuBuilder;
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
 * THE COLUMNS AND THE QUERY COME FROM ONE LIST. `ColumnBuilder` decides which fields are shown,
 * and the same list goes into `Api::getList()` as `$properties`, which makes the core select
 * exactly those columns. A field that is not a column is therefore never read from the database
 * — which is what makes the builder's `secret` filter worth anything.
 */
final class ListController
{
    use GuardsCoreVersion;

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

        $columns = $this->app[ColumnBuilder::CONTAINER_KEY]->build($name);

        $result = (new Api($this->app, $request))->getList(
            $name,
            null,
            null,
            null,
            array_column($columns, 'property'),
            null,
            false,
            1,
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

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('list.html.twig', array(
            'title'      => $name.' — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'entity'     => $name,
            'columns'    => $columns,
            'rows'       => self::rows($records, $columns),
        ));

        return new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));
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
     * The records turned into printable cells, one map of property to string per row.
     *
     * THE FORMATTING HAPPENS HERE AND NOT IN TWIG. `toValueObject()` does not answer scalars
     * throughout: a `datetime` arrives as an array of `LOCAL`, `LOCAL_TIME` and `ISO8601`
     * (`Types/DatetimeType.php:43`), and printing that in a template is a runtime error in the
     * one place that is hardest to see coming. The template receives strings only.
     *
     * @param list<object>                                 $records
     * @param list<array{property: string, label: string}> $columns
     *
     * @return list<array<string,string>>
     */
    private static function rows(array $records, array $columns): array
    {
        $rows = array();

        foreach ($records as $record) {
            $row = array();

            foreach ($columns as $column) {
                $row[$column['property']] = self::cell($record->{$column['property']} ?? null);
            }

            $rows[] = $row;
        }

        return $rows;
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
}
