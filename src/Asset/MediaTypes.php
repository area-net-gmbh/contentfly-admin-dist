<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Asset;

/**
 * Which files of this package are deliverable, and as what media type.
 *
 * ONE LIST FOR BOTH DELIVERY PATHS. The route (`Controller\AssetController`) and the static
 * copy (`Command\PublishAssetsCommand`) have to agree on the set of files, or switching a
 * project from one to the other would change which assets exist. That invariant only holds
 * while there is a single list, so this is it.
 *
 * AN EXTENSION MAP, NOT CONTENT SNIFFING — the opposite of what the core's `FileController`
 * does, and deliberately so. The core serves what unknown clients uploaded, so it must not
 * trust the claimed type. These files ship inside this package, so the extension is the most
 * reliable thing about them; sniffing would read CSS and JavaScript as `text/plain`, and a
 * browser refuses an ES module under that type.
 *
 * An unlisted extension is not delivered at all. That keeps this list, and not the contents of
 * the directory, in charge of what leaves the package — the `README.md` next to the vendored
 * modules is a file nobody should be able to fetch.
 */
final class MediaTypes
{
    /** @var array<string,string> */
    private const TYPES = array(
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
     * The media type for a file, or `null` when it is not deliverable.
     */
    public static function forFile(string $path): ?string
    {
        $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));

        return self::TYPES[$extension] ?? null;
    }
}
