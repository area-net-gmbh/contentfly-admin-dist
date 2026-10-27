<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

/**
 * Which input element one field is edited with, inferred from the schema alone.
 *
 * THIS IS STAGE 1 OF THE SHAPING CASCADE for the form, the counterpart of `ColumnBuilder` for
 * the list: it runs when nobody has configured anything. A short text becomes a text input
 * carrying the length the column allows, a long one a textarea, a boolean a checkbox, a relation
 * a picker. The result is plain — technical labels, schema order, no tabs — and that is the
 * accepted price of a form that exists without a line of setup. The attribute-driven stage 2 is
 * story 002-008; this class does not read `contentflyAdmin` at all.
 *
 * THE WIDGET KEY NAMES THE INPUT ELEMENT, NOT THE STORAGE TYPE. 16 keys cover the core's 21
 * types, and the mapping is fixed in ADR 0013. Two consequences are worth stating here, because
 * they look like mistakes otherwise. `integer`, `decimal` and `float` all resolve to `number` —
 * what differs between them is carried in `type`, which every definition passes through, so a
 * template can still tell them apart. And the core's `boolean` (one checkbox) and the core's
 * `checkbox` (a group over a relation) resolve to *different* widgets, `boolean` and
 * `checkboxgroup`: the two type names are near-identical and mean nothing alike.
 *
 * THE RESOLUTION IS A CHAIN AND THE FIRST MATCH WINS: an override for one concrete field, an
 * override for a field type, the inference below, the fallback. That order is fixed in ADR 0013
 * and mirrors how the core's `TypeManager` resolves. The first two links are the `WidgetRegistry`,
 * which this class asks before it infers anything; it is empty until a project fills it in
 * story 005-002.
 *
 * THE TYPES ARE AN ALLOWLIST, as in `ColumnBuilder`. The core registers 21 types and a plugin may
 * add a 22nd (`Api.php:1872-1879`); an unknown type therefore resolves to `readonly` — the value
 * is shown, not offered for editing. A text input over it would be the obvious alternative and is
 * the dangerous one: we would not know what shape the core expects back, so saving could deform
 * the value. Showing it risks nothing.
 *
 * FOUR TRAPS OF THE SCHEMA ARE HANDLED HERE DELIBERATELY, all of them documented in
 * `an_project/docs/schema-vertrag.md`:
 *
 * - Seven types carry no `nullable` at all (gap L2'), so a required field is only ever reported
 *   when the schema says `nullable: false`. It is never inferred from the absence of the key.
 *   Those seven are reachable through the entity's own `#[Admin(required: true)]`, which
 *   `EntityView` lays over this definition — not here, because §4 below keeps this class away
 *   from `contentflyAdmin`.
 * - `file` and `join` are not disjoint (gap L7) — both arise from `ManyToOne`. They are told
 *   apart by the type alias, never by the mapping.
 * - `checkbox` and `radio` deliver a `group` id and no option list (gap L8). The group is passed
 *   through; no options are invented for them.
 * - `joinbidirectional` names its target `targetEntity` instead of `accept` (gap L9).
 *
 * And one more that is not a gap but a convention: on `file` and `multifile` the `accept` key is
 * the wildcard `*`, not the target class (`Types/FileType.php`), because the target is `PIM\File`
 * by definition. It is taken from `FILE_ENTITY` rather than read out of the schema.
 */
final class WidgetResolver
{
    /** Where the resolver puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.widget_resolver';

    /** What an unknown field type resolves to: shown, never offered for editing. */
    public const FALLBACK_WIDGET = 'readonly';

    /** The target of `file` and `multifile`, whose `accept` is the wildcard `*`. */
    public const FILE_ENTITY = 'PIM\\File';

    /**
     * The core's 21 type aliases mapped onto the admin's widget keys.
     *
     * The aliases are the core's own spelling from `Config::$APP_SYSTEM_TYPES` — lower case
     * throughout, `joinbidirectional` and not `joinBidirectional`. The order is the one of
     * `an_project/docs/schema-vertrag.md` section 2.2, so the two tables can be read side by
     * side. `permissions` and `i18npermissions` occur on `PIM\Group` only and are secondary for
     * a project entity's form; they are mapped so that no type is left without an answer.
     *
     * @var array<string,string>
     */
    private const WIDGETS = array(
        'boolean'           => 'boolean',
        'integer'           => 'number',
        'decimal'           => 'number',
        'float'             => 'number',
        'string'            => 'text',
        'textarea'          => 'textarea',
        'json'              => 'json',
        'datetime'          => 'datetime',
        'time'              => 'time',
        'select'            => 'select',
        'join'              => 'entity',
        'radio'             => 'radio',
        'virtualjoin'       => 'entitylist',
        'i18npermissions'   => 'permissionmatrix',
        'file'              => 'file',
        'multifile'         => 'filelist',
        'multijoin'         => 'entitylist',
        'onejoin'           => 'entity',
        'checkbox'          => 'checkboxgroup',
        'joinbidirectional' => 'entitylist',
        'permissions'       => 'permissionmatrix',
    );

    /** The widgets that carry a `group` id instead of an option list. */
    private const GROUPED_WIDGETS = array('radio', 'checkboxgroup');

    /** The one type whose value the core computes; it can be shown but never written. */
    private const COMPUTED_TYPE = 'virtualjoin';

    private WidgetRegistry $registry;

    public function __construct(WidgetRegistry $registry)
    {
        $this->registry = $registry;
    }

    /**
     * The widget definition for one field of the schema.
     *
     * The field's schema entry is passed in rather than fetched: whoever walks an entity's
     * properties has it already, and a second reader of the schema is how a form and a list end
     * up disagreeing about a field.
     *
     * Every definition carries `widget`, `type`, `required`, `readonly`, `default` and
     * `overridden`. The last one says whether the element came from the registry rather than from
     * the mapping, and it exists because `EntityView` has to know: the `rte` attribute changes the
     * element too, and a registered project widget beats it (ADR 0027 §4). The keys
     * that only some widgets need are present only where they apply: `maxlength`, `options`,
     * `group`, `target`, `sortable` and `through`. A template therefore never has to tell
     * "absent" from "empty".
     *
     * @param string                    $entity   Entity name as the schema spells it
     * @param string                    $property The property name
     * @param array<string,mixed>|mixed $meta     The field's schema entry
     *
     * @return array{widget: string, type: string|null, required: bool, readonly: bool, default: mixed, overridden: bool, maxlength?: int, options?: array<int,mixed>, group?: string, target?: string, sortable?: bool, through?: string}
     */
    public function resolve(string $entity, string $property, $meta): array
    {
        $meta = is_array($meta) ? $meta : array();
        $type = $meta['type'] ?? null;
        $type = is_string($type) ? $type : null;

        $override = $this->registry->find($entity, $property, $type);

        if ($type === null || !isset(self::WIDGETS[$type])) {
            return self::fallback($type, $override);
        }

        /*
         * The override replaces the element, never the facts about the field. `maxlength`,
         * `options` and `group` therefore follow the INFERRED widget: a project that puts its
         * own text widget on a `string(50)` still gets the 50 passed to it.
         */
        $widget = self::WIDGETS[$type];

        $definition = array(
            'widget'     => $override ?? $widget,
            'type'       => $type,
            'required'   => ($meta['nullable'] ?? null) === false,
            'readonly'   => $type === self::COMPUTED_TYPE,
            'default'    => $meta['default'] ?? null,
            'overridden' => $override !== null,
        );

        $length = $meta['length'] ?? null;
        if ($widget === 'text' && is_numeric($length) && (int) $length > 0) {
            $definition['maxlength'] = (int) $length;
        }

        $options = $meta['options'] ?? null;
        if ($widget === 'select' && is_array($options)) {
            $definition['options'] = array_values($options);
        }

        $group = $meta['group'] ?? null;
        if (in_array($widget, self::GROUPED_WIDGETS, true) && is_string($group) && $group !== '') {
            $definition['group'] = $group;
        }

        /*
         * `sortable` TRAVELS FOR THE MULTI-IMAGE WIDGET ALONE, and it is the first fact this
         * class passes on that is about *storage* rather than about the element. It has to: a
         * `multifile` can hold an order only in the `OneToMany` + `#[PIM\ManyToMany]` form with
         * a `BaseSortable` target, and in the ordinary `#[ORM\ManyToMany]` form the core answers
         * `false` (gap L12). The widget offers a drag handle only where the answer is `true`,
         * because a handle on the other form would promise a sequence that is gone after the save
         * (ADR 0023). Always present for this widget, so the template reads a boolean and not an
         * absence.
         */
        if ($widget === 'filelist') {
            $definition['sortable'] = ($meta['sortable'] ?? null) === true;

            /*
             * `through` IS WHAT MAKES THE SORTABLE FORM READABLE AT ALL, and it is a measured
             * necessity rather than a convenience. In that form the core answers the field with
             * the **in-between** records — the `BaseSortable` rows — and their `id` is the id of
             * such a row, not of the file. The file sits one level in, under the property the
             * schema names in `mappedBy`. Reading `id` there would hand the form the wrong ids,
             * and writing them back finds no file for them: measured against the running harness,
             * a second save of an unchanged form empties the whole collection. So the property
             * name travels with the definition, and the form dereferences it.
             */
            $through = $meta['mappedBy'] ?? null;

            if ($definition['sortable'] && is_string($through) && $through !== '') {
                $definition['through'] = $through;
            }
        }

        $target = self::target($type, $meta);
        if ($target !== null) {
            $definition['target'] = $target;
        }

        return $definition;
    }

    /**
     * The entity a relation field points at, or `null` for a field that is not a relation.
     *
     * `treeParent` NEEDS NO SPECIAL CASE HERE, AND FINDING THAT OUT COST A DETOUR WORTH
     * RECORDING. The attribute on `BaseTree::$treeParent` names the abstract base
     * (`targetEntity: 'Areanet\PIM\Entity\BaseTree'`), and `pim_tree` really is the single
     * JOINED table of every tree entity — so story 004-001-0004 first built a substitution here
     * that read such a target as the owning entity. It was dead code: `Api::getSchema()`
     * overwrites `accept` for exactly this property with the concrete class name
     * (`if($prop->getName() == 'treeParent')`), so the schema already answers
     * `Custom\Entity\Harness\FeldtypBaum` and never the base. Measured, not assumed — the
     * substitution was removed when a sabotage run left the gate green.
     *
     * What the schema cannot answer is which *record* is being edited, and that is what a cycle
     * depends on. The exclusion of a node's own subtree therefore lives where the record is
     * known — `FormController::prepare()` and `OptionsController` — and not here.
     *
     * @param array<string,mixed> $meta
     */
    private static function target(string $type, array $meta): ?string
    {
        if ($type === 'file' || $type === 'multifile') {
            return self::FILE_ENTITY;
        }

        $key    = $type === 'joinbidirectional' ? 'targetEntity' : 'accept';
        $target = $meta[$key] ?? null;

        if (!is_string($target) || $target === '' || $target === '*') {
            return null;
        }

        return $target;
    }

    /**
     * The definition for a field this package cannot edit by itself.
     *
     * `required` stays false and `default` stays empty on purpose: for a type we do not know,
     * both would be guesses, and a guess here is indistinguishable from knowledge later.
     *
     * AN OVERRIDE ALSO LIFTS THE `readonly` HERE. The flag says "we do not know how to edit
     * this", and a project that registers a widget for exactly this type says it does — leaving
     * the field read-only would ignore the registration while appearing to honour it. The one
     * case an override cannot lift is `virtualjoin` above, where the core computes the value and
     * no widget changes that.
     *
     * @return array{widget: string, type: string|null, required: bool, readonly: bool, default: mixed, overridden: bool}
     */
    private static function fallback(?string $type, ?string $override): array
    {
        return array(
            'widget'     => $override ?? self::FALLBACK_WIDGET,
            'type'       => $type,
            'required'   => false,
            'readonly'   => $override === null,
            'default'    => null,
            'overridden' => $override !== null,
        );
    }
}
