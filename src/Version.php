<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin;

/**
 * Version des Admin-Pakets.
 *
 * Einzige Quelle der Wahrheit für die ausgelieferte Version: die composer.json trägt
 * bewusst kein `version`-Feld, der Tag steuert die Veröffentlichung. Damit Tag und Paket
 * nicht auseinanderlaufen, vergleicht tools/ci/paket-veroeffentlichen.sh den Release-Tag
 * gegen diese Konstante und bricht bei Abweichung ab.
 */
final class Version
{
    public const VERSION = '0.1.0';
}
