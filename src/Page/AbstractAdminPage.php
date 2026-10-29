<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Page;

use Areanet\ContentflyAdmin\AdminManager;
use Areanet\ContentflyAdmin\Asset\Importmap;
use Areanet\ContentflyAdmin\Attribute\AdminAction;
use Areanet\ContentflyAdmin\Attribute\AdminPage;
use Areanet\ContentflyAdmin\Auth\FormToken;
use Areanet\ContentflyAdmin\Auth\PermissionView;
use Areanet\ContentflyAdmin\Controller\CallsCore;
use Areanet\ContentflyAdmin\Controller\GuardsCoreVersion;
use Areanet\ContentflyAdmin\Controller\GuardsPermissions;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Areanet\PIM\Classes\Api;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The base class of every project page — the whole of stage 3a a project has to know about.
 *
 * WHAT A PAGE IS: one class with `#[AdminPage]`, one `index()` method, one Twig template. No
 * constructor, no layout assembly, no version check, no permission check, no session handling.
 * That is the point of the contract and the measure of whether it holds — concept section 9
 * writes the target shape out, and anything a project has to repeat per page is a defect here.
 *
 * A BASE CLASS, UNLIKE THE TWO GUARD TRAITS NEXT DOOR. `GuardsCoreVersion` says why it is a trait
 * and not a base class: `CustomControllerProvider::connect()` builds every controller as
 * `new $controllerName($app)`, so a base class would be a second place that has to honour that
 * signature for no gain. For a *project* page the gain is the whole feature — the signature is
 * honoured once, here, so that a project never writes a constructor at all. The two traits are
 * used rather than reimplemented, so the refusal pages stay the admin's own.
 *
 * THE PAGE METHODS TAKE NO ARGUMENTS, and `handle()` is why. The core calls one fixed entry point
 * per route; it runs the gates in the order every other page of the admin runs them, puts the
 * request and the data layer in place, and only then calls the author's method. Letting the core
 * call `index(Request $request)` directly was the alternative and it costs the contract its
 * shape: every page would open with the same two guard clauses, and a page that forgot one would
 * be a page without a permission check.
 *
 * READING IS IN-PROCESS, WRITING IS A SUB-REQUEST, and that split is not this class's invention —
 * ADR 0006 for the read, ADR 0014 for the write. `$this->api` is the core's own `Api`, so a page
 * uses the same methods as the rest of the package (`getList()`, `getSingle()`). `write()` exists
 * although `TreeController` and `FileController` deliberately keep their own copies of it: there
 * the payload shape is the caller's business and sharing would hide it, while here the *mechanics*
 * are exactly what a project must not have to rebuild. The payload stays the caller's.
 */
abstract class AbstractAdminPage
{
    use CallsCore;
    use GuardsCoreVersion;
    use GuardsPermissions;

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    protected $app;

    /** The request being answered. Set by `handle()`, never before. */
    protected Request $request;

    /** The core's data layer for reading, bound to this request's editor (ADR 0006). */
    protected Api $api;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * The page's own screen. Every page has one; the attribute's `route` is its address.
     */
    abstract public function index(): Response;

    /**
     * The one entry point the admin mounts, for the page route and every `#[AdminAction]` alike.
     *
     * THE GATE ORDER IS THE ADMIN'S, not a new one: core version first, then the permission —
     * the same sequence `ListController`, `FormController` and `TreeController` run, and for the
     * same reason. A version mismatch is a deployment fault and must be reported before anything
     * tries to render; a withdrawn right answers with the admin's own 403 page, which keeps the
     * menu so the editor has somewhere to go (`Controller\GuardsPermissions`).
     *
     * THE METHOD NAME IS CHECKED AGAINST THE PAGE'S OWN DECLARATIONS even though the caller
     * resolves it from the registry and never from the request. A dynamic call is one refactoring
     * away from being reachable with a name somebody else chose, and the check costs one
     * reflection on a class that is already loaded.
     *
     * @internal Called by the admin's page dispatcher. Not part of the project-facing contract.
     *
     * @throws \InvalidArgumentException
     */
    final public function handle(Request $request, string $method): Response
    {
        if (($refusal = $this->guard()) !== null) {
            return $refusal;
        }

        if (!$this->declares($method)) {
            throw new \InvalidArgumentException(sprintf(
                'The page "%s" does not declare "%s" as a route target; a page method needs #[AdminAction] or has to be index().',
                static::class,
                $method
            ));
        }

        $declared = self::declaration()->permission;

        if ($declared !== null) {
            $permission = PagePermission::parse($declared);

            if (!$permission->isGranted($this->app[PermissionView::CONTAINER_KEY])) {
                return $this->accessDeniedPage($permission->entity);
            }
        }

        /*
         * A STATE-CHANGING REQUEST CARRIES THE FORM TOKEN. Until 007-002-0004 an
         * `#[AdminAction(method: 'POST')]` route verified none, and ADR 0030 recorded exactly
         * that as a point still to be decided. Every entity route has verified it all along
         * (`BulkController`, `RowActionController`, `FormController`), so a project page was the
         * one submit path of this admin that another site could reach.
         *
         * THE ORDER IS PERMISSION, THEN TOKEN, as in `BulkController` and `RowActionController`:
         * who the editor is does not depend on which form they arrived from.
         *
         * ONLY FOR POST. A `GET` action is a second view of the page and changes nothing. An
         * `'ANY'` route is therefore checked on the requests that actually are POSTs and not on
         * its GET half — the attribute's method is what the route accepts, not what arrived.
         */
        if ($request->isMethod('POST') && !FormToken::verify($request)) {
            return $this->refusalPage(
                'Formular abgelaufen',
                'Das Formular war zu lange offen, oder es kam nicht von dieser Oberfläche. '
                .'Lade die Seite neu und schicke es noch einmal ab — gespeichert wurde nichts.'
            );
        }

        $this->request = $request;
        $this->api     = new Api($this->app, $request);

        return $this->{$method}();
    }

    /**
     * Renders one of the project's templates inside the admin's layout.
     *
     * THE FIVE LAYOUT VARIABLES ARE SET HERE so that a project never sees them. `layout.html.twig`
     * requires `title`, `stylesheet` and `importmap` and fails loudly on a missing one
     * (`strict_variables`); `menu` and `logoutUrl` are what make the navigation render at all. The
     * page's own context is merged **last**, so a page that wants a different title simply passes
     * one instead of being locked out of its own layout.
     *
     * THE TITLE IS THE LABEL PLUS THE ADMIN'S, which is what the entity pages do — the browser tab
     * of a second window has to be distinguishable without reading the page.
     *
     * The template name is the project's, resolved in the `@project` namespace under `widgetRoot`
     * (ADR 0028 §4) — `@project/admin/newsletter.html.twig`.
     *
     * @param array<string,mixed> $context
     */
    protected function render(string $template, array $context = array()): Response
    {
        $manager = $this->app[AdminManager::CONTAINER_KEY];

        /*
         * THE FORM TOKEN IS A SIXTH LAYOUT VARIABLE, for the reason the other five are set here:
         * a project should not have to know the mechanism to be protected by it. A page that
         * posts renders `<input type="hidden" name="{{ csrfField }}" value="{{ csrfToken }}">`
         * and is done; the name comes from `FormToken::FIELD` and is deliberately not written
         * out, because a hand-written one went wrong once already (ADR 0030).
         */
        $token = FormToken::issue($this->request);

        $html = $this->app[TemplateRenderer::CONTAINER_KEY]->render($template, array_merge(array(
            'title'      => self::declaration()->label.' — '.$manager->getTitle(),
            'stylesheet' => $manager->assetUrl('admin.css'),
            'importmap'  => Importmap::scriptTag($manager),
            'menu'       => $this->app[MenuBuilder::CONTAINER_KEY]->build(),
            'logoutUrl'  => $manager->getPrefix().'/logout',
            'csrfField'  => FormToken::FIELD,
            'csrfToken'  => $token,
        ), $context));

        $response = new Response($html, 200, array('Content-Type' => 'text/html; charset=UTF-8'));

        /*
         * THE COOKIE IS SET ONLY WHERE THE BROWSER DOES NOT HOLD IT YET. Reissuing on every
         * render would invalidate the form a second tab is showing (`FormToken::issue()`), and
         * without the cookie `verify()` would have nothing to compare the field against.
         */
        if (!FormToken::isIssued($this->request, $token)) {
            $response->headers->setCookie(
                FormToken::cookie($this->request, $token, $manager->getPrefix())
            );
        }

        return $response;
    }

    /**
     * One write against the core, as a sub-request, with its answer read as an envelope (ADR 0014).
     *
     * The server parameters of the main request are carried over, which is what lets the core check
     * its per-record permission against the guarded request's `auth.user` — the same reason
     * `TreeController::call()` carries them.
     *
     * @param array<string,mixed> $payload
     *
     * @return array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null}
     */
    protected function write(string $endpoint, array $payload): array
    {
        return $this->callCore($this->request, $endpoint, $payload);
    }

    /**
     * The page's own `#[AdminPage]`, or an exception naming the class that is missing it.
     *
     * @throws \InvalidArgumentException
     */
    private static function declaration(): AdminPage
    {
        $attributes = (new \ReflectionClass(static::class))->getAttributes(AdminPage::class);

        if ($attributes === array()) {
            throw new \InvalidArgumentException(sprintf(
                'The page "%s" extends AbstractAdminPage but carries no #[AdminPage] attribute.',
                static::class
            ));
        }

        return $attributes[0]->newInstance();
    }

    /** Is this method one the page offers as a route target? */
    private function declares(string $method): bool
    {
        if ($method === 'index') {
            return true;
        }

        if (!method_exists($this, $method)) {
            return false;
        }

        return (new \ReflectionMethod($this, $method))->getAttributes(AdminAction::class) !== array();
    }
}
