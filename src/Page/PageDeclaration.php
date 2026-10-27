<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Page;

/**
 * One registered project page, read off its attributes once and then fixed.
 *
 * IT EXISTS BECAUSE THREE PLACES ASK THE SAME QUESTIONS. The mount needs route and actions, the
 * dispatcher needs the class and the target method, the menu needs label, group and permission.
 * Passing the reflection around instead would mean reading the attributes again per request, and
 * passing arrays would mean three places that each know which keys are in them.
 *
 * REFLECTION HAPPENS ONCE, when the project registers the page — which is inside the constructor
 * of the `AdminManager`, so a malformed page fails where it was written rather than on the first
 * request to it.
 */
final class PageDeclaration
{
    /**
     * @param string                                              $route   Below the admin's prefix, with a leading slash
     * @param array<string,array{method:string,target:string}>    $actions Full route => HTTP method and page method
     */
    public function __construct(
        public readonly string $class,
        public readonly string $route,
        public readonly string $label,
        public readonly ?string $group,
        public readonly ?string $permission,
        public readonly array $actions,
    ) {
    }
}
