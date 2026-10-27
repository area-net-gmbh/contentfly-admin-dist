<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

/**
 * The two places a project can override the widget a field is edited with.
 *
 * THE SEAM IS NOW THE CONTRACT (story 005-002, ADR 0027). A project fills this registry through
 * the `widgets` option of the `AdminManager`, whose callback receives it; "registered by service
 * tag" from concept section 9 is overtaken, because this stack has no such mechanism. What is
 * here are the first two links of the resolution chain from ADR 0013: an override for one
 * concrete field, and an override for a field type.
 *
 * TWO NAMESPACES, NOT ONE WITH A PRECEDENCE RULE. A package key such as `textarea` and a
 * project's own key are told apart by the `custom-` prefix, and that is what makes the two
 * legitimate registrations distinguishable at the one moment a mistake is cheap: putting a field
 * on a widget the package ships (`registerType('string', 'textarea')`) against naming an own
 * widget. Without the prefix both arrive here as the same string, and a project widget that
 * happened to be called `textarea` would quietly replace the package's on every entity.
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

    /** What tells a project's own widget key from one the package ships (ADR 0027). */
    public const PROJECT_PREFIX = 'custom-';

    /**
     * The widget keys the package owns, and which a project therefore cannot name its own.
     *
     * Two groups, and the difference is worth knowing: every fragment shipped under
     * `templates/widget/` is in here — `search` included, which is the list's search box rather
     * than a field widget, but shares the namespace and could be shadowed just as well. And
     * `permissionmatrix` is in here *without* a fragment of its own: the resolver can produce it
     * (ADR 0013 §1), so the key is taken, and a field of that key renders as `readonly` until a
     * fragment exists (ADR 0016 §2). The gate of 005-002-0005 holds the invariant that every
     * shipped fragment appears in this list.
     *
     * @var list<string>
     */
    public const PACKAGE_KEYS = array(
        'boolean',
        'checkboxgroup',
        'datetime',
        'entity',
        'entitylist',
        'file',
        'filelist',
        'json',
        'number',
        'permissionmatrix',
        'radio',
        'readonly',
        'rte',
        'search',
        'select',
        'text',
        'textarea',
        'time',
    );

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
     * Whether this registry still accepts registrations.
     *
     * The instance in the container is sealed as soon as the `widgets` callback has run, because
     * that callback *is* the window: `custom/app.php` is the only project-side file a web request
     * loads at all, and it runs immediately before `bindRoutes()`. A registration attempted later
     * would be a coin toss — it works if it happens to precede the first `resolve()` of that field
     * and does nothing if it does not. Sealing turns that into a message. A registry built by hand
     * is never sealed, which is what keeps the gates free to register and resolve in any order.
     */
    private bool $sealed = false;

    /**
     * Register a widget for one field of one entity. The most specific override there is.
     *
     * @param string $entity Entity name as the schema spells it, e.g. `Harness\Feldtyp`
     */
    public function registerField(string $entity, string $property, string $widget): void
    {
        $this->assertRegistrable($widget);

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
        $this->assertRegistrable($widget);

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

    /**
     * Closes this registry for further registrations. Called once, by the container's factory.
     */
    public function seal(): void
    {
        $this->sealed = true;
    }

    /**
     * @throws \LogicException           when this registry is already sealed
     * @throws \InvalidArgumentException when the widget key is neither a package key nor a
     *                                   well-formed project key
     */
    private function assertRegistrable(string $widget): void
    {
        if ($this->sealed) {
            throw new \LogicException(sprintf(
                'The widget registry is sealed; "%s" arrives too late to have any effect. Register '
                . 'widgets through the "widgets" option of the AdminManager in custom/app.php — '
                . 'that callback is the one moment the registration is guaranteed to be in time.',
                $widget
            ));
        }

        if (in_array($widget, self::PACKAGE_KEYS, true)) {
            return;
        }

        if (preg_match('/^custom-[a-z0-9][a-z0-9-]*$/', $widget) !== 1) {
            throw new \InvalidArgumentException(sprintf(
                'Unusable widget key "%s". Either name one the package ships (%s), or your own, '
                . 'which carries the prefix "%s" and otherwise holds lower-case letters, digits '
                . 'and hyphens — "%sfarbwaehler" for example. The prefix is what keeps a project '
                . 'widget from silently replacing one of the package\'s (ADR 0027).',
                $widget,
                implode(', ', self::PACKAGE_KEYS),
                self::PROJECT_PREFIX,
                self::PROJECT_PREFIX
            ));
        }
    }

    private static function fieldKey(string $entity, string $property): string
    {
        return $entity . '.' . $property;
    }
}
