<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The file manager: folders, files, preview, and the three write paths.
 *
 * ITS OWN MOUNT UNDER `/<prefix>/files`, and it has to be one — `RouteManager` keys its
 * providers by mount path, so a second `mount($prefix, …)` would replace `AdminController`
 * instead of extending it. That the address is a function area of its own rather than
 * `/<prefix>/PIM-File` is ADR 0012, applied here by ADR 0020.
 *
 * IT IS NOT A SCHEMA-DRIVEN VIEW, and that is what makes it different from the four entry
 * points before it. The list, the form, the options search and the bulk actions all take an
 * entity from the URL and work out everything else from the schema. This one addresses two
 * fixed entities — `PIM\File` and `PIM\Folder` — because a file manager is a function, not a
 * table. The entity names are therefore constants here instead of a route placeholder.
 *
 * THE FOLDER TRAVELS IN THE QUERY STRING, never as a path segment. A placeholder in this core
 * matches a single segment and nothing reachable from a package can widen it (ADR 0007), so no
 * route could address a folder tree of arbitrary depth. The list keeps its own state in the
 * query string for the same reason (ADR 0018). ADR 0020 records the decision.
 *
 * BOTH GUARDS ARE HERE BECAUSE IT IS THE FIFTH ENTRY POINT. `GuardsPermissions` says it plainly:
 * whoever adds one adds both checks or neither. The whitelist answers 404 for an entity the
 * project did not release, the permission answers 403 as a page — and both run before the core
 * is asked anything.
 */
final class FileController
{
    use GuardsCoreVersion;
    use GuardsPermissions;

    /**
     * The entity whose records this view manages.
     *
     * A CONSTANT AND NOT A ROUTE PLACEHOLDER. The file manager is built around the fields of
     * `PIM\File` — folder, preview, name — and would not work against any other entity, so
     * taking the name from the URL would offer a freedom that does not exist. It is still
     * checked against the project's whitelist below, because a project that does not release
     * `PIM\File` does not get a file manager either (ADR 0005).
     */
    public const ENTITY_FILE = 'PIM\\File';

    /** The tree entity that gives the files their folders. */
    public const ENTITY_FOLDER = 'PIM\\Folder';

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The file manager's page.
     *
     * The route is registered `isSecure = true`: reading files goes through `Api::getList()`,
     * which asks `Permission::isReadable()` about `auth.user`, and only a guarded main request
     * sets it.
     *
     * THE THREE GATES RUN IN THIS ORDER, the same order all four earlier entry points use:
     * core version, whitelist, permission. The version guard comes first because a mismatched
     * core makes every answer below it meaningless; the whitelist comes before the permission
     * because "not released" and "not allowed" are different statements and the whitelist is
     * the outer one (ADR 0005).
     */
    public function filesAction(Request $request): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];

        if (!in_array(self::ENTITY_FILE, $manager->getEntities(), true)) {
            return new Response('', 404);
        }

        if (!$this->mayRead(self::ENTITY_FILE)) {
            return $this->accessDeniedPage(self::ENTITY_FILE);
        }

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('files.html.twig', array(
            'title'      => 'Dateien — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
        ));

        return new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));
    }
}
