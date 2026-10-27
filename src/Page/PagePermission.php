<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Page;

use Areanet\ContentflyAdmin\Auth\PermissionView;

/**
 * The one place `#[AdminPage(permission: …)]` is read — ADR 0028 §3.
 *
 * ONE QUESTION, TWO PLACES, which is why this is a class and not two `explode()` calls. The page
 * asks it before it renders (`AbstractAdminPage`), and the menu asks it before it offers the entry
 * (`View\MenuBuilder`). Those two answers have to agree: a menu that lists a page the page itself
 * refuses is a dead link, and a page reachable although the menu hides it is the inverse and
 * worse. `Controller\GuardsPermissions` keeps this discipline for the seven entity routes for the
 * same reason, and it says so at length.
 *
 * THE CHECK IS ASYMMETRIC ON PURPOSE. The **form** is validated when the page is registered, where
 * a mistake is a typo in project code and belongs in an exception the developer sees. The
 * **entity** is resolved only when a request arrives, because the editor whose rights decide the
 * answer does not exist before then — and an entity nobody knows counts as "no right", never as
 * an error. That direction matters: a typo in an entity name must not open a page.
 *
 * NOTHING SPECIAL FOR ADMINISTRATORS. `PermissionView::may()` reads the core's permission flags,
 * which already come back as `ALL` for an administrator, so `isAdmin()` is not asked here — it
 * exists for the language restrictions, which a page has no concept of.
 */
final class PagePermission
{
    public const READ = 'read';
    public const WRITE = 'write';
    public const DELETE = 'delete';

    /** The three actions a page may ask for, matching `PermissionView`'s three questions. */
    private const ACTIONS = array(self::READ, self::WRITE, self::DELETE);

    private function __construct(
        public readonly string $entity,
        public readonly string $action,
    ) {
    }

    /**
     * Reads `'<Entity>:<action>'`, or throws where a project wrote something else.
     *
     * THE MESSAGE NAMES THE THREE ACTIONS, because the value that gets here is hand-written in a
     * project's attribute and the most likely mistake is a plausible fourth word — `edit`,
     * `update`, `view`. Listing them turns one failed start into one fixed line.
     *
     * @throws \InvalidArgumentException
     */
    public static function parse(string $value): self
    {
        $parts = explode(':', $value);

        if (count($parts) !== 2 || $parts[0] === '' || $parts[1] === '') {
            throw new \InvalidArgumentException(sprintf(
                'The permission "%s" must read "<Entity>:<action>", for example "Angebot:read".',
                $value
            ));
        }

        if (!in_array($parts[1], self::ACTIONS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'The permission "%s" asks for "%s"; a page may ask for "%s".',
                $value,
                $parts[1],
                implode('", "', self::ACTIONS)
            ));
        }

        return new self($parts[0], $parts[1]);
    }

    /**
     * Does the editor hold the right?
     *
     * IT TAKES THE `PermissionView`, NOT THE CONTAINER, because both callers already hold one —
     * the page through its app, the menu as a constructor dependency — and a value object that
     * reaches into a container cannot be asked a question without one.
     */
    public function isGranted(PermissionView $permissions): bool
    {
        return match ($this->action) {
            self::WRITE  => $permissions->mayWrite($this->entity),
            self::DELETE => $permissions->mayDelete($this->entity),
            default      => $permissions->mayRead($this->entity),
        };
    }
}
