<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\PIM\Classes\Api;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The files an image field may choose from, as JSON for the picker overlay of the form.
 *
 * ITS OWN MOUNT AND ITS OWN PATH SEGMENT, like the list, the form and the options search — one
 * mount binds one controller class, and the segment under the prefix is ADR 0012. That this is
 * not a third route on the `/files` mount is the decision of ADR 0023: the file manager renders
 * pages a person reads, this answers a widget. `FileController::detailAction` is a *page of the
 * file manager* and therefore rightly sits on its mount; a selection list is the data source of a
 * form field whose only caller is a Stimulus controller — the situation of `OptionsController`.
 *
 * JSON AND NOT A TURBO FRAME, for the reason `OptionsController` states: the caller puts the
 * entries into markup it owns, and a server-rendered fragment would put the markup of the field
 * in two places. The package also removed an `_format=json` branch from the file routes again in
 * story 003-003-0004 because it had no second caller — this route is that caller, on its own
 * address.
 *
 * NO ENTITY PLACEHOLDER, like the file manager and for its reason: this works against
 * `PIM\File` and `PIM\Folder` and nothing else, so the names are constants rather than a segment
 * somebody could put another entity into. The whitelist is still checked (ADR 0005).
 *
 * NO TYPE FILTER. `accept` is the wildcard `*` on `file` and `multifile` and the target is not in
 * the schema (gap L7), so there is nothing to filter by; the folder's files are answered and the
 * preview decides per MIME type whether an image or a label is shown. Reading `accept` to narrow
 * this would invent a rule the core does not have.
 */
final class FilePickerController
{
    use GuardsCoreVersion;
    use GuardsPermissions;
    use ResolvesFiles;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * One page of selectable files, plus the folder they were read from.
     *
     * THE FOLDERS TRAVEL AS ID AND NAME, NOT AS ADDRESSES. The file manager builds links because
     * it renders them; here the caller asks this same route again with another `folder`, so an
     * address built in PHP would be a second place that knows this route's own shape.
     *
     * THE TREE IS ASKED FOR ONLY WHEN IT MAY BE READ: `Api::getTree2()` raises for a folder
     * entity the editor may not read (`Api.php:2430`). Without that right the folder column is
     * simply absent and the files stay listed — the additive masking of ADR 0019, the same way
     * `FileController` does it. `mayReadFolders` says so plainly instead of letting the caller
     * read an empty list as "no folders exist".
     */
    public function pickerAction(Request $request): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];

        if (!in_array(FileController::ENTITY_FILE, $manager->getEntities(), true)) {
            return new JsonResponse(array(), 404);
        }

        if (!$this->mayRead(FileController::ENTITY_FILE)) {
            return self::accessDeniedBare();
        }

        $api = new Api($this->app, $request);

        $mayReadFolders = $this->mayRead(FileController::ENTITY_FOLDER);
        $tree           = $mayReadFolders ? $api->getTree2(FileController::ENTITY_FOLDER) : null;

        $folder = self::requestedFolder($request, $tree, $mayReadFolders);
        $page   = self::page($request);

        /*
         * `getList()` ANSWERS `null` FOR AN EMPTY RESULT, not an empty list — normalised at this
         * edge the way `FileController` and `ListController` normalise it at theirs.
         */
        $result = $api->getList(
            FileController::ENTITY_FILE,
            array(self::PARAM_FOLDER => $folder ?? self::FOLDER_UNSET),
            array('name' => 'ASC'),
            null,
            array('id', 'name', 'type'),
            null,
            false,
            $page,
            FileController::ITEMS_PER_PAGE
        );

        $records = is_array($result) ? ($result['objects'] ?? array()) : array();
        $total   = is_array($result) ? (int) ($result['totalObjects'] ?? 0) : 0;

        /* Clamped the way the core clamps it, so the pager and the address cannot disagree. */
        $pages = max(1, (int) ceil($total / FileController::ITEMS_PER_PAGE));
        $page  = min($page, $pages);

        return new JsonResponse(array(
            'folder'         => $folder,
            'mayReadFolders' => $mayReadFolders,
            'trail'          => $tree === null ? array() : self::trail($tree, $folder),
            'folders'        => $tree === null ? array() : self::children($tree, $folder),
            'files'          => self::entries($records, $manager->getThumbnailAlias()),
            'page'           => $page,
            'pages'          => $pages,
            'total'          => $total,
        ));
    }

    /**
     * The selectable files of this page.
     *
     * THE PREVIEW IS THE SMALL FASSUNG AND THE ORIGINAL TRAVELS WITH IT, because the overlay
     * needs the same fallback the listing has: whether `thumbnailAlias` names a row that exists
     * cannot be found out from here (gap L25), so the browser swaps a failed derivative for the
     * original. Sending only the derivative would make a broken configuration look like a broken
     * file.
     *
     * @param list<mixed> $records
     *
     * @return list<array<string,mixed>>
     */
    private static function entries(array $records, string $alias): array
    {
        $entries = array();

        foreach ($records as $record) {
            $values = is_array($record) ? $record : (array) $record;

            $id   = (string) ($values['id'] ?? '');
            $name = (string) ($values['name'] ?? '');
            $type = (string) ($values['type'] ?? '');

            /*
             * A RECORD WITHOUT A USABLE ID IS LEFT OUT instead of travelling with an empty one:
             * the only thing the caller does with an entry is write its id into the field.
             */
            if ($id === '') {
                continue;
            }

            $entries[] = array(
                'id'       => $id,
                'name'     => $name,
                'type'     => $type,
                'preview'  => self::preview($id, $name, $type, $alias),
                'original' => self::preview($id, $name, $type, null),
                'badge'    => self::badge($type),
            );
        }

        return $entries;
    }

    /**
     * The folders directly below the open one — the step the overlay can take downwards.
     *
     * @param list<array<string,mixed>> $nodes
     *
     * @return list<array<string,string>>
     */
    private static function children(array $nodes, ?string $folder): array
    {
        if ($folder !== null) {
            $node  = self::find($nodes, $folder);
            $nodes = $node === null ? array() : ($node['childs'] ?? array());
        }

        $folders = array();

        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');

            if ($id === '') {
                continue;
            }

            $folders[] = array('id' => $id, 'name' => (string) ($node['name'] ?? ''));
        }

        return $folders;
    }

    /**
     * The open folder's ancestors, outermost first — the steps upwards, and the root is the
     * empty trail.
     *
     * @param list<array<string,mixed>> $nodes
     *
     * @return list<array<string,string>>
     */
    private static function trail(array $nodes, ?string $folder): array
    {
        if ($folder === null) {
            return array();
        }

        foreach ($nodes as $node) {
            $id = (string) ($node['id'] ?? '');

            if ($id === $folder) {
                return array(array('id' => $id, 'name' => (string) ($node['name'] ?? '')));
            }

            $below = self::trail($node['childs'] ?? array(), $folder);

            if ($below !== array()) {
                return array_merge(
                    array(array('id' => $id, 'name' => (string) ($node['name'] ?? ''))),
                    $below
                );
            }
        }

        return array();
    }
}
