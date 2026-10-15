<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\AdminManager;
use Symfony\Component\HttpFoundation\Response;

/**
 * The admin's own routes.
 *
 * NOT A SUBCLASS OF THE CORE'S `BaseController`, on purpose. That base class answers in the
 * API envelope (`data`, `errors`, `meta`) and brings the entity manager along; the admin
 * serves HTML to a browser and needs neither. What it does share is the constructor
 * signature — `CustomControllerProvider::connect()` builds every controller as
 * `new $controllerName($app)`, so that shape is fixed from the outside.
 *
 * WHAT THIS IS NOT YET: a user interface. The index page is the proof that the mount works
 * and nothing more — layout, login and the real views are stories 002-001 and 002-002.
 */
final class AdminController
{
    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The admin's entry page.
     *
     * The action name is given explicitly when the route is registered. It has to be: the
     * core derives a missing action from the route name, and this route's name is the empty
     * string — which is what puts the page on the mount path itself.
     */
    public function indexAction(): Response
    {
        $manager = $this->app[AdminManager::CONTAINER_KEY];

        $items = '';
        foreach ($manager->getEntities() as $entity) {
            $items .= '<li>'.htmlspecialchars($entity, ENT_QUOTES, 'UTF-8').'</li>';
        }

        $title = htmlspecialchars($manager->getTitle(), ENT_QUOTES, 'UTF-8');

        $html = '<!doctype html>'
            .'<html lang="de"><head><meta charset="utf-8"><title>'.$title.'</title></head>'
            .'<body><h1>'.$title.'</h1><ul>'.$items.'</ul></body></html>';

        return new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));
    }
}
