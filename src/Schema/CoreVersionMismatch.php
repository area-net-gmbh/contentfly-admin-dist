<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Schema;

/**
 * Thrown when this package does not fit the Contentfly core it is installed next to.
 *
 * A type of its own rather than a plain `RuntimeException`, because the admin's entry points
 * catch exactly this and nothing else: a mismatch is a deployment problem with a readable answer,
 * every other exception is a bug and has to keep travelling.
 */
final class CoreVersionMismatch extends \RuntimeException
{
}
