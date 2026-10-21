<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\ContentflyAdmin\Schema\SchemaClient;

/**
 * The columns of one entity's list view, derived from the schema alone.
 *
 * THIS IS STAGE 1 OF THE SHAPING CASCADE, the one that runs when nobody has configured
 * anything: the id, then the first few scalar fields, labelled with their humanised property
 * name. It is deliberately plain — the field order is whatever the schema delivers, the labels
 * are technical, and a long text lands in a table cell. That is the accepted price of a list
 * that exists without a line of setup, and the jump to "good" is the attribute-driven stage 2
 * (story 002-008), which will layer `showInList`, `label` and `position` on top of this.
 *
 * IT ANSWERS BOTH QUESTIONS THE LIST ASKS AT ONCE — which columns to show and which fields to
 * request from the core. The caller builds `$properties` for `Api::getList()` out of the same
 * result (`array_column($columns, 'property')`), so a column can never appear in the header
 * without its value being fetched.
 *
 * THE TYPES ARE AN ALLOWLIST, NOT AN EXCLUSION LIST, and that is the one decision here worth
 * defending. The core registers 21 field types and a plugin may add a 22nd (`Api.php:1872-1879`);
 * with an exclusion list that unknown type would silently become a column and a renderer would
 * meet a value it has no idea how to print. An allowlist leaves it out instead, which is the
 * failure everyone can live with. `json` is excluded for the same reason — it is a structure,
 * not a scalar, whatever its storage looks like.
 *
 * WHY THE BOOKKEEPING FIELDS ARE SKIPPED. Every entity inherits `created`, `modified`, `views`
 * and `isIntern` from the core's `Base`, plus `lang` from `BaseI18n`, and the schema does not
 * mark any of them as technical (`an_project/docs/schema-vertrag.md` section 1.4). The schema
 * lists them after the entity's own fields, so on a wide entity they fall outside the limit by
 * themselves — but a narrow one is exactly where that stops being true. `PIM\Tag` has a single
 * field of its own, and without this filter its list would be `id`, `title` and then four
 * columns of timestamps and counters. The point of the fallback is to show what the record *is*.
 *
 * SECRETS NEVER BECOME A COLUMN. The core flags them itself — `pass` and `salt` on `PIM\User`
 * carry `secret: true` — and the flag is honoured here rather than in the template, because
 * this list also decides which fields are *requested* from the core. A secret that is filtered
 * while rendering has already been read out of the database and into the page's memory; one
 * that never enters this list is never fetched.
 */
final class ColumnBuilder
{
    /** Where the builder puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.column_builder';

    /** The one column every list opens with; it is the record's address and always present. */
    public const ID_PROPERTY = 'id';

    /**
     * How many scalar fields follow the id at most.
     *
     * A constant and not an option: zero configuration is the hard design rule of this package,
     * and a list that needs tuning before it is readable has already lost the projects it is
     * for. Five is the upper end of the "three to five" the concept names.
     */
    public const MAX_FIELDS = 5;

    /**
     * The field types that can be printed as one value in a table cell.
     *
     * The aliases are the core's own, from `Config::$APP_SYSTEM_TYPES`, and they are lower case
     * there — `joinbidirectional`, not `joinBidirectional`. Everything absent from this list is
     * a relation, a collection, a permission matrix or a structure, and none of those has a
     * sensible one-cell representation at stage 1.
     *
     * @var list<string>
     */
    private const LISTABLE_TYPES = array(
        'boolean',
        'integer',
        'decimal',
        'float',
        'string',
        'textarea',
        'datetime',
        'time',
        'select',
    );

    /**
     * Inherited bookkeeping fields that are scalar but say nothing about the record.
     *
     * Only the ones that would otherwise pass `LISTABLE_TYPES` are named. The inherited
     * relations — `user`, `userCreated`, `users`, `groups` — are already out by their type, and
     * repeating them here would be a second list to keep in step with the first. The form has to
     * name them, because it renders relations; they are in `EntityView::INHERITED_RELATIONS`.
     *
     * Public because the form excludes the same fields (`EntityView::formFields()`). One list,
     * two readers — the alternative was a copy that drifts.
     *
     * @var list<string>
     */
    public const TECHNICAL_PROPERTIES = array(
        'created',
        'modified',
        'views',
        'isIntern',
        'lang',
    );

    private SchemaClient $schemaClient;

    public function __construct(SchemaClient $schemaClient)
    {
        $this->schemaClient = $schemaClient;
    }

    /**
     * The columns for one entity, id first.
     *
     * An entity the schema does not know yields the id column on its own rather than an error:
     * the callers have already checked their input against `AdminManager::getEntities()`, whose
     * contents `validateEntities()` checks against the schema, so there is no path here that a
     * throw would catch earlier than those two.
     *
     * @param string $entity Entity name as the schema spells it, e.g. `Harness\Feldtyp`
     *
     * @return list<array{property: string, label: string}>
     */
    public function build(string $entity): array
    {
        $columns = array($this->column(self::ID_PROPERTY));

        $properties = $this->schemaClient->getSchema()[$entity]['properties'] ?? array();

        foreach ($properties as $property => $meta) {
            if (count($columns) > self::MAX_FIELDS) {
                break;
            }

            if ($this->isListable((string) $property, $meta)) {
                $columns[] = $this->column((string) $property);
            }
        }

        return $columns;
    }

    /**
     * Whether one field of the schema earns a column.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    private function isListable(string $property, $meta): bool
    {
        if ($property === self::ID_PROPERTY || in_array($property, self::TECHNICAL_PROPERTIES, true)) {
            return false;
        }

        if (!is_array($meta) || ($meta['secret'] ?? false) === true) {
            return false;
        }

        $type = $meta['type'] ?? null;

        return is_string($type) && in_array($type, self::LISTABLE_TYPES, true);
    }

    /**
     * @return array{property: string, label: string}
     */
    private function column(string $property): array
    {
        return array('property' => $property, 'label' => self::humanise($property));
    }

    /**
     * A property name turned into something a person can read: `seoTitel` → `Seo Titel`.
     *
     * THE CORE SHIPS NO FIELD LABEL. None of the 21 type classes writes a `label` key — gap L1
     * in `an_project/docs/schema-vertrag.md` — so there is nothing to read and the name itself
     * is all there is to work with. Stage 2 is where a real label arrives.
     *
     * The second pattern keeps acronyms together, so `seoURL` becomes `Seo URL` and not
     * `Seo U R L`. Underscores are word breaks too, so `is_intern` and `isIntern` give the
     * same label — a property name should not read differently because of how it was spelled.
     */
    public static function humanise(string $property): string
    {
        $spaced = preg_replace(
            array('/([a-z\d])([A-Z])/', '/([A-Z]+)([A-Z][a-z])/'),
            '$1 $2',
            str_replace('_', ' ', $property)
        );

        return ucwords(trim((string) $spaced));
    }
}
