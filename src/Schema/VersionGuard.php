<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Schema;

use Composer\Semver\Semver;

/**
 * Refuses to serve the admin when this package does not fit the core it runs against.
 *
 * THE SUPPORT CASE THIS EXISTS FOR is a user interface that works to about 90 percent: a list
 * that renders, a form that saves four fields out of five, an error nobody can place. A clear
 * refusal naming both sides costs one look; the other costs an afternoon.
 *
 * IT GUARDS THE ADMIN, NOT THE PROJECT. The check runs when an admin route is dispatched, not
 * while the container is being built. The manager's constructor runs in *every* request of the
 * host project and in every console run — aborting there would take `/api/*` down with it, so a
 * project whose admin package is too old would lose its API over a UI it may not even use. That
 * contradicts the premise the package is built on: a project without an editor interface does not
 * notice that this package exists. The login screen is itself an admin route, so the check still
 * happens before anyone signs in, which was the actual requirement.
 *
 * WHAT IT COMPARES, AND WHAT IT CANNOT. It compares the core's **framework** version, the
 * constant `APP_VERSION`, against the constraint this package declares on `areanet/contentfly`.
 * That catches "package too old or too new for this core". It cannot catch a change to the
 * **schema format**: the core reports no schema version and no envelope version, so a core that
 * reshaped `properties` without raising its own version passes this check. Recorded as a gap
 * against the core, and the reason the refusal message says so out loud instead of implying a
 * compatibility it never established.
 *
 * ONE SOURCE FOR THE RANGE. The constraint is read from this package's own `composer.json` at
 * runtime, not copied into a constant. Two declarations of the same range are two things that
 * drift apart, and the one in `composer.json` cannot be dropped — Composer resolves against it.
 * Note what this adds on top of Composer: Composer checks the constraint when the package is
 * installed, against its own metadata. This checks it against the version the core actually
 * reports while running, which is a different fact and the one that drifts — a core swapped in
 * place, or loaded from outside Composer's control, moves `APP_VERSION` without touching a lock
 * file.
 */
final class VersionGuard
{
    public const CONTAINER_KEY = 'contentfly_admin.version_guard';

    /** The package whose version decides whether this admin fits. */
    private const CORE_PACKAGE = 'areanet/contentfly';

    /**
     * The core's framework version as it reports it at runtime, or `null` if it reports none.
     *
     * `null` IS A REAL CASE, NOT DEFENSIVE PADDING. The core reads its own version defensively
     * for the same reason (`Classes/Envelope.php`): a failure early in the start-up has to be
     * able to answer before `version.php` has been read. Whatever the cause, "no version" is
     * treated as a failure here and never as a pass — an unknown version is exactly the
     * situation this guard exists for.
     */
    public function getCoreVersion(): ?string
    {
        return defined('APP_VERSION') && is_string(APP_VERSION) && APP_VERSION !== ''
            ? APP_VERSION
            : null;
    }

    /**
     * The version range this package declares on the core.
     *
     * @throws CoreVersionMismatch if the package's own `composer.json` is unreadable or does not
     *                             require the core — a broken installation, reported rather than
     *                             guessed around
     */
    public function getRequiredRange(): string
    {
        $path = dirname(__DIR__, 2).'/composer.json';

        $raw = is_readable($path) ? file_get_contents($path) : false;
        if ($raw === false) {
            throw new CoreVersionMismatch(sprintf(
                'contentfly-admin cannot read its own composer.json at "%s", so it cannot tell '
                .'which core versions it supports. The installation is incomplete.',
                $path
            ));
        }

        $manifest = json_decode($raw, true);
        $range    = is_array($manifest) ? ($manifest['require'][self::CORE_PACKAGE] ?? null) : null;

        if (!is_string($range) || $range === '') {
            throw new CoreVersionMismatch(sprintf(
                'contentfly-admin finds no requirement on %s in its own composer.json, so it '
                .'cannot tell which core versions it supports. The installation is incomplete.',
                self::CORE_PACKAGE
            ));
        }

        return $range;
    }

    /**
     * Passes silently when this package fits the core, and throws otherwise.
     *
     * @throws CoreVersionMismatch
     */
    public function check(): void
    {
        $range   = $this->getRequiredRange();
        $version = $this->getCoreVersion();

        if ($version === null) {
            throw new CoreVersionMismatch(sprintf(
                'contentfly-admin cannot determine the version of the Contentfly core: the '
                .'constant APP_VERSION is not defined. Expected %s %s. Refusing rather than '
                .'guessing — an unknown version is the case this check exists for. Only the '
                .'framework version would have been compared anyway; the schema format is never '
                .'checked, because the core reports no schema version.',
                self::CORE_PACKAGE,
                $range
            ));
        }

        if (!Semver::satisfies($version, $range)) {
            throw new CoreVersionMismatch(sprintf(
                'contentfly-admin does not fit this Contentfly core. Found framework version %s, '
                .'this package requires %s %s. Raise or lower one of the two until they match. '
                .'Note what was NOT established: only the framework version was compared. The '
                .'schema format itself is not checked, because the core reports no schema '
                .'version — a matching version is therefore no promise that the schema is '
                .'unchanged.',
                $version,
                self::CORE_PACKAGE,
                $range
            ));
        }
    }
}
