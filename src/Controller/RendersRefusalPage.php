<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Symfony\Component\HttpFoundation\Response;

/**
 * The one place a refusal becomes a page in the admin's own layout.
 *
 * IT WAS LIFTED OUT OF `GuardsPermissions` WHEN THE SECOND REASON TO REFUSE ARRIVED. That trait
 * already carried the comment that this method exists "so the two messages above cannot drift
 * into two different-looking pages"; `ReportsCoreFailure` is a third message with the same need,
 * and copying the assembly would have been the mistake `GuardsCoreVersion` names outright — "a
 * page that exists twice is a page that will say two different things".
 *
 * THE STATUS IS A PARAMETER BECAUSE THE REASONS DIFFER, not the page. A refused right is a 403
 * and the editor could have been allowed; a core that failed mid-read is a 500 and nobody could
 * have. The same page says both, which is the point — the editor reads a sentence, not a code.
 *
 * IT STILL BUILDS THE MENU, as `refused.html.twig` describes: a refusal that also shows where
 * the editor *may* go is a refusal rather than a dead end. That is also its one precondition —
 * the menu reads the schema, so a caller whose failure is the schema itself must not come here.
 * `GuardsCoreVersion::refusal()` is the page for that case.
 */
trait RendersRefusalPage
{
    /**
     * @param int $status 403 for a right the editor does not hold, 500 for a failure of the
     *                    installation they cannot act on
     */
    private function refusalPage(string $heading, string $message, int $status = 403): Response
    {
        $manager = $this->app[AdminManager::CONTAINER_KEY];

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render('refused.html.twig', array(
            'title'      => $heading.' — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'heading'    => $heading,
            'message'    => $message,
        ));

        return new Response($html, $status, array('Content-Type' => 'text/html; charset=UTF-8'));
    }
}
