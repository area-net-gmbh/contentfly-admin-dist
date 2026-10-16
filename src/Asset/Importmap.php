<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Asset;

use Areanet\ContentflyAdmin\AdminManager;

/**
 * The import map that lets the browser resolve this package's ES modules.
 *
 * WHY THIS EXISTS AT ALL: the admin ships ES modules and no bundler. A browser cannot resolve
 * a bare specifier like `@hotwired/stimulus` on its own — an import map is the standard way to
 * tell it which URL that name means, and it is the piece that replaces an npm build here.
 *
 * NO npm, NO BUNDLER, NO `package.json` IN THE PACKAGE. That is the lesson from the AngularJS
 * panel of the previous generation: its build chain outlived the willingness to maintain it,
 * and when it stopped working, nobody could ship a one-line fix any more. Modules that the
 * browser loads directly have no such chain to rot.
 *
 * THE MAP IS A PHP CONSTANT AND NOT A JSON FILE. A JSON source would be read on every request
 * and edited in a second place whenever a vendored version changes, while only this class ever
 * looks at it. One array, one place.
 */
final class Importmap
{
    /**
     * Bare module specifier → path inside the package's asset directory.
     *
     * THE VERSION IS PART OF THE FILE NAME, deliberately. Two copies of Stimulus in one
     * browser session is a bug that presents as "the controller is registered but never
     * connects", and a name that carries its version makes a leftover copy visible in the
     * network tab instead of invisible behind an identical URL.
     *
     * The vendored files are single-file bundles with no relative imports of their own, so one
     * entry per library is all the browser needs to resolve the whole library.
     *
     * @var array<string,string>
     */
    private const MODULES = array(
        '@hotwired/stimulus' => 'vendor/stimulus-3.2.2.js',
        '@hotwired/turbo'    => 'vendor/turbo-8.0.23.js',
        'admin/hello'        => 'controllers/hello_controller.js',
    );

    /**
     * Renders the map as the `<script type="importmap">` element a page puts in its head.
     *
     * EVERY URL IS BUILT BY THE MANAGER, never written out here — the prefix is the project's
     * choice and the version is the package's, and `AdminManager::assetUrl()` is the single
     * place that knows how the two become an address.
     *
     * `JSON_HEX_TAG` IS THE REASON THIS IS SAFE INSIDE A SCRIPT ELEMENT. The prefix comes from
     * project configuration, and `<` and `>` escaped as `<` cannot end the element early
     * whatever a project puts there. `JSON_UNESCAPED_SLASHES` only keeps the URLs readable.
     */
    public static function scriptTag(AdminManager $manager): string
    {
        $imports = array();

        foreach (self::MODULES as $specifier => $path) {
            $imports[$specifier] = $manager->assetUrl($path);
        }

        $json = json_encode(
            array('imports' => $imports),
            JSON_HEX_TAG | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );

        return '<script type="importmap">'.$json.'</script>';
    }
}
