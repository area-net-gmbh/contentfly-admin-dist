<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\ContentflyAdmin\Schema\SchemaClient;

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
     * @return list<array{property: string, label: string}>
     */
    public function listColumns(string $entity): array
    {
        return $this->columnBuilder->build($entity);
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
             * `rte` CHANGES THE ELEMENT, AND ONLY FOR A `textarea`. ADR 0016 §3 put the reading
             * of it here rather than in the resolver, in the line of ADR 0015: the resolver
             * answers stage 1, from the mapping alone, and the mapping knows nothing about rich
             * text. The guard on the alias is not politeness — a `string` or a `json` column has
             * no room for markup, and a rich text control over one would produce HTML that the
             * field cannot hold.
             */
            if (($shaping['rte'] ?? null) === true && ($widget['type'] ?? null) === RichText::TYPE) {
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
