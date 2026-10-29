<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\Schema\ShapingListener;

/**
 * One entity's schema entry turned into a list definition and a form definition.
 *
 * THE ONE PLACE THAT ANSWERS "WHAT DOES THIS ENTITY LOOK LIKE". Both views ask the same schema,
 * and two readers of it are how a list ends up showing a field the form does not know. The list
 * half already existed as `ColumnBuilder` (story 002-003) and is taken over unchanged rather than
 * copied: the column rules — the allowlist of printable types, the five-field cap, `secret`, the
 * bookkeeping fields — stay where they are, and this class delegates to them.
 *
 * THE COLUMN LIST IS ALSO THE FETCH LIST. `ListController` builds `$properties` for
 * `Api::getList()` out of the same result (`array_column($columns, 'property')`), so a column can
 * never appear in the header without its value being fetched — and a field that is not a column
 * is never read out of the database at all. Whatever moves here later has to keep that double
 * duty; it is the reason the `secret` filter sits in the column rules and not in a template.
 *
 * THE FORM READS STAGE 2 WHERE THE ENTITY STATES IT, and falls back to stage 1 where it does
 * not: widget per field from the `WidgetResolver`, label from the humanised property name,
 * fields in schema order. Over that sit the entity's own attributes — `tab`, `readonly` and
 * `hide` since story 002-006 (ADR 0015), `label` and `position` since 002-008 (ADR 0017). All of
 * them go through `Shaping`, which is the single reader the list uses as well.
 *
 * WHAT IS NOT AN EDITABLE FIELD. The id is the record's address, not data. The inherited
 * bookkeeping of the core's `Base` is not edited by hand either, and the form has to name those
 * fields where the list did not have to: the list drops `user`, `userCreated`, `users` and
 * `groups` by their type, because no relation is printable in a table cell — but a form renders
 * relations perfectly well, so "who created this record" would turn into an editable picker on
 * every single entity. Secrets are excluded for the reason they are excluded from the list: a
 * value that is never requested cannot leak.
 */
final class EntityView
{
    /** Where the view puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.entity_view';

    /**
     * Inherited relations of `PIM\Entity\Base` that are bookkeeping, not content.
     *
     * `ColumnBuilder::TECHNICAL_PROPERTIES` names the scalar half of the same idea; these four
     * are the relations, and they are listed here and not there because the list has no need for
     * them — its type allowlist already leaves every relation out. `users` and `groups` are
     * `virtualjoin` and would at least arrive read-only; `user` and `userCreated` would not.
     *
     * @var list<string>
     */
    public const INHERITED_RELATIONS = array(
        'user',
        'userCreated',
        'users',
        'groups',
    );

    /**
     * The field types a list filter is offered for — ADR 0018 §3.
     *
     * These are the types whose equality says something. `boolean`, `integer` and the three
     * relation types are either a choice out of a known set or an exact number; `string`, `text`,
     * `textarea` and `rte` are not, and `Api::getList()` has no per-field `LIKE` to offer them.
     * They are covered by the full-text search instead.
     *
     * @var list<string>
     */
    public const FILTERABLE_TYPES = array(
        'boolean',
        'integer',
        'join',
        'multijoin',
        'checkbox',
    );

    /**
     * Property names that `Api::getList()` has already given a meaning inside `where`.
     *
     * `fulltext` IS THE SEARCH (`Api.php:1440`) and `mimetypes` filters the file table
     * (`:1473`) — neither is read as a field name. A filterable column of either name would
     * therefore not be compared for equality but silently do something else entirely, so no
     * control is offered for it. Skipped rather than refused, in the line of the core's own
     * handling of an unknown field: a column that cannot be filtered is a missing control, not
     * an error page.
     *
     * @var list<string>
     */
    public const RESERVED_WHERE_KEYS = array(
        'fulltext',
        'mimetypes',
    );

    /**
     * The field types the core's full-text search actually looks at.
     *
     * MIRRORS `Api.php:1442` AND NOTHING ELSE. The search is the core's, so what it covers is the
     * core's statement, not this package's: it builds one `OR` over every property of these types
     * that is not `secret`, plus an exact match on the id. Repeating the list here is the only way
     * to answer "can this entity be searched at all" before offering a box that could not work.
     *
     * @var list<string>
     */
    public const FULLTEXT_TYPES = array(
        'string',
        'text',
        'textarea',
        'rte',
    );

    /**
     * The field types a bulk change offers a control for — `changeableFields()` explains why.
     *
     * @var list<string>
     */
    public const CHANGEABLE_TYPES = array(
        'boolean',
        'integer',
        'decimal',
        'float',
        'string',
        'textarea',
        'select',
    );

    private SchemaClient $schemaClient;

    private ColumnBuilder $columnBuilder;

    private WidgetResolver $widgetResolver;

    public function __construct(
        SchemaClient $schemaClient,
        ColumnBuilder $columnBuilder,
        WidgetResolver $widgetResolver
    ) {
        $this->schemaClient   = $schemaClient;
        $this->columnBuilder  = $columnBuilder;
        $this->widgetResolver = $widgetResolver;
    }

    /**
     * The columns of the entity's list, id first.
     *
     * @param string $entity Entity name as the schema spells it, e.g. `Harness\Feldtyp`
     *
     * @return list<array{property: string, label: string, filterable: bool}>
     */
    public function listColumns(string $entity): array
    {
        return $this->columnBuilder->build($entity);
    }

    /**
     * The filters the entity's list offers, in schema order.
     *
     * READS THE CORE KEY `isFilterable`, which ADR 0017 §3 deliberately left without a consumer
     * — this is the consumer.
     *
     * IT DOES *NOT* START FROM THE COLUMNS, and that was the one correction this story had to
     * make to its own ADR. ADR 0018 §4 first said filterable is only what is also a column,
     * reasoning that the column list doubles as the fetch list in `ListController`. Both halves
     * were wrong: `ColumnBuilder::LISTABLE_TYPES` admits no relation type at all, so the three
     * relation types of §3 would never have been reachable — and the fetch list only decides
     * which values are *printed*. `Api::getList()` applies `where` in SQL and checks nothing but
     * whether the field is in the schema (`Api.php:1339`), so a filter over a field that is not
     * fetched works exactly as well. The most useful filter of all — every position belonging to
     * one record — sits on a relation, which a table cell cannot print in the first place.
     *
     * NOT EVERY FILTERABLE FIELD GETS A CONTROL (ADR 0018 §3). `Api::getList()` compares a
     * `where` entry on a field with `=` and not with `LIKE` (`Api.php:1427-1432`), so a text box
     * over a `string` would answer "nothing found" for `Mül` on a record called `Müller`. That
     * looks like a broken filter, not like an exact comparison, so the text types are left to the
     * full-text search and only the types whose equality is meaningful are offered here.
     *
     * THE REFUSALS OF THE LIST STILL HOLD. A `secret` field is not offered — the core would skip
     * it inside `where` anyway (`Api.php:1356`), and a control that cannot ever filter is worse
     * than none. `hide` keeps a field out of the admin entirely, so it cannot come back as a
     * filter. The core's inherited bookkeeping is left out for the reason the list leaves it out:
     * "who created this record" would otherwise turn up as a filter on every single entity.
     *
     * THE TYPE COMES FROM THE `WidgetResolver`, which is already the one place that answers what
     * kind of field this is — asking the schema again here would be the second reader this class
     * exists to prevent. Only `type`, `target` and `group` are taken from its answer; everything
     * else it says is about an input element in a form, not about a filter.
     *
     * @param string $entity Entity name as the schema spells it, e.g. `Harness\Feldtyp`
     *
     * @return list<array{property: string, label: string, type: string, target: string|null, group: string|null}>
     */
    public function listFilters(string $entity): array
    {
        $properties = $this->schemaClient->getSchema()[$entity]['properties'] ?? array();
        $filters    = array();

        foreach ($properties as $property => $meta) {
            $property = (string) $property;

            if (!self::isFilterable($property, $meta)) {
                continue;
            }

            $widget = $this->widgetResolver->resolve($entity, $property, $meta);
            $type   = $widget['type'] ?? null;

            if (!is_string($type) || !in_array($type, self::FILTERABLE_TYPES, true)) {
                continue;
            }

            $target = $widget['target'] ?? null;
            $group  = $widget['group'] ?? null;

            $filters[] = array(
                'property' => $property,
                'label'    => Shaping::label($meta) ?? ColumnBuilder::humanise($property),
                'type'     => $type,
                'target'   => is_string($target) ? $target : null,
                'group'    => is_string($group) ? $group : null,
            );
        }

        return $filters;
    }

    /**
     * The fields a bulk change may set, in form order.
     *
     * ADR 0018 §5 SETTLED ON ONE FIELD AND ONE VALUE, and this list says which fields can be that
     * one. It starts from `formFields()`, so the question "may this be written at all" is answered
     * exactly once in this package: a field the entity marks `readonly`, one the core computes,
     * one `hide` keeps out of the admin and one whose type has no writable control are already
     * gone before this method sees them.
     *
     * THE TYPE ALLOWLIST IS NARROWER THAN THE FORM'S, and deliberately so. A bulk change has one
     * control for every selected record at once, so the control has to make sense without the
     * record in front of it: a number, a text, a yes/no, a choice out of a fixed list. A relation
     * is left out because its options depend on a query per field, a date because a bulk date is
     * almost always the wrong answer, and a file because there is nothing to upload to twenty
     * records in one go. None of that is a refusal in principle — each is a control this story
     * does not build, and adding one here is a type in this list plus a branch in the template.
     *
     * @param string $entity Entity name as the schema spells it
     *
     * @return list<array{property: string, label: string, type: string, options: list<array{value: string, label: string}>|null, maxlength: int|null}>
     */
    public function changeableFields(string $entity): array
    {
        $changeable = array();

        foreach ($this->formFields($entity) as $field) {
            $widget = $field['widget'];
            $type   = $widget['type'] ?? null;

            if (($widget['readonly'] ?? false) === true) {
                continue;
            }

            if (!is_string($type) || !in_array($type, self::CHANGEABLE_TYPES, true)) {
                continue;
            }

            $options   = $widget['options'] ?? null;
            $maxlength = $widget['maxlength'] ?? null;

            /* A `select` without options cannot be set to anything — the form shows it, a bulk
             * change cannot offer it. */
            if ($type === 'select' && !is_array($options)) {
                continue;
            }

            $changeable[] = array(
                'property'  => $field['property'],
                'label'     => $field['label'],
                'type'      => $type,
                'options'   => is_array($options) ? self::labelled($options) : null,
                'maxlength' => is_int($maxlength) ? $maxlength : null,
            );
        }

        return $changeable;
    }

    /**
     * A core `options` list as a control can render it.
     *
     * The core states them as a plain list of strings; the admin needs a value and a label, and
     * for a fixed choice those are the same string. Kept here rather than in the template so the
     * shape matches the one `RelationOptions` answers with — one shape, two sources.
     *
     * @param list<mixed> $options
     *
     * @return list<array{value: string, label: string}>
     */
    private static function labelled(array $options): array
    {
        $labelled = array();

        foreach ($options as $option) {
            if (is_scalar($option)) {
                $labelled[] = array('value' => (string) $option, 'label' => (string) $option);
            }
        }

        return $labelled;
    }

    /**
     * Whether the entity has anything the core's full-text search would look at.
     *
     * OFFERED ONLY WHERE IT CAN WORK. `where['fulltext']` searches the text properties of the
     * entity and, besides that, matches an exactly given id (`Api.php:1444`). On an entity without
     * a single text property the box would therefore be an id lookup wearing the label "search" —
     * it would answer "nothing found" for every word anyone could reasonably type. The same
     * stance `RelationOptions` takes with a target it cannot read: show nothing rather than offer
     * something that cannot deliver.
     *
     * A `secret` property does not count, because the core skips it inside the search as well
     * (`Api.php:1459`) — counting it would be how an entity made entirely of secrets gets a
     * search box.
     *
     * @param string $entity Entity name as the schema spells it
     */
    public function isSearchable(string $entity): bool
    {
        $properties = $this->schemaClient->getSchema()[$entity]['properties'] ?? array();

        foreach ($properties as $meta) {
            if (!is_array($meta) || ($meta['secret'] ?? false) === true) {
                continue;
            }

            if (in_array($meta['type'] ?? null, self::FULLTEXT_TYPES, true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether this entity's records form a tree and are therefore edited as one (story 004-001).
     *
     * THE SCHEMA ANSWERS THIS, NOT A `class_exists()` CHECK. The core sets
     * `settings.type` to `tree` for everything descending from `BaseTree` or `BaseI18nTree`
     * (`Api::getSchema()`), so the question is already answered for every entity the admin can
     * see — including the ones a project adds. Resolving the entity to a class and asking PHP
     * about its parents would be a second opinion about the same fact, and it would be the
     * weaker one: the admin reads the schema, not the project's autoloader.
     *
     * IT LIVES HERE BECAUSE THREE CALLERS ASK IT. `MenuBuilder` decides whether an entry points
     * at the tree or the list, `TreeController` refuses an entity that is not a tree, and
     * `ListController` offers the switch back. Written three times, those are three chances to
     * disagree about what a tree is.
     *
     * An unknown entity is not a tree. That is the same answer the whitelist and the permission
     * check give it, and it keeps the callers from needing a third branch.
     *
     * @param string $entity Entity name as the schema spells it, e.g. `Harness\FeldtypBaum`
     */
    public function isTree(string $entity): bool
    {
        $settings = $this->schemaClient->getSchema()[$entity]['settings'] ?? array();

        return is_array($settings) && ($settings['type'] ?? null) === 'tree';
    }

    /**
     * The display name of one entity — the configured one, or the fallback.
     *
     * IT LIVES HERE FOR THE REASON `isTree()` DOES: four callers ask it, the menu and the three
     * headings, and written four times those are four chances to disagree about where an entity's
     * name comes from. Before stage 3 there was no answer at all — the menu showed the raw schema
     * name and the headings did the same (005-005-0004).
     *
     * THE FALLBACK IS THE RAW NAME AND NOT A HUMANISED ONE. That is exactly what those four
     * places showed before, and the design rule is that an admin nobody configured does not
     * change. Humanising belongs to field labels (`ColumnBuilder`), where it always was.
     *
     * @param string      $entity   Entity name as the schema spells it, e.g. `Harness\Feldtyp`
     * @param string|null $fallback What to show when nothing is configured; the entity name
     *                              itself when absent. The file manager passes its own, because
     *                              it never showed the raw name (ADR 0020).
     */
    public function label(string $entity, ?string $fallback = null): string
    {
        $settings = $this->schemaClient->getSchema()[$entity]['settings'] ?? array();
        $stated   = is_array($settings)
            ? ($settings[ShapingListener::SCHEMA_KEY]['label'] ?? null)
            : null;

        if (is_string($stated) && $stated !== '') {
            return $stated;
        }

        return $fallback ?? $entity;
    }

    /**
     * The configured tab order of one entity — tab name to its field names, both in order.
     *
     * EMPTY MEANS "NOT STATED", and then the form orders as it always did: the default group
     * first, then every tab in the order its first field appears in the schema, fields inside a
     * tab by `position`. The configuration replaces that order; it does not extend it.
     *
     * The grouping itself does NOT come from here — that is the per-field `tab` the merge writes,
     * which `formFields()` already carries. This answers only the question `tab` cannot: in which
     * sequence (005-005-0005).
     *
     * @return array<string,list<string>>
     */
    public function tabOrder(string $entity): array
    {
        $settings = $this->schemaClient->getSchema()[$entity]['settings'] ?? array();
        $stated   = is_array($settings)
            ? ($settings[ShapingListener::SCHEMA_KEY]['tabs'] ?? null)
            : null;

        return is_array($stated) ? $stated : array();
    }

    /**
     * Whether one field may be offered as a filter at all — the gates before the type is asked.
     *
     * The order is the order of the reasons: the id is the record's address and already its own
     * route, a field without the core's `isFilterable` was never meant to be filtered, a secret
     * cannot be filtered by anyone, `hide` is a refusal that holds everywhere, and the last two
     * lists are the bookkeeping the admin does not put in front of an editor.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    private static function isFilterable(string $property, $meta): bool
    {
        if ($property === ColumnBuilder::ID_PROPERTY || !is_array($meta)) {
            return false;
        }

        if (($meta['isFilterable'] ?? false) !== true || ($meta['secret'] ?? false) === true) {
            return false;
        }

        if (Shaping::flag($meta, 'hide') === true) {
            return false;
        }

        return !in_array($property, self::RESERVED_WHERE_KEYS, true)
            && !in_array($property, self::INHERITED_RELATIONS, true)
            && !in_array($property, ColumnBuilder::TECHNICAL_PROPERTIES, true);
    }

    /**
     * The editable fields of the entity's form, in schema order.
     *
     * An entity the schema does not know yields an empty list rather than an error, for the same
     * reason `ColumnBuilder::build()` yields the id column: the callers have already checked
     * their input against `AdminManager::getEntities()`.
     *
     * READS `contentflyAdmin`, AS THE LIST DOES. ADR 0013 §4 said stage 1 does not read it; ADR
     * 0015 narrowed that to the `WidgetResolver` and brought `tab`, `readonly` and `hide` here,
     * because they decide the *structure* of the form and retrofitting them would mean cutting
     * the form template twice. ADR 0017 added `label` and `position`, the two attributes that
     * work identically in list and form. `showInList` is the list's alone and `isFilterable`
     * never reaches a form field; `accept` was dropped from the stage altogether.
     *
     * @param string $entity Entity name as the schema spells it
     *
     * @return list<array{property: string, label: string, tab: string|null, widget: array<string,mixed>}>
     */
    public function formFields(string $entity): array
    {
        $properties = $this->schemaClient->getSchema()[$entity]['properties'] ?? array();
        $rows       = array();

        foreach ($properties as $property => $meta) {
            $property = (string) $property;

            if (!self::isEditable($property, $meta)) {
                continue;
            }

            $shaping = Shaping::of($meta);

            /*
             * `hide` DROPS THE FIELD, it does not merely mark it. The attribute says "keep this
             * out of the admin entirely" (`Attribute\Admin`), and a field rendered and then
             * hidden by a stylesheet is still in the page source and still submitted.
             */
            if (($shaping['hide'] ?? null) === true) {
                continue;
            }

            $widget = $this->widgetResolver->resolve($entity, $property, $meta);

            /*
             * THE ATTRIBUTE ONLY EVER ADDS WRITE PROTECTION. A stated `readonly: false` is not
             * honoured here, and that is deliberate: the resolver sets `readonly` for a
             * `virtualjoin`, whose value the core computes, and for a type this package does not
             * know — in both cases the field cannot be written correctly, which no attribute on
             * an entity can change. Lifting that is what a registered project widget is for
             * (ADR 0013), not an annotation.
             */
            if (($shaping['readonly'] ?? null) === true) {
                $widget['readonly'] = true;
            }

            /*
             * `required` CLOSES GAP L2', AND ONLY UPWARDS. Seven of the 21 types carry no
             * `nullable` at all, so `WidgetResolver` cannot tell "not required" from "unknown"
             * and reports neither — the attribute is the only way to mark such a field mandatory
             * (ADR 0017 §5). A stated `required: false` is ignored for the same reason
             * `readonly: false` is: `nullable: false` is the database's own statement, and a form
             * that offered such a field as optional would move the error to the save.
             *
             * It is read here and not in the resolver because ADR 0013 §4, as narrowed by ADR
             * 0015, keeps the resolver away from `contentflyAdmin` altogether — the same reason
             * `rte` sits here.
             */
            if (($shaping['required'] ?? null) === true) {
                $widget['required'] = true;
            }

            /*
             * `rte` CHANGES THE ELEMENT, AND ONLY FOR A `textarea`. ADR 0016 §3 put the reading
             * of it here rather than in the resolver, in the line of ADR 0015: the resolver
             * answers stage 1, from the mapping alone, and the mapping knows nothing about rich
             * text. The guard on the alias is not politeness — a `string` or a `json` column has
             * no room for markup, and a rich text control over one would produce HTML that the
             * field cannot hold.
             *
             * A REGISTERED PROJECT WIDGET BEATS IT (ADR 0027 §4). Both statements come from the
             * project, so one of them has to give way, and `rte: true` is the shorthand while a
             * registration names the element outright. Without this guard the registry could not
             * reach a single `textarea` field carrying the attribute — which is the long-text
             * field with a special editor, one of the most common own widgets there is.
             */
            if (($shaping['rte'] ?? null) === true
                && ($widget['type'] ?? null) === RichText::TYPE
                && ($widget['overridden'] ?? false) !== true) {
                $widget['widget'] = RichText::WIDGET;
            }

            $tab = $shaping['tab'] ?? null;

            $rows[] = array(
                'position' => Shaping::position($meta),
                'field'    => array(
                    'property' => $property,
                    'label'    => Shaping::label($meta) ?? ColumnBuilder::humanise($property),
                    'tab'      => is_string($tab) && $tab !== '' ? $tab : null,
                    'widget'   => $widget,
                ),
            );
        }

        return self::byPosition($rows);
    }

    /**
     * The fields ordered by `position` — within their tab, never across tabs.
     *
     * THE TAB ORDER IS NOT THIS ATTRIBUTE'S BUSINESS. `FormController::tabs()` orders the tabs
     * themselves by where their first field appears in the schema, and ordering by `position`
     * across the whole form would change that as a side effect: a late field pulled to the front
     * would drag its whole tab with it, and renaming nothing would have reordered the bar. So the
     * tab a field belongs to is the first sort key, in first-appearance order, which leaves
     * `tabs()` producing exactly the grouping it produced before — only the fields inside each
     * group move.
     *
     * `usort()` has been stable since PHP 8.0, which is what makes the schema order the tiebreaker
     * for equal and absent positions (ADR 0017) without carrying an index around.
     *
     * @param list<array{position: int|null, field: array<string,mixed>}> $rows
     *
     * @return list<array<string,mixed>>
     */
    private static function byPosition(array $rows): array
    {
        $tabOrder = array();

        foreach ($rows as $row) {
            $tab = $row['field']['tab'] ?? '';

            if (!array_key_exists($tab, $tabOrder)) {
                $tabOrder[$tab] = count($tabOrder);
            }
        }

        usort($rows, static function (array $a, array $b) use ($tabOrder): int {
            $tabA = $tabOrder[$a['field']['tab'] ?? ''];
            $tabB = $tabOrder[$b['field']['tab'] ?? ''];

            if ($tabA !== $tabB) {
                return $tabA <=> $tabB;
            }

            return Shaping::comparePosition($a['position'], $b['position']);
        });

        return array_column($rows, 'field');
    }

    /**
     * Whether one field of the schema is offered for editing at all.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    private static function isEditable(string $property, $meta): bool
    {
        if ($property === ColumnBuilder::ID_PROPERTY) {
            return false;
        }

        if (in_array($property, ColumnBuilder::TECHNICAL_PROPERTIES, true)) {
            return false;
        }

        if (in_array($property, self::INHERITED_RELATIONS, true)) {
            return false;
        }

        return is_array($meta) && ($meta['secret'] ?? false) !== true;
    }
}
