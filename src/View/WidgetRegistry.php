<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

/**
 * The two places a project can override the widget a field is edited with.
 *
 * THIS IS A SEAM, NOT A FEATURE. Story 005-002 builds the real extension contract for custom
 * field widgets — a Stimulus controller plus a Twig fragment, registered by service tag (concept
 * section 9). None of that is here. What is here are the first two links of the resolution chain
 * from ADR 0013, as a place that can be asked and that is empty by default: an override for one
 * concrete field, and an override for a field type.
 *
 * WHY NOW AND NOT WITH 005-002. If the chain is retrofitted later, every call site of the
 * resolver is wired to plain inference until then, and the order of resolution has to be
 * introduced into code that already works — the kind of change that is cheap to write and
 * expensive to be sure about. Registered empty, the chain is in place and provably changes
 * nothing: the resolver asks, gets no answer, and infers exactly as before.
 *
 * THE FIELD KEY IS THE ENTITY PLUS THE PROPERTY, because a property name alone is not unique
 * across entities — `titel` exists on most of them, and an override meant for one would quietly
 * reach all. The entity is spelled as the schema spells it (`Harness\Feldtyp`), not as the URL
 * segment of ADR 0011.
 */
final class WidgetRegistry
{
    /** Where the registry puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.widget_registry';

    /**
     * Widget per field, keyed `<Entity>.<property>`.
     *
     * @var array<string,string>
     */
    private array $byField = array();

    /**
     * Widget per field type, keyed with the core's type alias.
     *
     * @var array<string,string>
     */
    private array $byType = array();

    /**
     * Register a widget for one field of one entity. The most specific override there is.
     *
     * @param string $entity Entity name as the schema spells it, e.g. `Harness\Feldtyp`
     */
    public function registerField(string $entity, string $property, string $widget): void
    {
        $this->byField[self::fieldKey($entity, $property)] = $widget;
    }

    /**
     * Register a widget for every field of one type.
     *
     * @param string $type The core's type alias, lower case — `joinbidirectional`, not
     *                     `joinBidirectional`
     */
    public function registerType(string $type, string $widget): void
    {
        $this->byType[$type] = $widget;
    }

    /**
     * The overriding widget for one field, or `null` when nothing is registered for it.
     *
     * The field beats the type, which is the whole point of having both: a project sets one
     * widget for all its date fields and a different one for the single date field that is
     * special, without having to take the first registration back.
     *
     * @param string|null $type The field's type alias, or `null` for a field whose schema entry
     *                          carries none — an override by field still applies to it
     */
    public function find(string $entity, string $property, ?string $type): ?string
    {
        $key = self::fieldKey($entity, $property);

        if (isset($this->byField[$key])) {
            return $this->byField[$key];
        }

        if ($type !== null && isset($this->byType[$type])) {
            return $this->byType[$type];
        }

        return null;
    }

    private static function fieldKey(string $entity, string $property): string
    {
        return $entity . '.' . $property;
    }
}
