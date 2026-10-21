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
 * THE FORM IS STAGE 1 TOO: fields in schema order, labels from the humanised property name,
 * widget per field from the `WidgetResolver`. No tabs, no field order, no `hide`, no `readonly`
 * from an attribute — that is the attribute-driven stage 2 (story 002-008), and the form view
 * that renders all of this is story 002-006.
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
     * @param string $entity Entity name as the schema spells it
     *
     * @return list<array{property: string, label: string, widget: array<string,mixed>}>
     */
    public function formFields(string $entity): array
    {
        $properties = $this->schemaClient->getSchema()[$entity]['properties'] ?? array();
        $fields     = array();

        foreach ($properties as $property => $meta) {
            $property = (string) $property;

            if (!self::isEditable($property, $meta)) {
                continue;
            }

            $fields[] = array(
                'property' => $property,
                'label'    => ColumnBuilder::humanise($property),
                'widget'   => $this->widgetResolver->resolve($entity, $property, $meta),
            );
        }

        return $fields;
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
