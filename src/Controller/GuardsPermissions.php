<?php

declare(strict_types=1);

namespace Areanet\ContentflyAdmin\Controller;

use Areanet\ContentflyAdmin\Auth\PermissionView;
use Areanet\PIM\Classes\Language;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * The permission check that sits next to every whitelist check (ADR 0019).
 *
 * ONE QUESTION, TEN PLACES. The whitelist check is written identically in `ListController`,
 * `FormController`, `OptionsController`, `BulkController`, `RowActionController` (story 005-004),
 * `FileController` (story 003-001), `FilePickerController` (story 003-004), `TreeController`
 * (story 004-001), `Page\AbstractAdminPage` (story 005-003) and `Page\AbstractRowAction`
 * (story 005-004), and a permission belongs beside each of them. Hiding an entry in the menu is
 * tidiness; this is the part that closes the data. Whoever adds an eleventh entry point adds
 * both checks or neither — which is why they read the same in all ten.
 *
 * The count has now been wrong twice, the same way both times. Before story 004-001 this text
 * said five and named five while the trait already had six users (`FilePickerController` was
 * never added); until story 007-002 it said seven and named seven while there were ten — the
 * three page/action entry points of extension stages 3a and 3b were never added. It is spelled
 * out in full above for that reason — a number without the names is a number nobody re-counts.
 * The project's checklist of the six permission surfaces is in `an_project/docs/dev-guide.md`.
 *
 * Five of them ask `in_array($name, …)` about an entity taken from the URL. `FileController` and
 * `FilePickerController` ask about a constant, because the file manager is a function rather
 * than a table and has no entity placeholder — the check is the same one, about a name it did
 * not get from the request. The three in `Page\` take the entity from the action's or page's
 * own `permission` declaration, which is parsed before it is asked (ADR 0028 §3).
 *
 * IT REFUSES BEFORE THE CORE IS ASKED. The core would refuse too — `Api::getList()` asks
 * `Permission::isReadable()` itself, and the package already catches that answer
 * (`View\FieldError`, `ListController::report()`). But that is a *reaction*: the request goes
 * out and the message is assembled from a failure. The rights that can be decided from the
 * schema are decided here, and the reactive path keeps what only the core can decide — whether
 * one particular record belongs to this editor under `OWN` or `GROUP` (ADR 0019).
 *
 * TWO FORMS OF REFUSAL, BY ROUTE AND NOT BY TASTE. A page route answers with a page: the editor
 * is signed in, the layout works, and a bare status code would show the harness error page
 * instead of the admin — the raw rejection ADR 0010 exists to avoid. A fragment or action route
 * (`options`, `bulk`) answers with the status alone, because a rendered page is not what its
 * caller is reading.
 *
 * NEVER `AccessDeniedHttpException`. `AdminManager::registerAuthBridge()` turns every one of
 * those under the admin's prefix into a redirect to `/login`, so raising one here would tell a
 * signed-in editor to sign in again.
 *
 * NOT 404 EITHER. The whitelist answers 404 because an entity the project did not release does
 * not exist for the admin. A withdrawn permission is a different statement, and a 404 for it
 * reads as a broken link — the editor would look for the fault in the address.
 */
trait GuardsPermissions
{
    use RendersRefusalPage;

    private function mayRead(string $entity): bool
    {
        return $this->app[PermissionView::CONTAINER_KEY]->mayRead($entity);
    }

    private function mayWrite(string $entity): bool
    {
        return $this->app[PermissionView::CONTAINER_KEY]->mayWrite($entity);
    }

    private function mayDelete(string $entity): bool
    {
        return $this->app[PermissionView::CONTAINER_KEY]->mayDelete($entity);
    }

    /**
     * May the editor change this entity's records in this language?
     *
     * `null` is the language of an entity that is not translatable, and the answer is then the
     * entity-level one — the whole language layer only applies where `settings.i18n` is set.
     */
    private function mayEditLanguage(string $entity, ?string $language): bool
    {
        return $this->app[PermissionView::CONTAINER_KEY]->mayEditLanguage($entity, $language);
    }

    /**
     * The language a translatable entity is being worked in, out of a candidate value.
     *
     * ONE FALLBACK RULE, SEVERAL SOURCES — which is why this takes a value and not a request.
     * The list and the form read `lang` from the *query*, because a page navigated to with GET
     * has no body; an action posted from a list reads it from the query that list carried along
     * in a hidden field. The source differs per route and the rule must not: an absent or
     * unconfigured value falls back to the first configured language, because a zero-
     * configuration admin has to work on *something* before anyone picks a language, and a
     * mistyped language in a URL is not worth an error page.
     *
     * `Language::fromRequest()` IS NOT THIS. It reads `lang` from the request body and refuses an
     * unconfigured value with a 400 — right for an API call that states its language, wrong for
     * a page whose language is part of where the editor happens to be.
     *
     * IT MATTERS THAT THIS IS ONE METHOD. A permission check that disagrees with the write it
     * guards is the failure mode ADR 0019 is organised against: the check would pass for one
     * language and the write land in another.
     */
    private static function editingLanguage(mixed $requested): ?string
    {
        $language = Language::normalise($requested);

        if ($language !== null && Language::isConfigured($language)) {
            return $language;
        }

        $configured = Language::configured();

        return $configured[0] ?? null;
    }

    /**
     * The language out of the query a list carried into this post.
     *
     * The bulk bar and every row-action button post the list's own query string in a hidden
     * `query` field (`templates/list.html.twig:170`), which is how a report finds its way back
     * to the list the editor came from. That field is also the only place the language of that
     * list survives the post — the action's own address carries none.
     */
    private static function carriedLanguage(Request $request): ?string
    {
        $carried = array();
        parse_str((string) $request->request->get('query', ''), $carried);

        return self::editingLanguage($carried['lang'] ?? null);
    }

    /**
     * The refusal a write gets when the editor may read but not change.
     *
     * IT IS A SEPARATE MESSAGE FROM `accessDeniedPage()` because the situations differ for the
     * editor. There, the page was never theirs; here they were just looking at it. The usual way
     * to arrive is a form that was open while the right was withdrawn, or one whose submit
     * button was never rendered — so the sentence says plainly that nothing was saved.
     */
    private function writeDeniedPage(string $entity, ?string $language): Response
    {
        $where = $language === null ? '' : ' in der Sprache "'.$language.'"';

        return $this->refusalPage(
            'Nicht gespeichert',
            'Für "'.$entity.'"'.$where.' fehlt dir das Schreibrecht — es wurde nichts gespeichert.'
        );
    }

    /**
     * The refusal a page route gives: the admin's own layout, a plain sentence, status 403.
     *
     * IT KEEPS THE MENU. The editor has somewhere to go from here, and the menu now lists only
     * what they may read — so the page that says "not this one" also shows which ones instead.
     * That is the whole difference between a refusal and a dead end.
     *
     * The message names the entity and nothing else. It does not say who holds the right or
     * which group would, because the editor cannot act on either and the page is the wrong place
     * to describe the rights of others.
     */
    private function accessDeniedPage(string $entity): Response
    {
        return $this->refusalPage(
            'Kein Zugriff',
            'Für "'.$entity.'" fehlt dir die Berechtigung. '
            .'Wende dich an eine Administratorin oder einen Administrator, '
            .'wenn du damit arbeiten musst.'
        );
    }

    /**
     * The refusal a fragment or action route gives: the status, no body.
     *
     * Its callers are the relation search box and the bulk actions. Both are read by JavaScript
     * or followed by a redirect, so a page would be parsed as data or thrown away.
     *
     * THE EMPTY BODY IS SAFE ON THE JSON ROUTE even though `options` answers its own 404 as
     * JSON: `public/controllers/search_controller.js:99` tests `response.ok` before it parses,
     * so this body is never read. One refusal form for both routes is worth more than matching
     * each route's content type for a body nobody looks at.
     */
    private static function accessDeniedBare(): Response
    {
        return new Response('', 403);
    }
}
