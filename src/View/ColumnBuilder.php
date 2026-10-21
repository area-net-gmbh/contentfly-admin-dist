<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\ContentflyAdmin\Schema\SchemaClient;

/**
 * The columns of one entity's list view, derived from the schema alone.
 *
 * STAGE 1 IS THE FALLBACK, STAGE 2 SITS ON TOP OF IT. Stage 1 is what runs when nobody has
 * configured anything: the id, then the first few scalar fields, labelled with their humanised
 * property name, in whatever order the schema delivers. It is deliberately plain — that is the
 * accepted price of a list that exists without a line of setup. Stage 2 layers the entity's own
 * attributes over it (ADR 0017), all read through `Shaping`: `label` replaces the humanised name,
 * `position` replaces the schema order, and `showInList` replaces the selection itself.
 *
 * A STATED SELECTION REPLACES THE AUTOMATIC ONE, IT DOES NOT EXTEND IT. One `showInList: true`
 * anywhere on the entity switches the automatic fill off entirely, so the list shows exactly the
 * fields that asked to be there (ADR 0017 §1). Extending instead would put three chosen columns
 * next to five guessed ones, and nothing in the table says which is which. An entity that only
 * states `showInList: false` keeps the automatic fill for its remaining fields — it has excluded
 * something, not answered the question.
 *
 * TWO GATES NO ATTRIBUTE REACHES, and the split between `isRenderable()` and `isListable()` is
 * where that lives. `secret` is the obvious one. The type allowlist is the other and it is just
 * as hard: `{{ row.cells[column.property] }}` in the template would print `Array` for a relation,
 * so "the entity asked for it" cannot make a relation into a table cell. Both are the
 * Core-Wahrheit that ADR 0017 keeps stage 2 away from — in contrast to the bookkeeping-field
 * filter, which is a stage 1 *guess* about relevance and therefore does give way to an explicit
 * `showInList: true`.
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
     * How many scalar fields the *automatic* selection puts after the id at most.
     *
     * A constant and not an option: zero configuration is the hard design rule of this package,
     * and a list that needs tuning before it is readable has already lost the projects it is
     * for. Five is the upper end of the "three to five" the concept names.
     *
     * It bounds stage 1 and nothing else (ADR 0017 §1). An entity that states `showInList: true`
     * is not counted against it and not cut off by it — the cap exists to keep an *unconfigured*
     * list readable, and holding it against a project that has said what it wants would make the
     * attribute useless exactly where it matters, on an entity with many fields.
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
     * @return list<array{property: string, label: string, filterable: bool}>
     */
    public function build(string $entity): array
    {
        $properties = $this->schemaClient->getSchema()[$entity]['properties'] ?? array();

        $selected = array();

        foreach ($properties as $property => $meta) {
            if ($this->isRenderable((string) $property, $meta)
                && Shaping::flag($meta, 'showInList') === true
            ) {
                $selected[] = array('property' => (string) $property, 'meta' => $meta);
            }
        }

        /*
         * THE AUTOMATIC FILL ONLY RUNS WHEN NOTHING WAS CHOSEN. This is the one place the "stated
         * selection replaces the automatic one" rule is implemented, and it is an `if` and not a
         * subtraction on purpose: the cap counts stage 1's own guesses, so it must never see a
         * field that asked for its column.
         */
        if ($selected === array()) {
            foreach ($properties as $property => $meta) {
                if (count($selected) >= self::MAX_FIELDS) {
                    break;
                }

                if ($this->isListable((string) $property, $meta)) {
                    $selected[] = array('property' => (string) $property, 'meta' => $meta);
                }
            }
        }

        /*
         * THE CAP IS APPLIED BEFORE THE SORT, AND THAT ORDER IS THE POINT. `position` answers
         * "in which order", `showInList` answers "which at all" (ADR 0017) — sorting first would
         * quietly let `position` decide the selection too, and a field with `position: 1` sitting
         * past the cap would pull itself into the list without ever saying it wanted to be there.
         */
        usort($selected, static function (array $a, array $b): int {
            return Shaping::comparePosition(
                Shaping::position($a['meta']),
                Shaping::position($b['meta'])
            );
        });

        $columns = array($this->column(self::ID_PROPERTY, $properties[self::ID_PROPERTY] ?? null));

        foreach ($selected as $field) {
            $columns[] = $this->column($field['property'], $field['meta']);
        }

        return $columns;
    }

    /**
     * Whether one field *can* be a column at all — the gates no attribute overrides.
     *
     * `hide` and `showInList: false` are in here rather than in `isListable()` because they are
     * refusals, and a refusal has to hold on the explicit path too: `#[Admin(hide: true, …)]`
     * means "keep this out of the admin entirely" (`Attribute\Admin`), so it cannot be undone by
     * the same attribute's `showInList`. Closing that is what 002-008-0003 was for — `hide` used
     * to be read by the form alone, which left a field the form refuses to render able to become
     * a list column.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    private function isRenderable(string $property, $meta): bool
    {
        if ($property === self::ID_PROPERTY) {
            return false;
        }

        if (!is_array($meta) || ($meta['secret'] ?? false) === true) {
            return false;
        }

        $type = $meta['type'] ?? null;

        if (!is_string($type) || !in_array($type, self::LISTABLE_TYPES, true)) {
            return false;
        }

        if (Shaping::flag($meta, 'hide') === true) {
            return false;
        }

        return Shaping::flag($meta, 'showInList') !== false;
    }

    /**
     * Whether one field earns a column from stage 1 alone, without the entity saying anything.
     *
     * Everything `isRenderable()` refuses, plus the bookkeeping fields — the one filter here that
     * is a guess and not a rule, which is why an explicit `showInList: true` gets past it.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    private function isListable(string $property, $meta): bool
    {
        return $this->isRenderable($property, $meta)
            && !in_array($property, self::TECHNICAL_PROPERTIES, true);
    }

    /**
     * One column, labelled by the entity's own `label` where it states one.
     *
     * The id gets the same treatment as every other column although it never passes through
     * `isListable()`. Nobody is likely to label it, but a column that silently ignores an
     * attribute the next column honours is the kind of exception that costs an afternoon.
     *
     * `filterable` IS CARRIED WITHOUT A CONSUMER, ON PURPOSE (ADR 0017 §3). `isFilterable` is a
     * core key present on every field (`an_project/docs/schema-vertrag.md` §1.3), and the filter
     * interface that reads it is story 002-004. It is set here and not there because this list is
     * the single definition of what a column *is* — adding the flag later would mean reopening
     * this method and its two callers for a one-line copy.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry, or null when it has none
     *
     * @return array{property: string, label: string, filterable: bool}
     */
    private function column(string $property, $meta = null): array
    {
        return array(
            'property'   => $property,
            'label'      => Shaping::label($meta) ?? self::humanise($property),
            'filterable' => is_array($meta) && ($meta['isFilterable'] ?? false) === true,
        );
    }

    /**
     * A property name turned into something a person can read: `seoTitel` → `Seo Titel`.
     *
     * THE CORE SHIPS NO FIELD LABEL. None of the 21 type classes writes a `label` key — gap L1
     * in `an_project/docs/schema-vertrag.md` — so there is nothing in the core's own schema to
     * read and the name itself is all there is to work with. This is the fallback now, not the
     * only answer: a real label arrives through this package's `#[Admin(label: …)]`, read in
     * `column()` via `Shaping::label()`.
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
