<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\ContentflyAdmin\Schema\ShapingListener;

/**
 * The one reader of this package's own attributes inside a field's schema entry.
 *
 * STAGE 2 OF THE SHAPING CASCADE HAS TWO READERS — the list (`ColumnBuilder`) and the form
 * (`EntityView`) — and both reach for the same lookup. Two copies of it is how a label starts
 * working in the form and not in the list, so the lookup lives here once and both call it.
 *
 * THE ATTRIBUTE CARRIES EIGHT ARGUMENTS (`Attribute\Admin`): `label`, `showInList`, `position`,
 * `tab`, `readonly`, `hide`, `required` and `rte`. They do not all reach both readers. `label`,
 * `position` and `hide` do; `showInList` is the list's alone; `tab`, `readonly`, `required` and
 * `rte` are the form's. The set is fixed across three decisions and not one, which is why no
 * single ADR enumerates it: ADR 0017 for what stage 2 evaluates in list and form, ADR 0015 for
 * `tab`, `readonly` and `hide` in the form, ADR 0016 §3 for `rte`.
 *
 * `isFilterable` IS NOT ONE OF THEM, although ADR 0017 counts it among the keys stage 2
 * evaluates — which is why this docblock used to name it here. It is a core key set by
 * `#[PIM\Config]`, and it sits on the property level of the schema entry, *outside*
 * `contentflyAdmin`. So `of()` never sees it and nothing in this class can read it; its consumer
 * is `EntityView::listFilters()`, straight off the schema entry (story `002-004`). Measured in
 * `005-001-0001`: a property carries `isFilterable` next to `contentflyAdmin`, never inside it.
 *
 * AN ABSENT KEY MEANS "NOT STATED", NEVER "STATED FALSE". The listener writes only the
 * arguments an entity actually gave (`Schema\ShapingListener`), so `showInList` has three
 * states and not two — show, hide, and "nobody said" — and only the third leaves the stage 1
 * rules in charge. That is why `flag()` returns `?bool` and not `bool`: a caller that cannot
 * tell the three apart would turn every unshaped field into an explicitly excluded one.
 *
 * THE VALUES ARE TYPE-CHECKED, NOT TRUSTED. ADR 0008 names the one case where this entry can
 * hold something else entirely: a field literally called `contentflyAdmin` that directly
 * follows a shaped field carries its predecessor's data as scatter on the top level. It is rare
 * and it is harmless, and it stays harmless precisely because a wrong type here reads as "not
 * stated" rather than being passed on to a template.
 *
 * Static like `ColumnBuilder::humanise()`, for the same reason: the schema entry comes in as an
 * argument, so there is no state to hold and nothing to inject.
 */
final class Shaping
{
    /**
     * What this package's own attributes state about one field, or an empty map.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     *
     * @return array<string,mixed>
     */
    public static function of($meta): array
    {
        if (!is_array($meta)) {
            return array();
        }

        $shaping = $meta[ShapingListener::SCHEMA_KEY] ?? null;

        return is_array($shaping) ? $shaping : array();
    }

    /**
     * The stated label, or null when the entity stated none.
     *
     * An empty string counts as "none" rather than as an empty caption, the same way a stated
     * `tab: ''` has never produced a nameless tab: a field with no visible label is unusable,
     * and the humanised property name is a better answer than a blank.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    public static function label($meta): ?string
    {
        $label = self::of($meta)['label'] ?? null;

        return is_string($label) && $label !== '' ? $label : null;
    }

    /**
     * The stated position, or null when the entity stated none.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    public static function position($meta): ?int
    {
        $position = self::of($meta)['position'] ?? null;

        return is_int($position) ? $position : null;
    }

    /**
     * One stated boolean attribute — `true`, `false`, or null when the entity stated none.
     *
     * @param array<string,mixed>|mixed $meta The field's schema entry
     */
    public static function flag($meta, string $key): ?bool
    {
        $value = self::of($meta)[$key] ?? null;

        return is_bool($value) ? $value : null;
    }

    /**
     * Order two fields by their stated position, the unstated ones last.
     *
     * ADR 0017: a stated position wins over an unstated one, and equal or absent positions keep
     * the schema order. The second half is left to the caller's `usort()`, which has been a
     * stable sort since PHP 8.0 — returning 0 here is what preserves the order the schema
     * delivered, so this must stay a comparison and never fall back to comparing something else.
     */
    public static function comparePosition(?int $a, ?int $b): int
    {
        if ($a === null || $b === null) {
            return ($a === null ? 1 : 0) <=> ($b === null ? 1 : 0);
        }

        return $a <=> $b;
    }
}
