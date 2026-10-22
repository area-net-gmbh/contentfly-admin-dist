<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Auth\PermissionView;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The permission check that sits next to every whitelist check (ADR 0019).
 *
 * ONE QUESTION, FOUR PLACES. `in_array($name, $manager->getEntities())` is written identically in
 * `ListController`, `FormController`, `OptionsController` and `BulkController`, and a permission
 * belongs beside each of them. Hiding an entry in the menu is tidiness; this is the part that
 * closes the data. Whoever adds a fifth entry point adds both checks or neither — which is why
 * they read the same in all four.
 *
 * IT REFUSES BEFORE THE CORE IS ASKED. The core would refuse too — `Api::getList()` asks
 * `Permission::isReadable()` itself, and the package already catches that answer
 * (`View\FieldError`, `ListController::report()`). But that is a *reaction*: the request goes
 * out and the message is assembled from a failure. The rights that can be decided from the
 * schema are decided here, and the reactive path keeps what only the core can decide — whether
 * one particular record belongs to this editor under `OWN` or `GROUP` (ADR 0019).
 *
 * TWO FORMS OF REFUSAL, BY ROUTE AND NOT BY TASTE. A page route answers with a page: the editor
 * is signed in, the layout works, and a bare status code would show the harness error page
 * instead of the admin — the raw rejection ADR 0010 exists to avoid. A fragment or action route
 * (`options`, `bulk`) answers with the status alone, because a rendered page is not what its
 * caller is reading.
 *
 * NEVER `AccessDeniedHttpException`. `AdminManager::registerAuthBridge()` turns every one of
 * those under the admin's prefix into a redirect to `/login`, so raising one here would tell a
 * signed-in editor to sign in again.
 *
 * NOT 404 EITHER. The whitelist answers 404 because an entity the project did not release does
 * not exist for the admin. A withdrawn permission is a different statement, and a 404 for it
 * reads as a broken link — the editor would look for the fault in the address.
 */
trait GuardsPermissions
{
    private function mayRead(string $entity): bool
    {
        return $this->app[PermissionView::CONTAINER_KEY]->mayRead($entity);
    }

    private function mayWrite(string $entity): bool
    {
        return $this->app[PermissionView::CONTAINER_KEY]->mayWrite($entity);
    }

    private function mayDelete(string $entity): bool
    {
        return $this->app[PermissionView::CONTAINER_KEY]->mayDelete($entity);
    }

    /**
     * The refusal a page route gives: the admin's own layout, a plain sentence, status 403.
     *
     * IT KEEPS THE MENU. The editor has somewhere to go from here, and the menu now lists only
     * what they may read — so the page that says "not this one" also shows which ones instead.
     * That is the whole difference between a refusal and a dead end.
     *
     * The message names the entity and nothing else. It does not say who holds the right or
     * which group would, because the editor cannot act on either and the page is the wrong place
     * to describe the rights of others.
     */
    private function accessDeniedPage(string $entity): Response
    {
        $manager = $this->app[AdminManager::CONTAINER_KEY];

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('refused.html.twig', array(
            'title'      => 'Kein Zugriff — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'heading'    => 'Kein Zugriff',
            'message'    => 'Für "'.$entity.'" fehlt dir die Berechtigung. '
                .'Wende dich an eine Administratorin oder einen Administrator, '
                .'wenn du damit arbeiten musst.',
        ));

        return new Response($html, 403, array('Content-Type' => 'text/html; charset=UTF-8'));
    }

    /**
     * The refusal a fragment or action route gives: the status, no body.
     *
     * Its callers are the relation search box and the bulk actions. Both are read by JavaScript
     * or followed by a redirect, so a page would be parsed as data or thrown away.
     *
     * THE EMPTY BODY IS SAFE ON THE JSON ROUTE even though `options` answers its own 404 as
     * JSON: `public/controllers/search_controller.js:99` tests `response.ok` before it parses,
     * so this body is never read. One refusal form for both routes is worth more than matching
     * each route's content type for a body nobody looks at.
     */
    private static function accessDeniedBare(): Response
    {
        return new Response('', 403);
    }
}
