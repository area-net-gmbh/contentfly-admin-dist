<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin;

/**
 * The admin's one diagnostic trail: what failed against the core, and nothing else.
 *
 * IT EXISTS BECAUSE THERE WAS NOTHING. Before story 007-001 the package had no logger at all —
 * no PSR-3, no `error_log()`, no option — so a failed core call left no trace anywhere and the
 * only way to learn what the admin had asked for was to reproduce it. `psr/log` sits in the
 * harness's `vendor/`, not in this package's requirements, and story 007-001-0002 is explicitly
 * not the place to add a runtime dependency to a package built to install with none.
 *
 * IT IS A FAILURE LOG, NOT A TRACE. Nothing is written when a call succeeds. A log that also
 * records the normal case buries the one line somebody is looking for, and on a busy admin it
 * grows without anybody deciding that it should.
 *
 * IT CARRIES CODES, NEVER CONTENT. An envelope's `context.value` and `detail` are free text the
 * core assembles around the rejected value — `status — "gibtsnicht" is not one of the allowed
 * options` carries what an editor typed. Neither is ever written here, which costs the field
 * name (it exists only inside that same free text, gap L19) and buys a log that cannot leak what
 * somebody entered. Tokens never reach this class in the first place: it is handed an endpoint,
 * a status and an envelope, never a request or a payload.
 *
 * IT NEVER THROWS. A diagnostic that takes the application down with it when the log directory
 * is read-only is worse than no diagnostic — every write is guarded and a failure to log is
 * silent, deliberately.
 */
final class CoreLog
{
    public const CONTAINER_KEY = 'contentfly_admin.core_log';

    /** Where lines are appended, or `''` when logging is off. */
    private string $path;

    public function __construct(string $path)
    {
        $this->path = $path;
    }

    /** Whether anything is written at all — `false` unless the project set the `log` option. */
    public function isEnabled(): bool
    {
        return $this->path !== '';
    }

    /**
     * One sub-request against the core that did not come back `ok`.
     *
     * @param array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null} $envelope
     */
    public function failedCall(string $endpoint, int $status, array $envelope): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $code = is_array($envelope['error']) ? ($envelope['error']['code'] ?? null) : null;

        $this->write(sprintf(
            'core-call endpoint=%s status=%d readable=%s code=%s',
            $endpoint,
            $status,
            $envelope['readable'] ? 'yes' : 'no',
            is_string($code) && $code !== '' ? $code : '-'
        ));
    }

    /**
     * A throwable the admin refused on, named by where it was caught.
     *
     * `$where` IS A PLACE, NOT ONLY A PATH. The kernel handler passes the request path, because
     * that is what identifies an unexpected failure; `GuardsCoreVersion` passes the name of the
     * check that refused — `version-guard`, `admin-configuration`, `schema-read`. Both answer the
     * same question, "where did this come from", and keeping them in one line format means a
     * reader greps once instead of knowing which of two shapes to look for.
     *
     * THE MESSAGE IS KEPT HERE, unlike on the page that reports it. A driver exception names the
     * database connection, and that is exactly what somebody debugging the installation needs;
     * the reason it stays out of the response is that a page is readable by whoever can reach
     * the URL, and this file is not.
     */
    public function unexpected(string $where, \Throwable $error): void
    {
        if (!$this->isEnabled()) {
            return;
        }

        $this->write(sprintf(
            'refused where=%s exception=%s message=%s',
            $where,
            get_class($error),
            str_replace(array("\r", "\n"), ' ', $error->getMessage())
        ));
    }

    /**
     * One line, appended.
     *
     * `LOCK_EX` BECAUSE TWO REQUESTS CAN FAIL AT ONCE and a half-written line helps nobody. The
     * `@` is not laziness about the return value — `file_put_contents()` warns *and* returns
     * `false`, and the warning would surface on the page this class is trying to keep clean.
     */
    private function write(string $line): void
    {
        $entry = sprintf('[%s] %s%s', date('c'), $line, PHP_EOL);

        try {
            @file_put_contents($this->path, $entry, FILE_APPEND | LOCK_EX);
        } catch (\Throwable $ignored) {
            /* A log that breaks the request it is describing is worse than a missing line. */
        }
    }
}
