<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Schema;

/**
 * The core's one answer shape, `{data, errors, meta}`, read in one place.
 *
 * THE CONTRACT IS FROZEN AND UNIFORM, which is what makes a single reader worth having: the
 * envelope is built only in `Classes/Envelope.php` and no endpoint assembles it itself —
 * verified over the five writing call sites in `an_project/docs/schema-vertrag.md` §4.3. Every
 * JSON answer the admin will ever read — `insert`, `update`, `delete`, `list`, `single`, `tree`,
 * `count`, `/file/upload`, `/auth/*` — has this shape.
 *
 * IT LIVES BESIDE `SchemaClient` BECAUSE IT DOES THE SAME KIND OF WORK: reading what the core
 * answers. A directory of its own for one class is what ADR 0013 already decided against when
 * it rejected `src/Widget/`; turning the answer into something a person sees is `src/View/`.
 *
 * `readable` IS NOT PEDANTRY. With `APP_DEBUG` on, the core's error handler answers with its
 * debug page instead of the envelope (`bootstrap-web.php`), and the development harness runs
 * that way on purpose — so "there is no envelope here" is a state a caller meets in practice
 * and has to be able to tell apart from "the envelope says nothing went wrong".
 *
 * IT DOES NO TEXT PARSING. Guessing which field an error belongs to is display work and
 * deliberately not here; it has its own place, `View\FieldError`, as ADR 0014 requires.
 */
final class Envelope
{
    /**
     * Reads one response body.
     *
     * @param string|null $body   The raw response content
     * @param int         $status The HTTP status it came with
     *
     * @return array{ok: bool, readable: bool, data: mixed, error: array<string,mixed>|null}
     *         `error` is the first entry of `errors`, which is the only one the core ever
     *         fills (gap L20)
     */
    public static function read(?string $body, int $status): array
    {
        $decoded = json_decode((string) $body, true);
        $decoded = is_array($decoded) ? $decoded : null;

        $errors = $decoded === null ? null : ($decoded['errors'] ?? null);
        $first  = is_array($errors) && isset($errors[0]) && is_array($errors[0]) ? $errors[0] : null;

        return array(
            'ok'       => $status >= 200 && $status < 300 && $first === null,
            'readable' => $decoded !== null,
            'data'     => $decoded === null ? null : ($decoded['data'] ?? null),
            'error'    => $first,
        );
    }

    /**
     * The `id` the core reports for a record it just wrote, or `null`.
     *
     * @param mixed $data The envelope's `data`
     */
    public static function id($data): ?string
    {
        $id = is_array($data) ? ($data['id'] ?? null) : null;

        return is_scalar($id) && (string) $id !== '' ? (string) $id : null;
    }
}
