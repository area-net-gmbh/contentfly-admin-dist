<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

use Areanet\PIM\Classes\Kernel\Paths;
use Twig\Environment;
use Twig\Loader\FilesystemLoader;

/**
 * The admin's template engine — the one place that knows how HTML is produced.
 *
 * WHY A TEMPLATE ENGINE AT ALL, AND WHY HERE. Until story 002-001-0003 every page in this
 * package was built by concatenating strings (`AdminController::indexAction()`), which was
 * honest while a page was a proof that a mount works. The login screen is the first page that
 * takes input and echoes part of it back, and string concatenation makes escaping a thing you
 * remember at each of a dozen places rather than a property of the output. Twig escapes by
 * default; that is the whole reason it is wired now and not later. ADR 0010.
 *
 * THE CORE HAS NO TWIG — not wired anywhere, not even in its `composer.json`. `twig/twig` is
 * this package's own dependency, so there is no environment to share and no container key to
 * collide with. The admin owns this completely.
 *
 * THE ENVIRONMENT IS BUILT ON FIRST RENDER, NOT IN THE CONSTRUCTOR. Two reasons, and the
 * second one is the hard one:
 *
 *  - `AdminManager` registers this as a closure, so the object itself already only appears when
 *    something asks for it. Deferring once more costs nothing and keeps the promise that
 *    nothing in this package touches the filesystem while `custom/app.php` is being read —
 *    which happens in *every* console run, `appcms:install` included.
 *  - `Paths::data()` throws as long as the entry point has not set the project directory. A
 *    constructor that asked for it would turn "the admin is installed" into "every console
 *    command explodes". `cacheDir()` therefore asks `Paths::isSet()` first and renders without
 *    a compile cache rather than failing, because a slow admin beats an admin that cannot
 *    answer at all.
 */
final class TemplateRenderer
{
    /** Where the renderer puts itself, namespaced like the manager's key. */
    public const CONTAINER_KEY = 'contentfly_admin.renderer';

    /**
     * The compile cache lives in the PROJECT's data directory, never in the package.
     *
     * The package is installed under `vendor/` and a deployment may well mount that read-only;
     * a cache directory inside it is a write that works on a developer's machine and fails in
     * production. `Paths::data().'/cache/<namespace>'` is the core's own convention for exactly
     * this (`bootstrap.php`, the cache pools for `login-throttle`, `query` and `metadata`), so
     * the admin's templates sit next to them instead of inventing a second location.
     */
    private const CACHE_NAMESPACE = 'contentfly-admin-templates';

    private string $templateDir;

    private ?Environment $twig = null;

    public function __construct(?string $templateDir = null)
    {
        $this->templateDir = $templateDir ?? dirname(__DIR__, 2).'/templates';
    }

    /**
     * Renders one template to a string.
     *
     * @param array<string,mixed> $context
     */
    public function render(string $template, array $context = array()): string
    {
        return $this->environment()->render($template, $context);
    }

    /**
     * `strict_variables` IS ON ON PURPOSE. The alternative silently prints nothing for a
     * misspelled variable, which in a login screen means a missing error message or a missing
     * CSRF field — both of which look like a working page. A loud failure is the cheaper one.
     *
     * `auto_reload` COSTS A `stat()` PER TEMPLATE AND SAVES A CLASS OF BUG that is very hard to
     * recognise: an edited template that keeps rendering its previous version. At this package's
     * number of templates the trade is not close.
     */
    private function environment(): Environment
    {
        if ($this->twig === null) {
            $this->twig = new Environment(
                new FilesystemLoader($this->templateDir),
                array(
                    'cache'            => $this->cacheDir(),
                    'auto_reload'      => true,
                    'strict_variables' => true,
                    'autoescape'       => 'html',
                )
            );
        }

        return $this->twig;
    }

    /**
     * The cache directory, or `false` for "compile on every request".
     *
     * Twig creates the directory itself on the first write, so there is nothing to make here.
     *
     * @return string|false
     */
    private function cacheDir()
    {
        if (!Paths::isSet()) {
            return false;
        }

        return Paths::data().'/cache/'.self::CACHE_NAMESPACE;
    }
}
