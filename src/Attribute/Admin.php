<?php

namespace Areanet\ContentflyAdmin\Attribute;

use Attribute;

/**
 * Shapes how one property is presented in the admin — stage 2 of the three-stage cascade.
 *
 * Stage 1 infers everything from the Doctrine mapping and needs no attribute at all; the result
 * is usable but plain, with technical field names and arbitrary order. This attribute is the
 * jump from "usable" to "good", and it costs minutes per entity.
 *
 * WHY THIS LIVES IN THE ADMIN PACKAGE AND NOT IN THE CORE: the concept assumed the core already
 * carried `label`, `showInList`, `readonly`, `tab` and `hide`. It does not — of the seven
 * attributes it claimed, exactly one exists (`isFilterable`). Changing the core was not an
 * option, so the package brings its own metadata and lifts it into the schema through the
 * extension point the core provides for precisely this: `MetadataReader::forProperty()` reads
 * **every** attribute by reflection and deliberately passes foreign ones through, and `Api.php`
 * dispatches each of them as `pim.schema.after.propertyAnnotation`. See ADR 0008.
 *
 * EVERY ARGUMENT IS THREE-STATE AND DEFAULTS TO NULL — null means "not stated", and only stated
 * values reach the schema. This is load-bearing, not tidiness. A `required` defaulting to false
 * would silently override stage 1, which derives a mandatory field from `nullable: false`; a
 * `showInList` defaulting to false would drop a field from the list the moment any other
 * argument was set on it. Setting one argument must never change the meaning of another.
 *
 * The arguments are typed, unlike the core's annotations. The core leaves its parameters untyped
 * so that a type is not a new restriction on projects that already use them; a newly introduced
 * attribute has no existing users, and this package requires PHP 8.3 regardless. A typo such as
 * `#[Admin(position: 'first')]` therefore fails loudly where it is written, instead of putting a
 * nonsense value into the schema for the frontend to misread.
 *
 * WHERE THE VALUES END UP: `ShapingListener` collects the stated arguments and returns them
 * under the single field key `contentflyAdmin`, so a shaped field reads
 * `properties.<field>.contentflyAdmin.label` in `GET /api/schema`. One key rather than seven
 * flat ones, because the core's accumulation turns every returned top-level key into a
 * pseudo property name — flat, a field actually named `label` would abort schema generation.
 * ADR 0008 carries the full reasoning. The consumers are the list view (`showInList`,
 * `position`, `label`), the form view (`tab`, `position`, `label`, `readonly`, `required`) and
 * both (`hide`).
 *
 * Property level only. Entity-level shaping would go through the core's class-level twin event
 * and is not part of this decision.
 */
#[Attribute(Attribute::TARGET_PROPERTY)]
final class Admin
{
    /**
     * @param string|null $label      Human label for the field. Null falls back to the humanised
     *                                property name, which is what stage 1 does.
     * @param bool|null   $showInList Whether the field becomes a column in the list view. Null
     *                                leaves the stage-1 fallback in charge: the first three to
     *                                five scalar fields plus the id.
     * @param int|null    $position   Sort key for field order, ascending, in list and form alike.
     *                                Gaps are intended — leave room to insert later. Null means
     *                                the field keeps the order the schema delivers it in.
     * @param string|null $tab        Name of the form tab this field belongs to. Fields without a
     *                                tab are collected into one default tab, which comes first;
     *                                an entity that states no tab at all gets a form without a
     *                                tab bar (`Controller\FormController`, story 002-006-0003).
     * @param bool|null   $readonly   Show the value, do not let it be edited.
     * @param bool|null   $hide       Keep the field out of the admin entirely. It stays in the
     *                                API and in the database; this hides it from the interface.
     * @param bool|null   $required   Declares the field mandatory. The escape hatch for gap L2′:
     *                                seven field types carry no `nullable` in the schema at all,
     *                                so for those the admin cannot tell "optional" from
     *                                "unknown". Null leaves the mapping in charge.
     * @param bool|null   $rte        Edits the field as rich text — bold, italic, paragraphs,
     *                                lists, links — instead of as plain multi-line text. Only
     *                                has an effect on a `textarea` field (a `text` column); on
     *                                anything else it is ignored, because the other types have
     *                                no room for markup. ADR 0016 §3 records why this is an
     *                                attribute at all: rich text is a stage-2 case, "no code,
     *                                just attributes on the entity", and the core has no type
     *                                for it. Whatever an editor produces is reduced on the
     *                                server to the elements above (`View\RichText`) — the
     *                                attribute asks for a different control, never for
     *                                permission to store arbitrary markup.
     */
    public function __construct(
        public readonly ?string $label = null,
        public readonly ?bool $showInList = null,
        public readonly ?int $position = null,
        public readonly ?string $tab = null,
        public readonly ?bool $readonly = null,
        public readonly ?bool $hide = null,
        public readonly ?bool $required = null,
        public readonly ?bool $rte = null,
    ) {
    }
}
