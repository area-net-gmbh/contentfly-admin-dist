<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\View;

/**
 * The one place that turns a core error entry into something a form can show.
 *
 * THIS CLASS EXISTS BECAUSE THE CORE CANNOT ANSWER THE QUESTION. An error entry is
 * `{code, detail, type, context}` and names **no field** (gap L19); the field's name appears
 * only inside the free text of `context.value`. On top of that `errors` is always filled with
 * exactly one entry (L20), and for a `ContentflyException` `detail` is the same message key as
 * `code`, so there is no human-readable sentence to show either (L21). All three are recorded
 * in `an_project/docs/schema-vertrag.md` §4.4.
 *
 * SO THE FIELD IS GUESSED, AT EXACTLY ONE PLACE, AND THAT IS THE POINT. ADR 0014 settled it as
 * best effort with a fallback: `property()` reads the `Entity::field` prefix the core's types
 * happen to write, and when it does not match, the caller shows the message above the form
 * instead. **This is deliberately brittle** — the text is an English display string, and the
 * moment somebody in the core rephrases it the prefix can disappear. Keeping the parse in one
 * method is what makes that a single known risk instead of a scattered one, and
 * `tools/harness-formular-pruefen.php` is what makes it fail loudly on a core update rather
 * than quietly at an editor.
 *
 * THE GUESS IS GATED ON THE FORM'S OWN FIELDS. A parsed name is only accepted when the form
 * actually offers that property, so a changed message, a differently shaped `context.value` or
 * an error about something else can never attach a message to a field that is not there — the
 * worst case stays "shown above the form".
 *
 * THE GERMAN TEXTS ARE THE PACKAGE'S OWN, and they have to be: `detail` is a message key, not a
 * sentence. An unknown key gets a general sentence and never the raw key — `contentfly_general_
 * invalid_params` on screen is not an error message, it is a leak of an internal name.
 */
final class FieldError
{
    /**
     * Message key → what an editor is told.
     *
     * ONLY THE KEYS A SAVE CAN ACTUALLY REACH. The core defines some three dozen; most belong to
     * installation, plugins, tokens or the sign-in path and will never come out of `insert` or
     * `update`. Listing them all would suggest a completeness that nothing checks — anything
     * missing lands on `FALLBACK`, which is a correct answer rather than a wrong one.
     */
    private const TEXTS = array(
        'contentfly_general_invalid_params'          => 'Die Eingabe ist in diesem Feld nicht zulässig.',
        'contentfly_general_missing_params'          => 'Diese Angabe wird benötigt.',
        'contentfly_general_invalid_date'            => 'Das Datum ist nicht lesbar.',
        'contentfly_general_invalid_entity'          => 'Diese Datenart kann so nicht gespeichert werden.',
        'contentfly_general_unknown_entity'          => 'Diese Datenart ist dem Grundsystem nicht bekannt.',
        'contentfly_general_unknown_property'        => 'Dieses Feld gibt es an dieser Datenart nicht.',
        'contentfly_general_property_not_exists'     => 'Dieses Feld gibt es an dieser Datenart nicht.',
        'contentfly_general_not_found'               => 'Der Datensatz wurde nicht gefunden. Vielleicht hat ihn jemand anderes gelöscht.',
        'contentfly_general_access_denied'           => 'Für diese Änderung fehlt die Berechtigung.',
        'contentfly_general_permission_denied'       => 'Für diese Änderung fehlt die Berechtigung.',
        'contentfly_general_ressource_already_exists' => 'Ein Datensatz mit diesen Angaben existiert bereits.',
        'contentfly_general_user_already_exists'     => 'Dieser Benutzername ist bereits vergeben.',
        'contentfly_general_invalid_password'        => 'Das Passwort erfüllt die Anforderungen nicht.',
        'contentfly_general_token_too_weak'          => 'Das Passwort ist zu schwach.',
        'contentfly_general_admin_not_deletable'     => 'Dieser Zugang lässt sich nicht löschen.',
        'contentfly_general_internal_error'          => 'Im Grundsystem ist ein Fehler aufgetreten. Die Änderung wurde nicht gespeichert.',
        'contentfly_i18n_missing_lang_param'         => 'Für diese Datenart muss eine Sprache angegeben werden.',
        'contentfly_i18n_permission_denied'          => 'Für diese Sprache fehlt die Berechtigung.',
        'contentfly_i18n_translations_exists'        => 'Für diese Sprache gibt es diesen Datensatz schon.',
        'contentfly_i18n_undefined_languages'        => 'Es ist keine Sprache konfiguriert.',
    );

    /** What an unknown key says. Never the key itself. */
    private const FALLBACK = 'Das Grundsystem hat die Eingabe abgelehnt.';

    /** What a rejection without any readable envelope says. */
    private const UNREADABLE = 'Das Speichern wurde abgelehnt, und die Antwort des Grundsystems war nicht auswertbar.';

    /**
     * The property one error belongs to, or `null` when it cannot be told.
     *
     * The core's types write `Entity::property — explanation` into `context.value`; the example
     * verified at the harness is
     * `Harness\Feldtyp::status — "gibtsnicht" is not one of the allowed options (…)`. Only the
     * part before `::` and the name after it are read, and the name has to be one the form
     * offers.
     *
     * @param array<string,mixed>|null $error      One entry of the envelope's `errors`
     * @param list<string>             $properties The properties this form actually shows
     */
    public static function property(?array $error, array $properties): ?string
    {
        if ($error === null) {
            return null;
        }

        $context = $error['context'] ?? null;
        $value   = is_array($context) ? ($context['value'] ?? null) : null;

        if (!is_string($value) || $value === '') {
            return null;
        }

        if (preg_match('/^[A-Za-z0-9_\\\\]+::([A-Za-z0-9_]+)/', $value, $match) !== 1) {
            return null;
        }

        return in_array($match[1], $properties, true) ? $match[1] : null;
    }

    /**
     * What the editor is told, in German. Never empty, never a message key.
     *
     * @param array<string,mixed>|null $error    One entry of the envelope's `errors`
     * @param bool                     $readable Whether an envelope could be read at all
     */
    public static function message(?array $error, bool $readable): string
    {
        if (!$readable) {
            return self::UNREADABLE;
        }

        if ($error === null) {
            return self::FALLBACK;
        }

        $code = $error['code'] ?? null;

        /*
         * `code: null` IS THE CORE SAYING "NOTHING TO BRANCH ON" — an unexpected 500. Its
         * `detail` has already been replaced by a fixed message and the original went to the
         * server log (`bootstrap-web.php`), so there is nothing here worth showing and
         * `detail` is not passed through on this path either.
         */
        if (!is_string($code) || $code === '') {
            return self::TEXTS['contentfly_general_internal_error'];
        }

        return self::TEXTS[$code] ?? self::FALLBACK;
    }
}
