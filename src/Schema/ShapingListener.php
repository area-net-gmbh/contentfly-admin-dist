<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Schema;

use Areanet\ContentflyAdmin\Attribute\Admin;
use Areanet\PIM\Classes\Event;

/**
 * Lifts the package's own `#[Admin]` metadata into the core's field schema — stage 2 of the
 * cascade (ADR 0008).
 *
 * The core dispatches `pim.schema.after.propertyAnnotation` once per property attribute and
 * merges whatever a listener leaves under `properties` into that field's schema. This listener
 * is the only thing standing between the attribute and the schema; the core is not touched.
 *
 * THE CORE'S ACCUMULATION IS FRAGILE, AND THIS CLASS IS WRITTEN AROUND IT RATHER THAN TRUSTING
 * IT. In `Api.php`, `$customProperties` starts out as a map over all properties of the entity,
 * is then overwritten with the event's flat return value, and is finally nested under the
 * property name inside itself. Three consequences this listener has to live with:
 *
 * 1. **Return the complete array, never only your own contribution.** The core replaces the
 *    value, it does not merge. Anything a previous listener or a previous attribute of the same
 *    property contributed is lost if it is not carried along.
 * 2. **An empty return value is discarded** — the core tests the return for truthiness. An
 *    attribute can therefore set a value but never clear one. That is a property of the core,
 *    not a decision of this package.
 * 3. **Leave `properties` untouched for a foreign annotation.** Returning without calling
 *    `setParam()` is deliberate and load-bearing: `getParam()` then yields exactly what the core
 *    pre-set, so an `#[Admin]` that was processed earlier in the same property survives a later
 *    `#[ORM\Column]`. Writing an empty array here instead would wipe it.
 *
 * What saves this from being dangerous is that the core consumes each property's value inside
 * the same loop iteration that produced it: the merge sits in the property loop, after the
 * annotation loop closes. The clobbering therefore cannot reach across properties.
 *
 * HOW THIS FAILS SILENTLY, so nobody hunts for it in the wrong place: the core skips an
 * attribute whose class cannot be loaded (`MetadataReader::instantiate()`), deliberately, so
 * that a package installed only in development does not bring down schema generation. If this
 * package is not autoloadable in a given run, the shaping is simply absent — no error, no
 * warning, just unshaped fields.
 *
 * WHY EVERYTHING GOES UNDER ONE KEY: every top-level key a listener returns also lands in the
 * core's map as a pseudo property name. Flat keys would mean that an entity with a field
 * actually called `label` makes the core merge an array with a string and abort schema
 * generation for the whole project. Under a single key the worst case is stray data on one
 * oddly-named field.
 */
final class ShapingListener
{
    /** The core event this listener is registered for. */
    public const EVENT = 'pim.schema.after.propertyAnnotation';

    /** The one field-schema key everything this package contributes lives under. */
    public const SCHEMA_KEY = 'contentflyAdmin';

    public function __invoke(Event $event): void
    {
        $annotation = $event->getParam('propertyAnnotation');

        if (!$annotation instanceof Admin) {
            return;
        }

        $shaping = self::stated($annotation);

        if ($shaping === []) {
            return;
        }

        $properties = $event->getParam('properties');
        $properties = is_array($properties) ? $properties : [];

        $carried = $properties[self::SCHEMA_KEY] ?? [];
        $properties[self::SCHEMA_KEY] = array_merge(is_array($carried) ? $carried : [], $shaping);

        $event->setParam('properties', $properties);
    }

    /**
     * The arguments the entity actually stated, in schema form.
     *
     * Null means "not stated" and must not reach the schema: a `required` of null has to leave
     * stage 1's inference from `nullable: false` in charge, and a `showInList` of null has to
     * leave the list's own column fallback in charge. `false` and `0` are stated values and
     * survive — hence the explicit null test instead of a bare `array_filter`.
     *
     * @return array<string,string|int|bool>
     */
    private static function stated(Admin $annotation): array
    {
        $alle = [
            'label'      => $annotation->label,
            'showInList' => $annotation->showInList,
            'position'   => $annotation->position,
            'tab'        => $annotation->tab,
            'readonly'   => $annotation->readonly,
            'hide'       => $annotation->hide,
            'required'   => $annotation->required,
            'rte'        => $annotation->rte,
        ];

        return array_filter($alle, static fn ($wert): bool => $wert !== null);
    }
}
