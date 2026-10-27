<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Page;

/**
 * One registered project action on records, read off its attribute once and then fixed.
 *
 * IT EXISTS FOR THE SAME REASON `PageDeclaration` DOES — three places ask the same questions. The
 * mount needs to know whether there is anything to mount at all, the dispatcher needs the class,
 * and the list needs label, icon and `bulk` to decide what to draw where. Reading the attribute
 * per request instead would mean reflection on every list view.
 *
 * THE KEY IS NOT IN THE ATTRIBUTE. It is derived from the class name by `RowActionRegistry` and
 * kept here so that nothing downstream has to derive it a second time — two derivations of one
 * address are two chances to disagree about it.
 */
final class RowActionDeclaration
{
    public function __construct(
        public readonly string $class,
        public readonly string $entity,
        public readonly string $key,
        public readonly string $label,
        public readonly ?string $icon,
        public readonly bool $bulk,
        public readonly ?string $permission,
    ) {
    }
}
