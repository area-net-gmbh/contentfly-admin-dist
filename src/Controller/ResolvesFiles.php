<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Symfony\Component\HttpFoundation\Request;

/**
 * What both views of `PIM\File` need: the address of a stored file and the folder that was asked
 * for.
 *
 * TWO CALLERS, NOT A LIBRARY. `FileController` renders the pages a person reads;
 * `FilePickerController` answers the form's image fields in JSON (ADR 0023). Everything here was
 * `private static` in `FileController` until the second caller existed — the package's rule is no
 * abstraction for single-use code, so this trait appeared the moment the code stopped being
 * single-use and not a release earlier. Anything with only one caller stays where it is:
 * `original()`, `variants()`, `rows()` and the tree-to-link helpers are the file manager's own and
 * are deliberately not here.
 *
 * THE CONSTANTS TRAVEL WITH THE METHODS that read them, which is why they sit in a trait rather
 * than in the controller: `self::` inside a trait resolves against the using class, so a
 * `preview()` here with its constants left behind in `FileController` would be a method that only
 * compiles in one of its two homes.
 */
trait ResolvesFiles
{
    /**
     * The query parameter that carries the open folder.
     *
     * A PARAMETER AND NOT A PATH SEGMENT (ADR 0020). A placeholder matches one segment
     * (ADR 0007), so no route could address a folder tree of arbitrary depth.
     */
    public const PARAM_FOLDER = 'folder';

    /**
     * The `where` value that asks `Api::getList()` for the records whose relation is unset.
     *
     * THE ROOT IS "NO FOLDER", not "all files". `ListController` uses the same literal for the
     * same core convention — verified against the running harness: `folder => '-1'` returns the
     * one file that sits outside every folder, while an unknown id returns none.
     */
    private const FOLDER_UNSET = '-1';

    /**
     * The core's own file route. Fixed, not configurable: `file` is one of the prefixes the core
     * mounts itself and the admin refuses to be mounted on (`AdminManager::RESERVED_PREFIXES`).
     */
    private const CORE_FILE_ROUTE = '/file/get';

    /**
     * The types a derivative can be made from.
     *
     * THE LIST IS THE CORE'S, NOT A GUESS. `Processing\Image::getMimeTypes()` registers exactly
     * these three; for anything else `Processing::getInstance()` hands back the `Standard`
     * processor, and `FileController::getAction` then raises `FileNotFoundException`
     * (Schema-Vertrag 8.3). Asking for a size on a fourth type is therefore not a degraded
     * preview, it is a 500 per row — which is why `image/svg+xml` and `image/webp` belong on the
     * icon side even though their names say image.
     *
     * @var list<string>
     */
    private const THUMBNAILABLE = array('image/jpeg', 'image/gif', 'image/png');

    /**
     * One address of a file: the derivative with an alias, the stored original without one.
     *
     * `null` MEANS "SHOW THE BADGE INSTEAD" and happens in exactly one case: the file is not one
     * of the three types a derivative can be made from. For those the core answers a size request
     * with a 500, so not asking is the whole point (ADR 0020).
     *
     * THE TRAILING SEGMENT IS COSMETIC. The record is found by `{id}` alone (Schema-Vertrag 8.1);
     * the name only makes the address readable, so it is encoded and never trusted to route.
     */
    private static function preview(string $id, string $name, string $type, ?string $alias): ?string
    {
        if ($id === '' || !in_array($type, self::THUMBNAILABLE, true)) {
            return null;
        }

        $tail = $name === '' ? 'datei' : rawurlencode($name);

        if ($alias === null) {
            return self::CORE_FILE_ROUTE.'/'.rawurlencode($id).'/'.$tail;
        }

        return self::CORE_FILE_ROUTE.'/'.rawurlencode($id).'/s-'.rawurlencode($alias).'/'.$tail;
    }

    /**
     * The short label a file without a preview shows in the preview box.
     *
     * A LABEL AND NOT AN ICON FILE, because the set of types is open: a project can store
     * anything, and an icon set would either need a file per type or a generic image that says
     * less than the four letters it replaces. The subtype is what distinguishes them.
     *
     * AN UNKNOWN OR EMPTY TYPE STILL GETS SOMETHING. An empty box is indistinguishable from a
     * preview that failed to load, and that is the one reading this must not have.
     */
    private static function badge(string $type): string
    {
        $subtype = strtolower(substr($type, (int) strpos($type, '/') + 1));
        /* `svg+xml` and `vnd.openxmlformats-…` carry their family after a separator. */
        $subtype = preg_replace('#[^a-z0-9].*$#', '', $subtype) ?? '';

        return $subtype === '' ? '?' : strtoupper(substr($subtype, 0, 4));
    }

    /**
     * The folder the address asks for, or `null` for the root.
     *
     * AN UNKNOWN FOLDER FALLS BACK TO THE ROOT RATHER THAN ANSWERING 404. The folder is view
     * state in the query string, like the list's sort column and page number, and the list
     * clamps invalid state instead of refusing it (ADR 0018). A folder that was deleted while
     * someone had the page open is the ordinary way to get here, and dropping them at the root
     * is a better answer than a page that reads like a broken link.
     *
     * WITHOUT THE RIGHT TO READ FOLDERS IT CANNOT BE CHECKED, so it is passed through as an
     * opaque filter value — an unknown id then simply matches no file (verified against the
     * running harness). That asymmetry is deliberate: the alternative would be to deny a folder
     * view to an editor who may read the files in it.
     */
    private static function requestedFolder(Request $request, ?array $tree, bool $mayReadFolders): ?string
    {
        $wanted = $request->query->get(self::PARAM_FOLDER);

        if (!is_string($wanted) || $wanted === '') {
            return null;
        }

        if (!$mayReadFolders) {
            return $wanted;
        }

        return self::find($tree ?? array(), $wanted) === null ? null : $wanted;
    }

    /** The page number the address asks for, never below one. */
    private static function page(Request $request): int
    {
        $page = $request->query->get('page');

        return is_numeric($page) ? max(1, (int) $page) : 1;
    }

    /** The node with this id, anywhere in the tree, or `null`. */
    private static function find(array $nodes, string $id): ?array
    {
        foreach ($nodes as $node) {
            if (($node['id'] ?? null) === $id) {
                return $node;
            }

            if (($found = self::find($node['childs'] ?? array(), $id)) !== null) {
                return $found;
            }
        }

        return null;
    }
}
