<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\PIM\Classes\Api;
use Symfony\Component\HttpFoundation\Request;

/**
 * Reading a `BaseTree` entity's tree, and the three questions three controllers ask about it.
 *
 * IT IS A TRAIT FOR THE SAME REASON `ResolvesFiles` IS ONE: three entry points need the same
 * answers and none of them owns the others. `TreeController` renders the tree and counts what a
 * delete would take; `FormController` validates a preset parent and keeps a node out of its own
 * subtree; `OptionsController` does the latter again for a tree too large to list (ADR 0025 §3).
 *
 * THE TREE IS READ ONCE PER REQUEST AND NEVER PER NODE. `Api::getTree2()` answers the whole tree
 * in one query, `Api::getTree()` one query per node — the decision of ADR 0025 and the trap the
 * dev guide records for the folder tree.
 *
 * WHOEVER CALLS THIS HAS ALREADY CHECKED THE READ PERMISSION. `Api::getTree2()` *raises* for an
 * entity the editor may not read, so this trait deliberately does not catch that: a 500 here
 * means a caller skipped its gate, and swallowing it would hide the mistake rather than the
 * symptom.
 *
 * THE ROWS STAY RAW. What comes back are database columns (schema contract section 9.1), not a
 * view model — `TreeController::nodes()` turns them into something printable, and the other two
 * callers only ever look at ids. A trait that pre-chewed them would have to guess which of the
 * three needs what.
 */
trait ResolvesTree
{
    /**
     * The property that holds a node's parent, as `BaseTree` names it.
     *
     * NAMED ONCE BECAUSE THREE PLACES MEAN IT: the query parameter of the create link, the
     * preset in the form, and the subtree exclusion. A literal in each would be three places to
     * change if the core ever renamed the field — and two of them would keep working, which is
     * the worst of the possible outcomes.
     */
    public const PARENT_PROPERTY = 'treeParent';

    /**
     * The whole tree of one entity, as `Api::getTree2()` answers it.
     *
     * @return list<array<string,mixed>>
     */
    private function treeRows(Request $request, string $entity): array
    {
        $rows = (new Api($this->app, $request))->getTree2($entity);

        return is_array($rows) ? $rows : array();
    }

    /**
     * The node with this id, anywhere in the tree, or `null`.
     *
     * THE ANSWER IS ALSO THE MEMBERSHIP TEST, and that is what `FormController` uses it for: an
     * id that is not in the tree is either not a record of this entity, or one the editor may not
     * see, or `isIntern` — and a preset parent must fail for all three the same way. Telling them
     * apart would say something about records the editor cannot see.
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return array<string,mixed>|null
     */
    private static function findInTree(array $rows, string $id): ?array
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            if ((string) ($row['id'] ?? '') === $id) {
                return $row;
            }

            $childs = $row['childs'] ?? array();
            $found  = is_array($childs) ? self::findInTree($childs, $id) : null;

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * One node's id and the ids of everything below it.
     *
     * THIS IS THE LIST A NODE MAY NOT BE MOVED INTO, and the measurement behind it is the reason
     * the list exists at all: the core accepts a cycle without a word and writes it, and because
     * `treeSort()` only descends from rows with an empty `parent_id`, the whole connected
     * component of the cycle is unreachable afterwards — it disappears from the admin with no
     * error anywhere (gap L31, measured in 004-001-0004, scope corrected in 004-002-0001).
     * Re-measured with other nodes in the data: `multiupdate` answers `200`, the cycle really is
     * in `pim_tree`, and ten nodes became seven — exactly the three of the cycle, while the other
     * roots stayed visible (schema contract 10.4). The first measurement answered an *empty*
     * tree because there the three-level tree **was** the whole component. One wrong choice in a
     * dropdown, or one drop, is enough. The node itself is in the list because a record that is
     * its own parent is the shortest cycle there is.
     *
     * @param array<string,mixed> $node
     *
     * @return list<string>
     */
    private static function subtreeIds(array $node): array
    {
        $ids    = array();
        $id     = $node['id'] ?? null;
        $childs = $node['childs'] ?? array();

        if (is_string($id) || is_int($id)) {
            $ids[] = (string) $id;
        }

        if (is_array($childs)) {
            foreach ($childs as $child) {
                if (is_array($child)) {
                    $ids = array_merge($ids, self::subtreeIds($child));
                }
            }
        }

        return $ids;
    }

    /**
     * The ids of one level, in the order the tree was read — the children of `$parentId`, or the
     * roots when it is empty.
     *
     * THE ORDER IS THE PAGE'S ORDER, and that matters: `Api::getTree2()` sorts by
     * `ORDER BY t.parent_id, t.sorting`, so this list is exactly what the editor saw. Rebuilding
     * it from the database would risk a different tie-break than the one that was rendered, and
     * at `sorting = 0` every sibling is a tie (gap `L33`).
     *
     * @param list<array<string,mixed>> $rows
     *
     * @return list<string>
     */
    private static function siblingIds(array $rows, string $parentId): array
    {
        $level = $rows;

        if ($parentId !== '') {
            $parent = self::findInTree($rows, $parentId);
            $childs = $parent === null ? array() : ($parent['childs'] ?? array());
            $level  = is_array($childs) ? $childs : array();
        }

        $ids = array();

        foreach ($level as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = $row['id'] ?? null;

            if (is_string($id) || is_int($id)) {
                $ids[] = (string) $id;
            }
        }

        return $ids;
    }

    /**
     * The id of one node's parent, or `null` when it has none here.
     *
     * IT LOOKS FOR THE NODE AS SOMEBODY'S CHILD, which is what makes it short and correct: the
     * answer is the id of whoever holds it. `null` therefore covers two cases on purpose — the
     * node is a root, or it is not in this tree at all. A caller that must tell them apart asks
     * `findInTree()` first, which is what `move()` does before it ever calls this.
     *
     * READ FROM THE STRUCTURE, NOT FROM THE ROW. The row does carry `parent` as
     * `array('id' => …)` (schema contract 9.1), but the structure is what was rendered and what
     * a move operates on; two sources could only ever agree or signal a bug.
     *
     * @param list<array<string,mixed>> $rows
     */
    private static function parentIdOf(array $rows, string $id): ?string
    {
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $childs = $row['childs'] ?? array();
            $childs = is_array($childs) ? $childs : array();

            foreach ($childs as $child) {
                if (is_array($child) && (string) ($child['id'] ?? '') === $id) {
                    return (string) ($row['id'] ?? '');
                }
            }

            $found = self::parentIdOf($childs, $id);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * How many nodes hang below this one — what a delete would take with it.
     *
     * The node itself is not counted: the question the confirmation asks is "and how much else",
     * and the editor already knows they clicked one node (story 004-001-0005).
     *
     * @param array<string,mixed> $node
     */
    private static function descendantCount(array $node): int
    {
        return max(0, count(self::subtreeIds($node)) - 1);
    }
}
