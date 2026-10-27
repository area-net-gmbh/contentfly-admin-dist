<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Page;

use Areanet\ContentflyAdmin\Attribute\AdminRowAction;

/**
 * The record actions a project has registered — the one list the mount, the dispatcher and the
 * list view read.
 *
 * A PROJECT REGISTERS EXPLICITLY, exactly as it registers pages, and for the reasons ADR 0028 §1
 * gives and ADR 0029 §1 carries forward: no service tags in this stack, and a directory scan
 * would have to run while `custom/app.php` is being read. This is deliberately not a third way
 * of saying the same thing — a project that has learnt `pages` already knows `rowActions`.
 *
 * FILLED IN THE CONSTRUCTOR, like `PageRegistry` and unlike the `WidgetRegistry`. The mount needs
 * to know whether there is anything to mount before `bindRoutes()`, so the list has to exist by
 * then. Nothing here reads a directory, the schema, the database or the request.
 *
 * EVERYTHING IS CHECKED HERE, WHERE THE MISTAKE IS CHEAP — an unknown class, a missing base
 * class, a missing attribute, an empty entity or label, a malformed permission, or two actions
 * that would answer on one address. The admin then refuses to start, naming the class, instead of
 * answering 404 at the moment an editor presses the button.
 *
 * THE ADDRESS KEY COMES FROM THE CLASS NAME, not from a sixth attribute argument. `WordExportAction`
 * becomes `word-export`: the short name without a trailing `Action`, hyphenated at its humps and
 * lowercased. Nobody types this address — it is the target of a form button — so the contract is
 * better served by one argument fewer than by letting a project name it. Renaming the class
 * changes the address, which costs nothing because no bookmark and no link points at it. Two
 * classes of one entity that would produce the same key are refused rather than silently merged.
 */
final class RowActionRegistry
{
    /** Where the registry puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.row_action_registry';

    /** The mount all actions share, below the admin's prefix — reserved in `PageRegistry`. */
    public const SEGMENT = 'rowaction';

    /** @var array<string,RowActionDeclaration> "<Entity>\0<key>" => the action */
    private array $actions = array();

    private bool $sealed = false;

    /**
     * Register one record action by its class name.
     *
     * @param string $class A class extending `AbstractRowAction` and carrying `#[AdminRowAction]`
     *
     * @throws \LogicException           when the registry is already sealed
     * @throws \InvalidArgumentException on anything wrong with the class or its attribute
     */
    public function register(string $class): void
    {
        if ($this->sealed) {
            throw new \LogicException(sprintf(
                'The row-action registry is sealed; "%s" arrives too late to get a route. Register '
                .'actions through the "rowActions" option of the AdminManager in custom/app.php — '
                .'that callback runs before the routes are mounted, which is the only moment an '
                .'action can get one.',
                $class
            ));
        }

        if (!class_exists($class)) {
            throw new \InvalidArgumentException(sprintf(
                'The row-action class "%s" does not exist. Check the namespace and that composer autoloads it.',
                $class
            ));
        }

        if (!is_subclass_of($class, AbstractRowAction::class)) {
            throw new \InvalidArgumentException(sprintf(
                'The row-action class "%s" has to extend %s — that is what brings authentication, '
                .'the permission check and the data layer with it.',
                $class,
                AbstractRowAction::class
            ));
        }

        $attributes = (new \ReflectionClass($class))->getAttributes(AdminRowAction::class);

        if ($attributes === array()) {
            throw new \InvalidArgumentException(sprintf(
                'The row-action class "%s" carries no #[AdminRowAction] attribute, so it has neither '
                .'an entity to appear in nor a label.',
                $class
            ));
        }

        /** @var AdminRowAction $action */
        $action = $attributes[0]->newInstance();

        if ($action->entity === '') {
            throw new \InvalidArgumentException(sprintf(
                'The row action "%s" names no entity; without one there is no list it could appear in.',
                $class
            ));
        }

        if ($action->label === '') {
            throw new \InvalidArgumentException(sprintf(
                'The row action "%s" has an empty label; the label is the text of its button.',
                $class
            ));
        }

        if ($action->permission !== null) {
            /* Throws with its own message when the form is wrong — ADR 0028 §3. */
            PagePermission::parse($action->permission);
        }

        $key  = self::key($class);
        $slot = $action->entity."\0".$key;

        if (isset($this->actions[$slot])) {
            throw new \InvalidArgumentException(sprintf(
                'The row actions "%s" and "%s" would both answer on "%s/%s"; their class names '
                .'produce the same address. Rename one of them.',
                $this->actions[$slot]->class,
                $class,
                $action->entity,
                $key
            ));
        }

        $this->actions[$slot] = new RowActionDeclaration(
            $class,
            $action->entity,
            $key,
            $action->label,
            $action->icon,
            $action->bulk,
            $action->permission
        );
    }

    /** Closes the registry. Called once, by the manager, right after the project's callback. */
    public function seal(): void
    {
        $this->sealed = true;
    }

    /** Has a project registered anything at all? Decides whether the mount happens. */
    public function isEmpty(): bool
    {
        return $this->actions === array();
    }

    /**
     * The actions of one entity, in registration order.
     *
     * @return list<RowActionDeclaration>
     */
    public function forEntity(string $entity): array
    {
        $found = array();

        foreach ($this->actions as $action) {
            if ($action->entity === $entity) {
                $found[] = $action;
            }
        }

        return $found;
    }

    /** What answers this entity and key, or `null` when nothing does. */
    public function target(string $entity, string $key): ?RowActionDeclaration
    {
        return $this->actions[$entity."\0".$key] ?? null;
    }

    /**
     * The address key of one action class: `Custom\Admin\WordExportAction` => `word-export`.
     *
     * THE TRAILING `Action` GOES because every one of these classes ends in it — keeping it would
     * put `-action` in every address and say nothing. A class called just `Action` keeps its name
     * rather than ending up with an empty key.
     */
    public static function key(string $class): string
    {
        $short = substr(strrchr('\\'.$class, '\\'), 1);

        if (strlen($short) > 6 && substr($short, -6) === 'Action') {
            $short = substr($short, 0, -6);
        }

        return strtolower(preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', '-', $short));
    }
}
