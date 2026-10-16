<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\Version;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves the package's own CSS and ES modules.
 *
 * WHY A ROUTE AND NOT A COPY INTO THE DOCUMENT ROOT: the assets live in `vendor/`, which the
 * project's `.htaccess` answers with 403 on purpose. The usual remedy is an install command
 * that copies them somewhere reachable — but a step somebody forgets produces an admin that
 * looks broken in the browser while reporting nothing. Serving them from here needs no step at
 * all. The optional static copy for production, and why it must sit *next to* the prefix and
 * never underneath it, is ADR 0007.
 *
 * SAME CONSTRUCTOR SHAPE AS `AdminController`, and for the same external reason:
 * `CustomControllerProvider::connect()` builds every controller as `new $controllerName($app)`.
 *
 * NOT GUARDED, NEITHER BY LOGIN NOR BY THE VERSION CHECK (ADR 0007). The login form of story
 * 002-001 needs its stylesheet before anyone is logged in, and the `VersionGuard`'s own refusal
 * page would otherwise be the one page in the admin that cannot be styled. The route exposes
 * nothing but the package's own static files, every one of which ships publicly in the dist
 * repository.
 */
final class AssetController
{
    /**
     * The one directory assets are served from. Nothing outside it is reachable.
     */
    private const ASSET_ROOT = __DIR__.'/../../public';

    /**
     * The media types this route serves, keyed by lowercase extension.
     *
     * AN EXTENSION MAP, NOT CONTENT SNIFFING — the opposite of what the core's `FileController`
     * does, and deliberately so. The core serves what unknown clients uploaded, so it must not
     * trust the claimed type. These files ship inside this package, so the extension is the
     * most reliable thing about them; sniffing would read CSS and JavaScript as `text/plain`
     * and a browser refuses an ES module under that type.
     *
     * An unlisted extension is not served. That keeps the list, not the directory, in charge of
     * what this route can hand out.
     *
     * @var array<string,string>
     */
    private const MEDIA_TYPES = array(
        'css'   => 'text/css; charset=UTF-8',
        'js'    => 'text/javascript; charset=UTF-8',
        'json'  => 'application/json',
        'map'   => 'application/json',
        'svg'   => 'image/svg+xml',
        'woff2' => 'font/woff2',
        'png'   => 'image/png',
        'jpg'   => 'image/jpeg',
        'ico'   => 'image/x-icon',
    );

    /**
     * A single path segment: starts alphanumeric, then word characters, dot or dash.
     *
     * THE FIRST CHARACTER IS THE WHOLE TRAVERSAL DEFENCE. Requiring it to be alphanumeric
     * rejects `..` and `.` outright, and with them every dotfile. A segment also cannot smuggle
     * a separator: `/` and `\` are outside the class — which matters, because the router
     * decodes `%2F` into the parameter *after* matching, so a placeholder can arrive carrying
     * a slash even though the route matched a single segment.
     */
    private const SEGMENT = '/^[A-Za-z0-9][A-Za-z0-9._-]*$/';

    /** @var \Areanet\PIM\Classes\Kernel\ApplicationInterface */
    private $app;

    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * Hands out one asset of this package.
     *
     * THREE ROUTES SHARE THIS ACTION, which is why `$dir` and `$sub` have defaults: a
     * placeholder in this core matches exactly one segment, and the only thing that could
     * widen it — `RouteEntry::assert()` — is unreachable from here, because
     * `CustomControllerProvider::get()` returns the provider and not the entry. Fixed depths
     * are therefore the way, and the core takes the same one in `FileControllerProvider`.
     * Arguments arrive by name, so the order of the parameters below is free.
     *
     * THE VERSION SEGMENT IS CHECKED, NOT DECORATION. It is what makes the URL safe to cache
     * forever: a mismatch is an address from another release and gets 404 rather than today's
     * bytes under yesterday's address.
     *
     * EVERY FAILURE IS A BARE 404. What does not exist, what carries an extension this route
     * does not serve, and what tried to walk out of the directory all look the same from
     * outside — the distinction would only tell a prober which guess came closer.
     */
    public function serveAction(Request $request, string $version, string $file, string $dir = '', string $sub = ''): Response
    {
        if ($version !== Version::VERSION) {
            return new Response('', 404);
        }

        $path = self::resolve(array($dir, $sub, $file));

        if ($path === null) {
            return new Response('', 404);
        }

        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        if (!isset(self::MEDIA_TYPES[$extension])) {
            return new Response('', 404);
        }

        $response = new BinaryFileResponse($path);
        $response->headers->set('Content-Type', self::MEDIA_TYPES[$extension]);
        $response->setAutoEtag();
        $response->setAutoLastModified();

        /*
         * IMMUTABLE IS ONLY HONEST BECAUSE THE URL CARRIES THE VERSION. The address changes with
         * every release, so the content behind it never does, and a browser may keep it for a
         * year without ever asking again.
         *
         * With debug on, the version does not change while the files do — the same promise would
         * poison a whole development session. So there it revalidates on every request; the ETag
         * keeps that cheap by answering 304 instead of resending the file.
         */
        if ($this->app['debug']) {
            $response->setPublic();
            $response->setMaxAge(0);
            $response->headers->addCacheControlDirective('no-cache');
        } else {
            $response->setPublic();
            $response->setMaxAge(31536000);
            $response->setImmutable();
        }

        $response->isNotModified($request);

        return $response;
    }

    /**
     * Turns the path segments into an absolute path inside the asset directory, or `null`.
     *
     * TWO INDEPENDENT CHECKS, because each one covers what the other cannot. The pattern stops
     * a segment from ever meaning "one level up"; `realpath()` then proves where the result
     * actually landed, which is the only thing that also catches a symlink pointing out of the
     * directory. Dropping either one would leave a hole the other does not close.
     *
     * @param array<int,string> $segments in path order, empty entries ignored
     */
    private static function resolve(array $segments): ?string
    {
        $parts = array();

        foreach ($segments as $segment) {
            if ($segment === '') {
                continue;
            }

            if (preg_match(self::SEGMENT, $segment) !== 1) {
                return null;
            }

            $parts[] = $segment;
        }

        if ($parts === array()) {
            return null;
        }

        $root = realpath(self::ASSET_ROOT);

        if ($root === false) {
            return null;
        }

        $path = realpath($root.'/'.implode('/', $parts));

        if ($path === false || !is_file($path)) {
            return null;
        }

        if (strpos($path, $root.DIRECTORY_SEPARATOR) !== 0) {
            return null;
        }

        return $path;
    }
}
