<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\View\EntityView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The records of one `BaseTree` entity, as a tree.
 *
 * ITS OWN MOUNT UNDER `/<prefix>/tree`, the ninth — one mount binds one controller class, and
 * the segment under the prefix is ADR 0012, exactly as for the list. The address is
 * `/<prefix>/tree/<Entity>` with the entity as a hyphen segment (ADR 0011).
 *
 * ONE ENTITY IS EITHER A TREE OR A TABLE, AND THE MENU PICKS ONE. `MenuBuilder` links an entity
 * whose `settings.type` is `tree` here instead of to `/list`, and this page links back to the
 * list (ADR 0025). Both routes keep working for whoever types them — the switch is a
 * convenience, not a boundary, and the boundary is checked on both.
 *
 * THE PERMISSION IS CHECKED BEFORE THE CORE IS ASKED, and here that is not tidiness.
 * `Api::getTree2()` *raises* a `ContentflyException` for an entity the editor may not read, so
 * without the check a withdrawn permission would surface as a 500 instead of the 403 page ADR
 * 0019 §6 asks for. The file manager made that mistake once and the dev guide records it.
 *
 * AN ENTITY THAT IS NOT A TREE ANSWERS 404. This route is not a second address for an ordinary
 * entity: it would render an empty tree for one, which says "no records" where the truth is
 * "wrong view". 404 is the same answer the whitelist gives, and for the same kind of reason —
 * for the admin, a tree of a non-tree entity does not exist.
 */
final class TreeController
{
    use GuardsCoreVersion;
    use GuardsPermissions;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * One tree entity's records.
     *
     * The route is registered `isSecure = true`, so `auth.user` is set by the time the core is
     * asked — `Api::getTree2()` would otherwise dereference a null user inside
     * `Permission::isReadable()`.
     *
     * @param string $entity The entity's URL segment, e.g. `Harness-FeldtypBaum` (ADR 0011)
     */
    public function treeAction(Request $request, string $entity): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        $manager = $this->app[AdminManager::CONTAINER_KEY];
        $name    = MenuBuilder::urlToEntity($entity);

        /*
         * THE THREE GATES IN THE ORDER THE OTHER CONTROLLERS USE THEM: the core version first
         * (above), then the whitelist, then the permission. The tree check is a fourth and sits
         * last of the four on purpose — it answers a question about the entity, and an entity
         * the project did not release or the editor may not read should not be distinguishable
         * by its shape.
         */
        if (!in_array($name, $manager->getEntities(), true)) {
            return new Response('', 404);
        }

        if (!$this->mayRead($name)) {
            return $this->accessDeniedPage($name);
        }

        if (!$this->app[EntityView::CONTAINER_KEY]->isTree($name)) {
            return new Response('', 404);
        }

        $listUrl = $manager->getPrefix().'/list/'.MenuBuilder::entityToUrl($name);

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('tree.html.twig', array(
            'title'      => $name.' — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'entity'     => $name,
            'listUrl'    => $listUrl,
        ));

        return new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));
    }
}
