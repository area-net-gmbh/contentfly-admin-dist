<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin;

/**
 * The version of the admin package.
 *
 * The single source of truth for what is released: composer.json deliberately carries no
 * `version` field, the tag drives the release. To keep tag and package from drifting apart,
 * tools/ci/paket-veroeffentlichen.sh compares the release tag against this constant and
 * aborts when they differ.
 */
final class Version
{
    public const VERSION = '1.0.0';
}
