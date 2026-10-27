<?php

namespace Areanet\ContentflyAdmin\Attribute;

use Attribute;

/**
 * Declares a class to be one action on records of an entity — stage 3b of the extension contract.
 *
 * THE OTHER HALF OF STAGE 3, AND THE MORE COMMON ONE. Stage 3a adds a screen that is not a table;
 * this adds a button to the table that is already there. No menu entry, no address an editor
 * types, no layout — a label in the row and, where the action says so, a second one above the
 * list for everything that is ticked. Concept section 9 writes the target shape out as a Word
 * export, and that is exactly what the pilot builds against it.
 *
 * ONE METHOD FOR ONE RECORD AND FOR MANY. The action is called as `__invoke(array $ids)` whether
 * it was reached from a row or from the bulk bar, so it is written once instead of twice. Where
 * the difference matters it is a `count()`, which is where it belongs — the Word export returns a
 * `.docx` for one id and a ZIP for several, and nothing about that is the contract's business.
 *
 * A PROJECT REGISTERS ITS ACTIONS EXPLICITLY, class name by class name, exactly as it registers
 * pages. The reasoning is ADR 0028 §1 and is not repeated per attribute: there are no service
 * tags in this stack, and a directory scan would have to run while `custom/app.php` is read.
 *
 * THE ROW BUTTON ACTS ON ITS OWN ROW, ALWAYS. That sounds like a triviality and is the one
 * decision this feature turns on. The list's tick boxes and the row buttons sit inside the same
 * POST form — HTML allows no second one inside it — so a row button submits the ticked `ids[]`
 * unless it is stopped from doing so. It is: the row button carries its own id and the dispatcher
 * then does not read `ids[]` at all (ADR 0029 §4). Without that rule a click on one row would
 * quietly take every ticked row with it, invisibly, until the day an action writes.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class AdminRowAction
{
    /**
     * @param string      $entity     The entity whose records this acts on, as the schema spells
     *                                it — `'Angebot'`. The action appears in that entity's list
     *                                and nowhere else; an entity outside the admin's whitelist
     *                                never renders a list, so the action never appears either.
     * @param string      $label      The button's text, shown to editors as written.
     * @param string|null $icon       Optional icon name beside the label. Null renders the label
     *                                alone, which stays fully readable — the icon decorates, it
     *                                never carries the meaning on its own.
     * @param bool        $bulk       Offer this above the list for the ticked records too.
     *                                Default `false`, because acting on many records is the
     *                                larger promise of the two: a row button affects what the
     *                                editor pointed at, the bulk button affects a selection that
     *                                may have scrolled out of sight. An action opts into that.
     * @param string|null $permission Required right, as `'<Entity>:<action>'` with `read`, `write`
     *                                or `delete` — `'Angebot:read'`. Same format, same parser and
     *                                same asymmetry as a page's (ADR 0028 §3): the form is checked
     *                                when the action is registered, the entity only when a request
     *                                arrives, and an entity nobody knows counts as "no right".
     *                                Null makes the action available to everyone who may read the
     *                                entity at all — that right is checked in every case, because
     *                                without it there is no list to press a button in.
     */
    public function __construct(
        public readonly string $entity,
        public readonly string $label,
        public readonly ?string $icon = null,
        public readonly bool $bulk = false,
        public readonly ?string $permission = null,
    ) {
    }
}
