<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\PIM\Classes\Api;
use Areanet\PIM\Classes\Exceptions\ContentflyException;
use Areanet\PIM\Classes\Helper;
use Symfony\Component\HttpFoundation\Request;

/**
 * The choosable records behind a relation field — ADR 0016 §2.
 *
 * WHY THIS EXISTS AT ALL: the schema carries no options for a relation. For `checkbox` and
 * `radio` it states a `group` id and nothing else (gap L8 of the schema contract), for the other
 * relation types only the target entity. A form that wants to *offer* a relation therefore has
 * to go and look, and this is the one place that does it.
 *
 * `Api::getList()`, IN-PROCESS, AND NOT A QUERY OF OUR OWN. The reason is not convenience: that
 * method asks `Permission::isReadable()` for the target entity (`Api.php:1284`). An editor
 * without read access to the target gets no options at all, and gets that before story 002-009
 * builds the permission checks — a dropdown listing records the editor may not read would be a
 * quiet hole. A hand-written Doctrine query would be shorter and would skip exactly that check.
 * Going over HTTP against our own API is ruled out by ADR 0006.
 *
 * THE LABEL COMES FROM `settings.labelProperty`, AND ITS DEFAULT IS `null` (gap L6). Where it is
 * not maintained the option shows the record's id. That is deliberately ugly: it is never wrong,
 * and it makes the missing setting visible. Guessing the first `string` property would look
 * better and be worse — a plausible wrong name is the kind of mistake nobody reports.
 *
 * THE LIMIT IS A GUARD, NOT A JUDGEMENT ABOUT USABILITY. `LIMIT` records are fetched at most, and
 * the true count comes back alongside, so the caller can tell "this is all of them" from "there
 * are more". What the form does above the limit is the caller's decision, not this class's.
 */
final class RelationOptions
{
    /** Where the provider puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.relation_options';

    /**
     * How many target records a relation may offer before the form stops offering them.
     *
     * Fifty is set, not measured (ADR 0016 §2 says so in as many words). It is high enough that
     * every relation of a normal content model stays below it and low enough that a target table
     * with thousands of rows cannot turn one form into one unusable page plus one expensive
     * query. The search that serves the other case is task 002-007-0007.
     */
    public const LIMIT = 50;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The options for one relation field, or `null` when it cannot offer any.
     *
     * `null` MEANS "DO NOT OFFER THIS", and it covers three different causes on purpose: the
     * definition names no target, the target is not in the schema, or the core refuses it —
     * because the entity is unknown to it or the editor may not read it. All three end in the
     * same place, a field that is shown instead of offered, so the caller does not have to tell
     * them apart. An empty target table lands here too: `getList()` answers `null` for an empty
     * result rather than an empty list, and an empty dropdown is worse than a displayed value.
     *
     * @param array<string,mixed> $widget The widget definition from `WidgetResolver`
     *
     * @return array{options: list<array{value: string, label: string}>, total: int}|null
     */
    public function forField(array $widget, Request $request): ?array
    {
        $prepared = $this->describe($widget);

        if ($prepared === null) {
            return null;
        }

        [$name, $label, $where] = $prepared;

        return $this->query($name, $label, $where, $request);
    }

    /**
     * The matches for a search term in one relation field — the other half of ADR 0016 §2.
     *
     * THE CORE'S OWN `fulltext` DOES THE SEARCHING, and that is not laziness. It escapes `%`,
     * `_` and `\` in the term before it reaches the LIKE pattern (`Api.php:1452`, fixed there
     * under 015-000-0012 because an unescaped `_` lets a caller ask whether the sixth character
     * is an `x`), and it skips properties the schema marks `secret`. Writing the pattern here
     * would mean repeating both of those correctly, and getting the first one wrong is silent.
     *
     * IT SEARCHES EVERY TEXT PROPERTY OF THE TARGET, not only the label — deviating from the
     * task's own wording, and in the editor's favour: the term someone types is as likely to sit
     * in a description as in a title, and a search that only looks at the label answers "nothing
     * found" for a record that is plainly there.
     *
     * @return array{options: list<array{value: string, label: string}>, total: int}|null
     */
    public function search(array $widget, string $query, Request $request): ?array
    {
        if (trim($query) === '') {
            return null;
        }

        $prepared = $this->describe($widget);

        if ($prepared === null) {
            return null;
        }

        [$name, $label, $where] = $prepared;

        $where = ($where ?? array()) + array('fulltext' => $query);

        return $this->query($name, $label, $where, $request);
    }

    /**
     * Entity name, label property and base filter of one relation field, or `null`.
     *
     * The part `forField()` and `search()` have in common, kept in one place because the two
     * must not disagree about which entity a field points at or which group it is restricted to.
     *
     * @param array<string,mixed> $widget
     *
     * @return array{0: string, 1: string|null, 2: array<string,mixed>|null}|null
     */
    private function describe(array $widget): ?array
    {
        $target = $widget['target'] ?? null;

        if (!is_string($target) || $target === '') {
            return null;
        }

        /*
         * `accept` IS NOT SPELLED ONE WAY. The schema answers a fully qualified class name for
         * `join` and `multijoin` (`Custom\Entity\Harness\FeldtypGruppe`) and the short schema
         * name for `onejoin` (`Harness\FeldtypDetail`) — both occur on one entity. The core's
         * own helper is what reconciles them, and it is also what `MultijoinType` uses.
         */
        $name   = (new Helper())->getShortEntityName($target);
        $schema = $this->app['schema'];

        if (!isset($schema[$name]['settings'])) {
            return null;
        }

        $label = $schema[$name]['settings']['labelProperty'] ?? null;
        $label = is_string($label) && isset($schema[$name]['properties'][$label]) ? $label : null;

        /*
         * `checkbox` AND `radio` POINT AT ONE GROUP OF A SHARED TABLE. Both accept `PIM\Option`,
         * where every option of every group lives; without this filter a field would offer the
         * options of every other field as well. `getList()` takes the group as an ordinary
         * `where` over the `join` property (`Api.php:1392`), and a caller of the search route
         * cannot widen it — the group is read here, from the schema, never from the request.
         */
        $where = isset($widget['group']) && is_string($widget['group'])
            ? array('group' => $widget['group'])
            : null;

        return array($name, $label, $where);
    }

    /**
     * One list query against the core, turned into options.
     *
     * @param array<string,mixed>|null $where
     *
     * @return array{options: list<array{value: string, label: string}>, total: int}|null
     */
    private function query(string $name, ?string $label, ?array $where, Request $request): ?array
    {
        try {
            $result = (new Api($this->app, $request))->getList(
                $name,
                $where,
                null,
                null,
                /*
                 * `id` HAS TO BE ASKED FOR. A restricted property list is honoured literally by
                 * `toValueObject()`, so a list of just the label property comes back as objects
                 * carrying the label and NOTHING ELSE — no id, and therefore no option value.
                 * That is not a detail to rediscover: the first version of this class asked for
                 * the label alone and every relation silently fell back to being displayed.
                 */
                $label === null ? array('id') : array('id', $label),
                null,
                false,
                1,
                self::LIMIT
            );
        } catch (ContentflyException $refused) {
            return null;
        }


        if (!is_array($result) || empty($result['objects'])) {
            return null;
        }

        $options = array();

        foreach ($result['objects'] as $record) {
            $id = is_object($record) ? ($record->id ?? null) : null;

            if (!is_scalar($id)) {
                continue;
            }

            $text = $label === null || !is_object($record) ? null : ($record->{$label} ?? null);

            $options[] = array(
                'value' => (string) $id,
                'label' => is_scalar($text) && (string) $text !== '' ? (string) $text : (string) $id,
            );
        }

        if ($options === array()) {
            return null;
        }

        $total = $result['totalObjects'] ?? count($options);

        return array(
            'options' => $options,
            'total'   => is_numeric($total) ? (int) $total : count($options),
        );
    }
}
