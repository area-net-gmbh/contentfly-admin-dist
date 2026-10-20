<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin;

use Areanet\ContentflyAdmin\Auth\AdminAuthenticator;
use Areanet\ContentflyAdmin\Auth\LoginScreen;
use Areanet\ContentflyAdmin\Controller\AdminController;
use Areanet\ContentflyAdmin\Command\PublishAssetsCommand;
use Areanet\ContentflyAdmin\Controller\AssetController;
use Areanet\ContentflyAdmin\Schema\SchemaClient;
use Areanet\ContentflyAdmin\Schema\ShapingListener;
use Areanet\ContentflyAdmin\Schema\VersionGuard;
use Areanet\ContentflyAdmin\View\MenuBuilder;
use Areanet\ContentflyAdmin\View\TemplateRenderer;
use Areanet\PIM\Classes\Kernel\ApplicationInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

/**
 * The admin package's entry point into a Contentfly project.
 *
 * A project registers it in `custom/app.php` and is done:
 *
 *     $app['adminManager'] = new \Areanet\ContentflyAdmin\AdminManager($app);
 *
 * WHY A MANAGER OF ITS OWN AND NOT A CORE PLUGIN: the core's plugin system builds the class
 * name from `Plugins\<Name>\<Class>Plugin` and resolves its paths into the project tree, not
 * into `vendor/`. A package installed by Composer cannot satisfy that, and the plugin API knows
 * nothing about routes or services anyway. Recorded with file and line in ADR 0001.
 *
 * ZERO CONFIGURATION IS A HARD RULE, not a convenience. The admin is meant for headless
 * Contentfly projects, and in those nobody adopts a UI that demands setup first. Every option
 * therefore has a default that produces a working admin, and that includes `entities`: left
 * empty it means *all* entities of the schema, never none.
 */
final class AdminManager
{
    /**
     * Where the manager puts itself so its own controllers can find it again.
     *
     * A project is free to name its container key — the example in the documentation says
     * `$app['adminManager']`, but nothing enforces it. The controllers must not depend on that
     * choice, and they cannot be handed the manager either: the core builds every controller
     * itself as `new $controllerName($app)`. Hence a fixed key of our own, namespaced so it
     * cannot collide with a project's.
     */
    public const CONTAINER_KEY = 'contentfly_admin.manager';

    /**
     * The options a project may pass, with the values that apply when it passes nothing.
     *
     * This array is also the whitelist — an unknown key is a typo, and a typo that is silently
     * ignored looks exactly like an option that does not work.
     */
    private const DEFAULTS = [
        'prefix'    => '/admin',
        'title'     => 'Contentfly Admin',
        'entities'  => [],
        'assetPath' => '',
    ];

    /**
     * Path segments the project's `.htaccess` answers with 403.
     *
     * The static asset copy has to land somewhere a client may actually fetch, and these are
     * the names where it could not. Checked when the option is set rather than when the command
     * runs, so a project learns about it at the one moment it is looking at the option.
     */
    private const BLOCKED_ASSET_SEGMENTS = ['bin', 'custom', 'lib', 'vendor', 'plugins', 'tools', 'tests', 'data'];

    /**
     * Path segments the core mounts itself in `bootstrap-web.php`.
     *
     * The admin's routes are collected before the core's, so an admin mounted on one of these
     * would swallow the API of the very project it administers — and it would do so silently,
     * because nothing in the core objects to a second mount on the same path.
     */
    private const RESERVED_PREFIXES = ['api', 'auth', 'file', 'system'];

    private ApplicationInterface $app;

    private string $prefix;

    private string $title;

    /** @var list<string> The configured whitelist; empty means "every entity of the schema". */
    private array $entities;

    /** URL path of the static asset copy; empty means "serve through the route". */
    private string $assetPath;

    /**
     * @param array<string,mixed> $options See self::DEFAULTS for the accepted keys.
     *
     * @throws \InvalidArgumentException on an unknown option or an unusable prefix
     */
    public function __construct(ApplicationInterface $app, array $options = [])
    {
        $unknown = array_diff(array_keys($options), array_keys(self::DEFAULTS));
        if ($unknown !== []) {
            throw new \InvalidArgumentException(sprintf(
                'Unknown option(s) for the AdminManager: %s. Known are: %s.',
                implode(', ', $unknown),
                implode(', ', array_keys(self::DEFAULTS))
            ));
        }

        $options = array_merge(self::DEFAULTS, $options);

        $this->app       = $app;
        $this->prefix    = self::validatePrefix($options['prefix']);
        $this->title     = self::validateTitle($options['title']);
        $this->entities  = self::validateEntities($options['entities']);
        $this->assetPath = self::validateAssetPath($options['assetPath'], $this->prefix);

        $this->registerServices();
        $this->mountRoutes();
        $this->registerAuthBridge();
        $this->registerLoginRedirect();
        $this->registerCommands();
        $this->registerSchemaListener();
    }

    /**
     * Puts the admin's services into the container.
     *
     * EVERY ONE OF THEM IS REGISTERED, NONE OF THEM IS READ. This runs while `custom/app.php`
     * does, which is before `bindRoutes()` and long before a request exists — and it runs in
     * every console run and install too. A closure counts as a lazy factory in
     * `Kernel\Container`, so the `SchemaClient` is built on first access and not here; nothing
     * in this method touches the schema, the database or the request.
     */
    private function registerServices(): void
    {
        $this->app[self::CONTAINER_KEY] = $this;

        $this->app[SchemaClient::CONTAINER_KEY] = static function ($app): SchemaClient {
            return new SchemaClient($app);
        };

        $this->app[VersionGuard::CONTAINER_KEY] = static function (): VersionGuard {
            return new VersionGuard();
        };

        $prefix = $this->prefix;

        $this->app[AdminAuthenticator::CONTAINER_KEY] = static function ($app) use ($prefix): AdminAuthenticator {
            return new AdminAuthenticator($app, $prefix);
        };

        $this->app[TemplateRenderer::CONTAINER_KEY] = static function (): TemplateRenderer {
            return new TemplateRenderer();
        };

        $this->app[LoginScreen::CONTAINER_KEY] = static function ($app): LoginScreen {
            return new LoginScreen(
                $app[self::CONTAINER_KEY],
                $app[TemplateRenderer::CONTAINER_KEY]
            );
        };

        $this->app[MenuBuilder::CONTAINER_KEY] = static function ($app): MenuBuilder {
            return new MenuBuilder($app[self::CONTAINER_KEY]);
        };
    }

    /**
     * Hangs the cookie-to-header translation in front of every request.
     *
     * THE ADMIN'S SESSION IS A COOKIE AND THE CORE DOES NOT READ COOKIES — it looks in five
     * places, none of them one (`Classes/Security/TokenSources.php`). Something has to carry the
     * token across, and this hook is it: `$app->before()` runs on `kernel.request` and for the
     * main request only, while the core's route guard runs later, on `kernel.controller`. The
     * header is therefore set before anything checks for it. ADR 0009.
     *
     * THE HOOK IS REGISTERED GLOBALLY AND ACTS NARROWLY. It has to be global — there is no
     * per-mount `before` in this core that runs early enough — so the limit to the admin's own
     * prefix lives in `AdminAuthenticator::applyTokenHeader()`, together with the reason it
     * matters. Registering it costs nothing before a request exists: `Application::on()` only
     * extends the `dispatcher` factory while the app is unbooted, so no container key is resolved
     * here, which is the same promise `registerServices()` makes.
     */
    private function registerAuthBridge(): void
    {
        $this->app->before(static function ($request, $app): void {
            $app[AdminAuthenticator::CONTAINER_KEY]->applyTokenHeader($request);
        });
    }

    /**
     * Turns the core's refusal of an unauthenticated admin request into the login mask.
     *
     * WHY AN ERROR HANDLER AND NOT THE `before` HOOK NEXT DOOR. The hook was the obvious place
     * and is the wrong one, for two reasons that only show up when you look at what is mounted
     * inside the prefix. First, the asset routes live on `$prefix.'/_asset'` — inside the admin's
     * own scope — so a hook that redirected every unauthenticated request in scope would redirect
     * the stylesheet and the ES modules of the very mask it was sending people to, and the mask
     * would arrive naked. Second, a cookie that merely *exists* says nothing: the token in it
     * expires, and `AdminAuthenticator::hasSession()` cannot tell a live session from a dead one
     * without asking the core. An expired session would sail past the hook straight into the raw
     * refusal. The exception, on the other hand, is thrown in exactly both cases and in no other,
     * because `login` and the asset routes are mounted `isSecure = false` and never produce it.
     *
     * THE EXCEPTION CLASS IS THE TEST, NOT THE STATUS CODE. `AccessDeniedHttpException` reports
     * HTTP **403**; the 401 the core means sits in the exception's *code* and is only read on its
     * JSON path. A handler keyed on status 401 would never fire — and would look like a handler
     * that works, because the raw refusal it failed to replace is what you saw before it existed.
     *
     * THE PRIORITY IS WHAT PUTS THIS IN FRONT. `Application::error()` defaults to `-8` and the
     * core registers its own handler on that default; the first handler to set a response wins.
     * Anything above `-8` therefore runs first, and returning `null` for everything that is not
     * an admin access refusal hands those back to the core's handler untouched — including the
     * debug page, which stays exactly as it was for every other error.
     */
    private function registerLoginRedirect(): void
    {
        $app    = $this->app;
        $prefix = $this->prefix;

        $this->app->error(static function (\Throwable $error, $request) use ($app, $prefix) {
            if (!$error instanceof AccessDeniedHttpException) {
                return null;
            }

            $path = $request->getPathInfo();

            if ($path !== $prefix && !str_starts_with($path, $prefix.'/')) {
                return null;
            }

            $response = new RedirectResponse($prefix.'/login');

            /*
             * A DEAD COOKIE IS CLEARED ON THE WAY OUT. It is the only thing that distinguishes
             * "never signed in" from "session ran out", and leaving it in place means the browser
             * keeps presenting a token the core has already rejected on every later request.
             */
            $authenticator = $app[AdminAuthenticator::CONTAINER_KEY];

            if ($authenticator->hasSession($request)) {
                return $authenticator->withoutSessionCookie($response, $request);
            }

            return $response;
        }, 0);
    }

    /**
     * Registers the admin's routes with the core's route manager.
     *
     * CALLED FROM THE CONSTRUCTOR, because that is the whole contract a project gets: it writes
     * one `new AdminManager($app)` into `custom/app.php` and is done. A separate `mount()` call
     * would be one more line every project has to remember, and forgetting it would produce an
     * admin that silently is not there.
     *
     * THE MOMENT IS THE POINT. `custom/app.php` is required in `bootstrap.php` immediately
     * before `$app['routeManager']->bindRoutes()`, so the routes are collected in time. Reading
     * `$app['routeManager']` here resolves that key — unavoidable, since mounting is the job,
     * and harmless, because the bootstrap reads it on the next line anyway.
     *
     * The route name is the empty string so the path stays the mount path itself: the core
     * prepends a slash to every route name, which is what makes `/admin/` — with the trailing
     * slash — the canonical address. `/admin` without it matches no route at all, and the
     * core's catch-all for CORS preflights then matches the path but not the method: the
     * router reports MethodNotAllowed and the answer is 405, never 404. That is the core's
     * documented behaviour for every unmatched path, not something the admin introduces
     * (`bootstrap-web.php`, the OPTIONS catch-all); with debug output switched on, as in the
     * harness, it surfaces as an error page with a stack trace instead.
     *
     * The action name is passed explicitly because the core would otherwise derive it from the
     * empty route name and look for a method called `Action`.
     *
     * THREE ROUTES ON ONE MOUNT, not three mounts. A mount binds one controller class and
     * `RouteManager` keys its providers by mount path, but the routes *inside* one provider are
     * keyed by their own name — so `login` and `logout` fit next to the index route and come out
     * as `/admin/login` and `/admin/logout`. Mounting them separately would have been the other
     * way to get a second controller class, at the price of their paths: a mount's own route is
     * the empty name, so `/admin/login` would have become `/admin/login/`, with the bare form
     * answering 405. The actions here stay thin on purpose — the work is in `AdminAuthenticator`
     * and `LoginScreen`, which is where ADR 0009 and ADR 0010 put it.
     *
     * `login` IS `match()` AND HAS TO BE. It answers a GET with the mask and a POST with the
     * result of submitting it, and those cannot be two registrations: keying by route name means
     * a `get('login', …)` written next to a `post('login', …)` would not add a route, it would
     * overwrite the one already there — silently, since nothing in the core objects. `match()` is
     * the core's own answer for this, a route without a method restriction, and the branch then
     * sits in `loginAction()`.
     *
     * THE SECOND ARGUMENT IS `isSecure`, and the three values are each deliberate. The index is
     * `true` as of story 002-001: until then `/admin/` was open to anyone who knew the path, and
     * the flag is what hangs the core's five-source token check in front of it. `login` is
     * `false` because a login route that demands a login cannot be reached. `logout` is `true`,
     * and that is not symmetry — `logoutAction` in the core acts on `auth.token`/`auth.user`,
     * which only the guard of a **main** request sets; from an unsecured route it would answer
     * 200 and delete nothing.
     */
    private function mountRoutes(): void
    {
        $this->app['routeManager']
            ->mount($this->prefix, AdminController::class)
            ->get('', true, 'indexAction')
            ->match('login', false, 'loginAction')
            ->get('logout', true, 'logoutAction');

        /*
         * THE ASSETS GET A MOUNT OF THEIR OWN, and it has to be that way: a mount binds exactly
         * one controller class, and `RouteManager` keys its providers by mount path — a second
         * `mount($this->prefix, …)` would not add anything, it would replace the admin.
         *
         * The one cost is that `CustomControllerProvider::connect()` registers the core's global
         * `before`/`after` middleware once more. That is not a new condition the admin
         * introduces: the core mounts four providers of its own in `bootstrap-web.php` and every
         * one of them does the same, so the middleware is already registered several times over.
         *
         * THREE ROUTES OF FIXED DEPTH instead of one catch-all, because a placeholder in this
         * core matches a single segment and nothing reachable from a package can widen it
         * (ADR 0007). Three depths cover `admin.css` and `controllers/hello_controller.js`; a
         * deeper tree needs a fourth line here, which is the trade-off that decision accepted.
         *
         * `false` is the `isSecure` flag — assets are served without a login on purpose, see
         * `AssetController`.
         */
        $this->app['routeManager']
            ->mount($this->prefix.'/_asset', AssetController::class)
            ->get('{version}/{file}', false, 'serveAction')
            ->get('{version}/{dir}/{file}', false, 'serveAction')
            ->get('{version}/{dir}/{sub}/{file}', false, 'serveAction');
    }

    /**
     * Makes the admin's console commands available.
     *
     * NO OPT-IN LINE IN THE PROJECT. A command a project has to register first is a command
     * nobody finds, and zero configuration is a hard rule here — so it is always *available*
     * while staying entirely optional to *run*.
     *
     * `addCommand()` goes through `$app->extend('dispatcher', …)`, and Pimple freezes a
     * definition the moment it has been read once. This is safe at exactly this point in the
     * boot: `custom/app.php` runs before `bindRoutes()`, and nothing reads `dispatcher` until a
     * controller provider's `connect()` does. Whoever moves this call later gets a
     * `FrozenServiceException`, which is the failure `000-000-0006` of the core already paid
     * for once.
     */
    private function registerCommands(): void
    {
        $this->app['consoleManager']->addCommand(new PublishAssetsCommand());
    }

    /**
     * Hooks the field shaping of stage 2 into the core's schema generation.
     *
     * NO OPT-IN LINE IN THE PROJECT, for the same reason the commands have none: zero
     * configuration is a hard rule, and an `#[Admin]` attribute that only takes effect after a
     * project registers something is an attribute that silently does nothing.
     *
     * `extend('dispatcher', …)` AND NOT `Application::on()`: `on()` is not part of
     * `ApplicationInterface`, which is what this class types `$app` as. `extend()` is both the
     * type-safe route and the one the core itself takes — `Manager\ConsoleManager::addCommand()`
     * registers its listener exactly this way.
     *
     * THE TIMING IS LOAD-BEARING. Pimple freezes a definition the moment it has been read once,
     * and `dispatcher` is a lazy factory. This is safe at exactly this point in the boot because
     * `custom/app.php` runs before `bindRoutes()` and nothing reads `dispatcher` until a
     * controller provider's `connect()` does. Whoever moves this call later gets a
     * `FrozenServiceException` — the same failure `registerCommands()` already documents.
     *
     * Registering reads nothing: no schema, no database, no request. The constructor also runs
     * in every console run and during installation.
     */
    private function registerSchemaListener(): void
    {
        $this->app->extend('dispatcher', static function ($dispatcher) {
            $dispatcher->addListener(ShapingListener::EVENT, new ShapingListener());

            return $dispatcher;
        });
    }

    public function getPrefix(): string
    {
        return $this->prefix;
    }

    /**
     * The URL one asset of this package is served under.
     *
     * THE ONE PLACE THAT KNOWS THE SHAPE OF AN ASSET URL. The prefix is the project's choice and
     * the version belongs to the package, so neither may be written out anywhere else: a second
     * place that builds this string is a place that will still say `/admin` after a project
     * picked something else.
     *
     * THE VERSION SEGMENT IS WHAT MAKES THE ADDRESS CACHEABLE FOREVER (ADR 0007). It comes from
     * `Version::VERSION` and is therefore no second thing to maintain — the release moves it.
     *
     * WITH `assetPath` SET IT POINTS AT THE STATIC COPY INSTEAD, and then no PHP is involved in
     * delivering the file at all — the project's `.htaccess` hands out an existing file before
     * it ever reaches the front controller. That is the whole mechanism behind the optional
     * production path; `Command\PublishAssetsCommand` puts the files there.
     *
     * @param string $path path relative to the package's asset directory, e.g. `admin.css`
     */
    public function assetUrl(string $path): string
    {
        $base = $this->assetPath !== ''
            ? $this->assetPath
            : $this->prefix.'/_asset';

        return $base.'/'.Version::VERSION.'/'.ltrim($path, '/');
    }

    /**
     * The URL path of the static asset copy, or an empty string when the route serves them.
     */
    public function getAssetPath(): string
    {
        return $this->assetPath;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    /**
     * The entities the admin administers, in the order the schema lists them.
     *
     * RESOLVED HERE AND NOT IN THE CONSTRUCTOR, and that is load-bearing. The manager is built
     * while `custom/app.php` runs, which is before `bindRoutes()` and long before a request is
     * dispatched. Reading the schema at that moment would open the database on every request,
     * console run and install — including the ones that have no database yet.
     *
     * THE SCHEMA COMES FROM THE `SchemaClient` AND NOWHERE ELSE. It is the admin's single reader;
     * going around it would build the schema a second time in the same request, and two readers
     * are how a menu and a form end up disagreeing. The client also owns the detail that `_hash`
     * sits in among the entity names.
     *
     * @return list<string>
     */
    public function getEntities(): array
    {
        if ($this->entities !== []) {
            return $this->entities;
        }

        return $this->app[SchemaClient::CONTAINER_KEY]->getEntityNames();
    }

    /**
     * Checks the URL path of the static asset copy.
     *
     * THE ONE RULE THAT MATTERS: IT MUST NOT LIE UNDER THE ADMIN PREFIX. The front controller
     * only runs for a path that is not an existing directory, so a copy inside
     * `<docroot><prefix>/` would make `<prefix>/` itself a real directory — and the admin's own
     * entry page would stop reaching PHP and answer 403 or a directory listing instead. The
     * optimisation would switch off exactly the page it is meant to speed up. That is the heart
     * of ADR 0007 and the reason this check exists rather than a comment.
     *
     * @param mixed $assetPath
     *
     * @throws \InvalidArgumentException on a path that cannot work
     */
    private static function validateAssetPath($assetPath, string $prefix): string
    {
        if ($assetPath === '' || $assetPath === null) {
            return '';
        }

        if (!is_string($assetPath)) {
            throw new \InvalidArgumentException('The option "assetPath" must be a string, for example "/admin-assets".');
        }

        if (!str_starts_with($assetPath, '/')) {
            throw new \InvalidArgumentException(sprintf('The asset path "%s" must start with a slash, for example "/admin-assets".', $assetPath));
        }

        if (str_ends_with($assetPath, '/')) {
            throw new \InvalidArgumentException(sprintf('The asset path "%s" must not end with a slash — the version segment is appended.', $assetPath));
        }

        if ($assetPath === $prefix || str_starts_with($assetPath, $prefix.'/')) {
            throw new \InvalidArgumentException(sprintf(
                'The asset path "%s" must not lie under the admin prefix "%s". A directory there '
                .'stops the prefix itself from reaching the front controller, and the admin\'s '
                .'entry page would no longer answer. Pick a path next to it, for example "%s-assets".',
                $assetPath,
                $prefix,
                rtrim($prefix, '/')
            ));
        }

        $firstSegment = strtolower(explode('/', ltrim($assetPath, '/'))[0]);

        if ($firstSegment === '' || str_starts_with($firstSegment, '.')) {
            throw new \InvalidArgumentException(sprintf('The asset path "%s" must name a directory and must not start with a dot.', $assetPath));
        }

        if (in_array($firstSegment, self::BLOCKED_ASSET_SEGMENTS, true)) {
            throw new \InvalidArgumentException(sprintf(
                'The asset path "%s" starts with "%s", which the project\'s .htaccess answers '
                .'with 403 — nothing copied there can be fetched. Reserved are: %s.',
                $assetPath,
                $firstSegment,
                implode(', ', self::BLOCKED_ASSET_SEGMENTS)
            ));
        }

        return $assetPath;
    }

    /**
     * @param mixed $prefix
     *
     * @throws \InvalidArgumentException
     */
    private static function validatePrefix($prefix): string
    {
        if (!is_string($prefix) || $prefix === '') {
            throw new \InvalidArgumentException('The option "prefix" must be a non-empty string, for example "/admin".');
        }

        if (!str_starts_with($prefix, '/')) {
            throw new \InvalidArgumentException(sprintf('The prefix "%s" must start with a slash, for example "/admin".', $prefix));
        }

        if (str_ends_with($prefix, '/')) {
            throw new \InvalidArgumentException(sprintf('The prefix "%s" must not end with a slash — the routes add it.', $prefix));
        }

        /*
         * A dot would survive into the controller key. The core builds
         * `<mountPath>.controller:<action>`, lowercases it, turns the colon into a dot and
         * splits the result to assemble its event names — `pim.controller.before.<part0>.<part2>`
         * in `BaseControllerProvider`. An extra dot shifts those parts, so the before and after
         * events fire under names nobody subscribed to. Nothing crashes; the hooks simply stop
         * arriving, which is the kind of defect one looks for everywhere except in the prefix.
         */
        if (str_contains($prefix, '.')) {
            throw new \InvalidArgumentException(sprintf('The prefix "%s" must not contain a dot — the core builds its controller key from it.', $prefix));
        }

        $firstSegment = strtolower(explode('/', ltrim($prefix, '/'))[0]);
        if (in_array($firstSegment, self::RESERVED_PREFIXES, true)) {
            throw new \InvalidArgumentException(sprintf(
                'The prefix "%s" collides with the core route "/%s". Reserved are: /%s.',
                $prefix,
                $firstSegment,
                implode(', /', self::RESERVED_PREFIXES)
            ));
        }

        return $prefix;
    }

    /**
     * @param mixed $title
     *
     * @throws \InvalidArgumentException
     */
    private static function validateTitle($title): string
    {
        if (!is_string($title) || trim($title) === '') {
            throw new \InvalidArgumentException('The option "title" must be a non-empty string.');
        }

        return $title;
    }

    /**
     * @param mixed $entities
     *
     * @return list<string>
     *
     * @throws \InvalidArgumentException
     */
    private static function validateEntities($entities): array
    {
        if (!is_array($entities)) {
            throw new \InvalidArgumentException('The option "entities" must be an array of entity names.');
        }

        foreach ($entities as $entity) {
            if (!is_string($entity) || $entity === '') {
                throw new \InvalidArgumentException('The option "entities" must contain non-empty entity names only.');
            }
        }

        return array_values($entities);
    }
}
